<?php

namespace App\Services\Zkteco;

/**
 * High-level facade over ZktecoConnection: exposes device operations as
 * plain PHP arrays/DateTime values instead of raw protocol bytes.
 */
class ZktecoDevice
{
    private ZktecoConnection $connection;

    public function __construct(
        public readonly string $host,
        public readonly int $port = 4370,
        public readonly int $commKey = 0,
        float $timeout = 8.0,
    ) {
        $this->connection = new ZktecoConnection($host, $port, $timeout, $commKey);
    }

    public static function fromModel(\App\Models\Device $device): self
    {
        return new self($device->ip_address, $device->port ?: 4370, (int) ($device->comm_key ?: 0));
    }

    public function connect(): void
    {
        $this->connection->connect();
    }

    public function disconnect(): void
    {
        $this->connection->disconnect();
    }

    /** Opens a connection, runs the callback, always disconnects afterwards. */
    public function run(\Closure $callback): mixed
    {
        $this->connect();

        try {
            return $callback($this);
        } finally {
            $this->disconnect();
        }
    }

    public function testConnection(): array
    {
        return $this->run(fn () => [
            'ok' => true,
            'device_time' => $this->getTime()?->format('Y-m-d H:i:s'),
            'firmware' => $this->getInfo()['firmware'] ?? null,
        ]);
    }

    public function getTime(): ?\DateTimeImmutable
    {
        return $this->connection->getTime();
    }

    public function getInfo(): array
    {
        $free = $this->connection->getFreeSizes();

        return [
            'firmware' => $this->safe(fn () => $this->connection->getFirmwareVersion()),
            'serial_number' => $this->safe(fn () => $this->connection->getSerialNumber()),
            'platform' => $this->safe(fn () => $this->connection->getPlatform()),
            'free_sizes_raw' => bin2hex($free),
        ];
    }

    public function enable(): void
    {
        $this->connection->enableDevice();
    }

    public function disable(): void
    {
        $this->connection->disableDevice();
    }

    public function clearAttendanceLog(): void
    {
        $this->connection->clearAttendanceLog();
    }

    /** Enrolls (or updates, if $uid already exists) a user directly on the device. */
    public function writeUser(int $uid, string $employeeCode, string $name, int $privilege = 0, int $cardNumber = 0): void
    {
        $this->connection->writeUser($uid, $employeeCode, $name, $privilege, $cardNumber);
    }

    /** Removes a single enrolled user (by device-internal uid) from the device. */
    public function deleteUser(int $uid): void
    {
        $this->connection->deleteUser($uid);
    }

    /**
     * Puts the device into live fingerprint-enrollment mode for a person,
     * showing their name on its screen and waiting for a finger - blocks
     * until the device reports a result or the timeout elapses.
     *
     * @return array{success:bool, finger_index?:int, error_code?:int}
     */
    public function enrollFingerprint(string $employeeCode, int $fingerIndex, ?\Closure $onProgress = null, int $timeoutSeconds = 30): array
    {
        return $this->connection->enrollFingerprint($employeeCode, $fingerIndex, $onProgress, $timeoutSeconds);
    }

    /**
     * Puts the device into card-read mode for a person and writes the next
     * card swiped straight onto their existing device record - blocks until
     * the device reports a result or the timeout elapses.
     *
     * @return array{success:bool, card_number?:int}
     */
    public function enrollCard(int $uid, string $employeeCode, string $name, int $privilege, ?\Closure $onProgress = null, int $timeoutSeconds = 20): array
    {
        return $this->connection->enrollCard($uid, $employeeCode, $name, $privilege, $onProgress, $timeoutSeconds);
    }

    /**
     * @return array<int, array{user_id:string, timestamp:\DateTimeImmutable, status:int, verify_method:int}>
     */
    public function getAttendanceLogs(): array
    {
        $raw = $this->connection->readAttendanceRaw();

        return self::decodeAttendanceRecords($raw);
    }

    /** Raw hex dump of the attendance table - useful for calibrating record parsing against a specific device model. */
    public function getAttendanceLogsRaw(): string
    {
        return bin2hex($this->connection->readAttendanceRaw());
    }

    /**
     * @return array<int, array{uid:int, user_id:string, name:string, privilege:int, card_number:int}>
     */
    public function getUsers(): array
    {
        $raw = $this->connection->readUsersRaw();

        return self::decodeUserRecords($raw);
    }

    public function getUsersRaw(): string
    {
        return bin2hex($this->connection->readUsersRaw());
    }

    /**
     * Attendance records are transferred as a 4-byte byte-length field
     * (matching the length of everything that follows) followed by
     * fixed-width records. Record width varies by firmware generation
     * (8 / 16 / 40 bytes are the ones documented across ZK client
     * implementations); this decodes the 40-byte layout used by the
     * common iClock/U-series family (confirmed against real hardware)
     * and falls back to returning the raw hex for anything it doesn't
     * recognise so it can be inspected and the parser adjusted further.
     */
    public static function decodeAttendanceRecords(string $raw): array
    {
        if (strlen($raw) < 4) {
            return [];
        }

        $body = substr($raw, 4); // leading 4 bytes are a byte-length field, not a record count
        $recordSize = self::detectRecordSize(strlen($body), [40, 16, 8]);
        $records = [];

        if ($recordSize <= 0) {
            return []; // truncated/misaligned buffer (e.g. dropped UDP datagrams) - nothing safe to decode
        }

        for ($offset = 0; $offset + $recordSize <= strlen($body); $offset += $recordSize) {
            $chunk = substr($body, $offset, $recordSize);
            $record = match ($recordSize) {
                40 => self::decode40ByteAttendance($chunk),
                16 => self::decode16ByteAttendance($chunk),
                8 => self::decode8ByteAttendance($chunk),
                default => null,
            };

            if ($record !== null) {
                $records[] = $record;
            } else {
                $records[] = ['raw' => bin2hex($chunk)];
            }
        }

        return $records;
    }

    private static function decode40ByteAttendance(string $chunk): ?array
    {
        if (strlen($chunk) < 40) {
            return null;
        }

        $uid = unpack('v', substr($chunk, 0, 2))[1];
        $userId = self::cStr(substr($chunk, 2, 24));
        // Confirmed against real hardware: byte[26] is the meaningful verify method
        // (1/3/4 etc, matching the CSL/HTTP export's verify_method column), byte[31]
        // is a constant placeholder (255) matching that same export's status column.
        $verifyMethod = ord($chunk[26]);
        $timestamp = unpack('V', substr($chunk, 27, 4))[1];
        $status = ord($chunk[31]);

        $decodedTimestamp = ZktecoConnection::decodeTimestampPublic($timestamp);
        if ($decodedTimestamp === null) {
            return null; // corrupted/misaligned bytes - let the caller keep it as raw hex instead
        }

        return [
            'uid' => $uid,
            'user_id' => $userId,
            'status' => $status,
            'verify_method' => $verifyMethod,
            'timestamp' => $decodedTimestamp,
        ];
    }

    private static function decode16ByteAttendance(string $chunk): ?array
    {
        if (strlen($chunk) < 16) {
            return null;
        }

        $userId = (string) unpack('V', substr($chunk, 0, 4))[1];
        $timestamp = unpack('V', substr($chunk, 4, 4))[1];
        $status = ord($chunk[8]);
        $punch = ord($chunk[9]);

        $decodedTimestamp = ZktecoConnection::decodeTimestampPublic($timestamp);
        if ($decodedTimestamp === null) {
            return null;
        }

        return [
            'uid' => null,
            'user_id' => $userId,
            'status' => $status,
            'verify_method' => $punch,
            'timestamp' => $decodedTimestamp,
        ];
    }

    private static function decode8ByteAttendance(string $chunk): ?array
    {
        if (strlen($chunk) < 8) {
            return null;
        }

        $userId = (string) unpack('v', substr($chunk, 0, 2))[1];
        $timestamp = unpack('V', substr($chunk, 2, 4))[1];
        $status = ord($chunk[6]);
        $punch = ord($chunk[7]);

        $decodedTimestamp = ZktecoConnection::decodeTimestampPublic($timestamp);
        if ($decodedTimestamp === null) {
            return null;
        }

        return [
            'uid' => null,
            'user_id' => $userId,
            'status' => $status,
            'verify_method' => $punch,
            'timestamp' => $decodedTimestamp,
        ];
    }

    /**
     * User records: 4-byte count header followed by fixed-width records,
     * classically 72 bytes on the iClock/U-series family
     * (uid:2, privilege:1, password:8, name:24, card:4, group:1, tz:2,
     * user_id:9, padding to 72).
     */
    public static function decodeUserRecords(string $raw): array
    {
        if (strlen($raw) < 4) {
            return [];
        }

        $body = substr($raw, 4); // leading 4 bytes are a byte-length field, not a record count
        $recordSize = self::detectRecordSize(strlen($body), [72]);
        $users = [];

        if ($recordSize <= 0) {
            return []; // truncated/misaligned buffer (e.g. dropped UDP datagrams) - nothing safe to decode
        }

        for ($offset = 0; $offset + $recordSize <= strlen($body); $offset += $recordSize) {
            $chunk = substr($body, $offset, $recordSize);

            if ($recordSize >= 72) {
                $uid = unpack('v', substr($chunk, 0, 2))[1];
                $privilege = ord($chunk[2]);
                $name = self::cStr(substr($chunk, 11, 24));
                $cardNumber = unpack('V', substr($chunk, 35, 4))[1];
                $userId = self::cStr(substr($chunk, 48, 9));

                $users[] = [
                    'uid' => $uid,
                    'user_id' => $userId !== '' ? $userId : (string) $uid,
                    'name' => $name,
                    'privilege' => $privilege,
                    'card_number' => $cardNumber,
                ];
            } else {
                $users[] = ['raw' => bin2hex($chunk)];
            }
        }

        return $users;
    }

    /**
     * Truncates a fixed-width C-style string at its first NUL byte.
     * Deliberately NOT a trailing rtrim(): devices can leave stale
     * non-NUL bytes after the terminator when a field is edited to a
     * shorter value without clearing the old memory, and rtrim() would
     * let that leftover garbage leak into the decoded value.
     */
    private static function cStr(string $raw): string
    {
        $nullPos = strpos($raw, "\0");

        return $nullPos === false ? $raw : substr($raw, 0, $nullPos);
    }

    /** Picks whichever candidate record width evenly divides the buffer length, preferring the first match. */
    private static function detectRecordSize(int $bodyLength, array $candidates): int
    {
        if ($bodyLength <= 0) {
            return 0;
        }

        foreach ($candidates as $candidate) {
            if ($bodyLength % $candidate === 0) {
                return $candidate;
            }
        }

        return 0;
    }

    private function safe(\Closure $fn): ?string
    {
        try {
            return $fn();
        } catch (ZktecoException) {
            return null;
        }
    }
}
