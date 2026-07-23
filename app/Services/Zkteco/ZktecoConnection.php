<?php

namespace App\Services\Zkteco;

/**
 * Talks directly to a ZKTeco biometric terminal using its own native
 * binary protocol over UDP on port 4370 - a from-scratch PHP
 * implementation with no dependency on the vendor's Windows SDK/DLL/COM
 * component anywhere in this class.
 *
 * Confirmed against real hardware (ZKTeco F18): despite this protocol
 * being commonly documented as running over TCP with an extra 8-byte
 * transport wrapper, this device (and many others) actually speaks it
 * directly over UDP datagrams, one packet per datagram, no wrapper.
 * Authentication uses the standard ZK "comm key" ticket algorithm.
 *
 * UDP has no delivery guarantee, so bulk transfers (attendance/user
 * lists) can arrive short if a datagram is dropped. Rather than retry
 * at the individual-datagram level, this client retries the *whole*
 * request a few times, which is simple and sufficient since reading
 * data from the device never consumes/deletes it.
 */
class ZktecoConnection
{
    private const MAX_BULK_ATTEMPTS = 5;

    /** @var resource|null */
    private $socket = null;

    private int $sessionId = 0;
    private int $replyId = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 4370,
        private readonly float $timeout = 8.0,
        private readonly int $commKey = 0,
    ) {
    }

    public function connect(): void
    {
        $this->open();

        $reply = $this->sendAndReceive(ZktecoProtocol::CMD_CONNECT);
        $this->sessionId = $reply['sessionId'];

        if ($reply['command'] === ZktecoProtocol::CMD_ACK_UNAUTH) {
            $ticket = self::makeCommKey($this->commKey, $this->sessionId);
            $authReply = $this->sendAndReceive(ZktecoProtocol::CMD_AUTH, $ticket);

            if (!in_array($authReply['command'], [ZktecoProtocol::CMD_ACK_OK, ZktecoProtocol::CMD_ACK_DATA], true)) {
                $this->close();
                throw new ZktecoException('Device rejected authentication (check the comm key).');
            }
        } elseif (!in_array($reply['command'], [ZktecoProtocol::CMD_ACK_OK, ZktecoProtocol::CMD_ACK_DATA], true)) {
            $this->close();
            throw new ZktecoException('Device refused the connection handshake.');
        }
    }

    public function disconnect(): void
    {
        if ($this->socket === null) {
            return;
        }

        try {
            $this->sendAndReceive(ZktecoProtocol::CMD_EXIT);
        } catch (ZktecoException) {
            // best-effort close
        }

        $this->close();
    }

    public function enableDevice(): void
    {
        $this->sendAndReceive(ZktecoProtocol::CMD_ENABLEDEVICE);
    }

    public function disableDevice(): void
    {
        $this->sendAndReceive(ZktecoProtocol::CMD_DISABLEDEVICE);
    }

    public function getTime(): ?\DateTimeImmutable
    {
        $reply = $this->sendAndReceive(ZktecoProtocol::CMD_GET_TIME);

        if (strlen($reply['data']) < 4) {
            return null;
        }

        return self::decodeTimestampPublic(unpack('V', substr($reply['data'], 0, 4))[1]);
    }

    /**
     * Deletes ALL attendance records on the device. Deliberately never
     * called anywhere in the application's sync flow - only exposed
     * here for completeness/manual use, since reading attendance never
     * needs it and it is irreversible on the device.
     */
    public function clearAttendanceLog(): void
    {
        $this->sendAndReceive(ZktecoProtocol::CMD_CLEAR_ATTLOG);
    }

    /** Raw bytes of every enrolled user record, reassembled from the device's chunked transfer. */
    public function readUsersRaw(): string
    {
        return $this->readBufferedDataWithRetry(ZktecoProtocol::CMD_USERTEMP_RRQ);
    }

    /** Raw bytes of every attendance/punch record, reassembled from the device's chunked transfer. */
    public function readAttendanceRaw(): string
    {
        return $this->readBufferedDataWithRetry(ZktecoProtocol::CMD_ATTLOG_RRQ);
    }

    /**
     * Enrolls (or updates) a user on the device itself. UID is the
     * device-internal slot number - reuse an existing one to update a
     * user, or pass an unused one to add a new enrollment. Writes only;
     * never called automatically by the app's sync flow.
     *
     * Per the documented "Set User Info" procedure (adrobinoga/zk-protocol),
     * the device must be disabled before CMD_USER_WRQ and re-enabled
     * after CMD_REFRESHDATA - writes were being silently rejected without
     * this exact sequence.
     */
    public function writeUser(int $uid, string $employeeCode, string $name, int $privilege = 0, int $cardNumber = 0): void
    {
        $this->disableDevice();

        try {
            $record = self::buildUserRecord($uid, $employeeCode, $name, $privilege, $cardNumber);
            $reply = $this->sendAndReceive(ZktecoProtocol::CMD_USER_WRQ, $record);

            if (!in_array($reply['command'], [ZktecoProtocol::CMD_ACK_OK, ZktecoProtocol::CMD_ACK_DATA], true)) {
                throw new ZktecoException("Device rejected the user write (command {$reply['command']}).");
            }

            $this->refreshData();
        } finally {
            $this->enableDevice();
        }
    }

    /** Removes a single enrolled user (by device-internal uid) from the device. Writes only; never called automatically. */
    public function deleteUser(int $uid): void
    {
        $this->disableDevice();

        try {
            $reply = $this->sendAndReceive(ZktecoProtocol::CMD_DELETE_USER, pack('v', $uid));

            if (!in_array($reply['command'], [ZktecoProtocol::CMD_ACK_OK, ZktecoProtocol::CMD_ACK_DATA], true)) {
                throw new ZktecoException("Device rejected the user delete (command {$reply['command']}).");
            }

            $this->refreshData();
        } finally {
            $this->enableDevice();
        }
    }

    /**
     * Some firmware acknowledges CMD_USER_WRQ/CMD_DELETE_USER immediately
     * but doesn't actually commit the change until told to reload its
     * data tables - confirmed necessary against real hardware (writes
     * reported success but didn't appear in a subsequent read without
     * this call).
     */
    private function refreshData(): void
    {
        $this->sendAndReceive(ZktecoProtocol::CMD_REFRESHDATA);
    }

    /**
     * Puts the device into live fingerprint-enrollment mode for a specific
     * user/finger - the same flow the vendor's own desktop software drives
     * (CMD_CANCELCAPTURE, CMD_STARTENROLL, CMD_STARTVERIFY, then listening
     * for the device's own realtime progress events), documented at
     * https://github.com/adrobinoga/zk-protocol. The device shows that
     * person's name on its own screen and waits for a finger; this call
     * blocks and reports progress via $onProgress until the device reports
     * a final result or $timeoutSeconds elapses.
     *
     * @param string $employeeCode the enrolled "ID Number" string for that user
     * @param int $fingerIndex 0-9, matching the device's own finger numbering
     * @param \Closure|null $onProgress optional callback(string $status) for live UI feedback
     * @return array{success:bool, finger_index?:int, error_code?:int}
     */
    public function enrollFingerprint(
        string $employeeCode,
        int $fingerIndex,
        ?\Closure $onProgress = null,
        int $timeoutSeconds = 30
    ): array {
        $report = function (string $status) use ($onProgress) {
            if ($onProgress !== null) {
                $onProgress($status);
            }
        };

        // Audible confirmation that the trigger actually reached the device -
        // otherwise there's no feedback that enrollment mode started.
        $this->beep();

        // Stop normal verification mode so the enroll sequence can take over.
        $this->sendAndReceive(ZktecoProtocol::CMD_CANCELCAPTURE);

        // Subscribe to the device's realtime event stream (finger placed, sample score, final result).
        $this->sendAndReceive(ZktecoProtocol::CMD_REG_EVENT, pack('V', ZktecoProtocol::EF_ALL));

        $reply = $this->sendAndReceive(
            ZktecoProtocol::CMD_STARTENROLL,
            self::buildEnrollRequest($employeeCode, $fingerIndex)
        );

        if ($reply['command'] !== ZktecoProtocol::CMD_ACK_OK) {
            throw new ZktecoException("Device refused to start enrollment (command {$reply['command']}).");
        }

        $report('waiting_for_finger');

        $reply = $this->sendAndReceive(ZktecoProtocol::CMD_STARTVERIFY);

        if ($reply['command'] !== ZktecoProtocol::CMD_ACK_OK) {
            throw new ZktecoException("Device refused to enter capture mode (command {$reply['command']}).");
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $samples = 0;

        // Poll frequently rather than inheriting the connection's longer
        // default timeout, so the overall deadline above is actually honored.
        stream_set_timeout($this->socket, 0, 500_000);

        while (microtime(true) < $deadline) {
            $datagram = @fread($this->socket, 8192);

            if ($datagram === '' || $datagram === false) {
                usleep(200_000);
                continue;
            }

            $packet = ZktecoProtocol::parseHeader($datagram);
            $this->ackRealtime($packet['sessionId']);

            if ($packet['command'] !== ZktecoProtocol::CMD_REG_EVENT) {
                continue; // not a realtime push (e.g. a stray reply) - ignore
            }

            $data = $packet['data'];
            $len = strlen($data);

            if ($len === 0) {
                $samples++;
                $report("finger_placed_sample_{$samples}");

                continue;
            }

            if ($len <= 4) {
                $score = unpack('V', str_pad($data, 4, "\0"))[1];
                $report("sample_score_{$score}");

                continue;
            }

            // Anything bigger is the final EF_ENROLLFINGER result: result(2) +
            // fp size(2) + user id(9) + finger index(1), per the documented layout.
            $result = unpack('v', substr($data, 0, 2))[1];

            if ($result === 0) {
                $report('enrolled');

                return ['success' => true, 'finger_index' => $fingerIndex];
            }

            $report("enroll_failed_code_{$result}");

            return ['success' => false, 'error_code' => $result];
        }

        throw new ZktecoException('Timed out waiting for the fingerprint to be captured on the device.');
    }

    /**
     * Puts the device into card-read mode for a specific user, waits for
     * the next card swipe, and writes that card number straight onto the
     * user's existing device record - mirrors enrollFingerprint()'s flow,
     * but there's no cross-vendor documented command/payload for reading a
     * raw card ID (unlike fingerprint enrollment), so the card number is a
     * best-effort read of the realtime event payload (first 4 bytes as a
     * little-endian uint32 - the same width/order the card field is stored
     * in on a user record). If the number that ends up written looks wrong
     * once tested against the real device, this is the parsing to revisit.
     *
     * @return array{success:bool, card_number?:int}
     */
    public function enrollCard(
        int $uid,
        string $employeeCode,
        string $name,
        int $privilege,
        ?\Closure $onProgress = null,
        int $timeoutSeconds = 20
    ): array {
        $report = function (string $status) use ($onProgress) {
            if ($onProgress !== null) {
                $onProgress($status);
            }
        };

        // Audible confirmation that the trigger actually reached the device.
        $this->beep();

        $this->sendAndReceive(ZktecoProtocol::CMD_CANCELCAPTURE);
        $this->sendAndReceive(ZktecoProtocol::CMD_REG_EVENT, pack('V', ZktecoProtocol::EF_ALL));

        // CMD_STARTENROLL/CMD_STARTVERIFY are what puts the device's own
        // screen into "place finger" mode for fingerprint enrollment - there's
        // no documented equivalent for cards, but the vendor SDK's StartEnroll
        // call uses slot numbers 0-9 for fingers and higher values for other
        // enrollment types (card is commonly slot 11). Try it on the chance
        // this firmware recognizes it and switches to a card-prompt screen;
        // if the device rejects it, that's fine - we still listen below
        // regardless, so a card can still be captured either way.
        try {
            $reply = $this->sendAndReceive(
                ZktecoProtocol::CMD_STARTENROLL,
                self::buildEnrollRequest($employeeCode, 11)
            );

            if ($reply['command'] === ZktecoProtocol::CMD_ACK_OK) {
                $this->sendAndReceive(ZktecoProtocol::CMD_STARTVERIFY);
            }
        } catch (ZktecoException) {
            // Device didn't accept the card-slot enroll attempt - proceed with a plain listen.
        }

        $report('waiting_for_card');

        $deadline = microtime(true) + $timeoutSeconds;
        stream_set_timeout($this->socket, 0, 500_000);

        while (microtime(true) < $deadline) {
            $datagram = @fread($this->socket, 8192);

            if ($datagram === '' || $datagram === false) {
                usleep(200_000);
                continue;
            }

            $packet = ZktecoProtocol::parseHeader($datagram);
            $this->ackRealtime($packet['sessionId']);

            if ($packet['command'] !== ZktecoProtocol::CMD_REG_EVENT || strlen($packet['data']) < 4) {
                continue; // not a realtime push with a usable payload - ignore
            }

            $cardNumber = unpack('V', substr($packet['data'], 0, 4))[1];
            $report('card_detected');

            $this->writeUser($uid, $employeeCode, $name, $privilege, $cardNumber);

            // A second beep confirms the whole operation completed, not just the trigger.
            $this->beep();
            $report('enrolled');

            return ['success' => true, 'card_number' => $cardNumber];
        }

        throw new ZktecoException('Timed out waiting for a card to be scanned on the device.');
    }

    /** Makes the device play its "Thank You" sound - used as an audible confirmation that a remote trigger reached it. */
    private function beep(): void
    {
        $this->sendAndReceive(ZktecoProtocol::CMD_TESTVOICE, pack('V', 0));
    }

    /** @return resource|null the raw socket, for stream_select()-based multi-device listening loops. */
    public function socket()
    {
        return $this->socket;
    }

    /**
     * Subscribes to the device's realtime event stream without entering
     * fingerprint/card capture mode - the device keeps behaving normally
     * (regular badge in/out) while also pushing matching events to us.
     * Same first two steps enrollFingerprint()/enrollCard() use.
     */
    public function beginRealtimeListening(int $eventMask = ZktecoProtocol::EF_ATTLOG): void
    {
        $this->sendAndReceive(ZktecoProtocol::CMD_CANCELCAPTURE);
        $this->sendAndReceive(ZktecoProtocol::CMD_REG_EVENT, pack('V', $eventMask));

        // Short poll interval so a caller's read attempt (after stream_select
        // says data is ready) never blocks longer than necessary.
        stream_set_timeout($this->socket, 0, 500_000);
    }

    /**
     * Reads and ACKs one pending realtime packet, if any is available right
     * now - callers drive their own poll/select loop across potentially
     * multiple device connections and only call this once a socket is
     * known to be readable. Returns the raw event payload, or null if
     * nothing arrived or it wasn't a usable push.
     */
    public function readPendingRealtimeRaw(): ?string
    {
        $datagram = @fread($this->socket, 8192);

        if ($datagram === '' || $datagram === false) {
            return null;
        }

        $packet = ZktecoProtocol::parseHeader($datagram);
        $this->ackRealtime($packet['sessionId']);

        if ($packet['command'] !== ZktecoProtocol::CMD_REG_EVENT || $packet['data'] === '') {
            return null;
        }

        return $packet['data'];
    }

    private function ackRealtime(int $sessionId): void
    {
        // Realtime pushes are acknowledged with a bare CMD_ACK_OK carrying
        // reply number 0 (not the usual incrementing counter), per protocol.
        $body = pack('v4', ZktecoProtocol::CMD_ACK_OK, 0, $sessionId, 0);
        $checksum = ZktecoProtocol::checksum($body);
        $ackPacket = pack('v4', ZktecoProtocol::CMD_ACK_OK, $checksum, $sessionId, 0);

        @fwrite($this->socket, $ackPacket);
    }

    private static function buildEnrollRequest(string $employeeCode, int $fingerIndex): string
    {
        // 26-byte structure per the documented protocol: user id (9 bytes) +
        // zero padding to offset 24 + finger index (1 byte) + fp flag (1
        // byte, 1 = valid fingerprint).
        $record = str_repeat("\0", 26);
        $record = substr_replace($record, str_pad(substr($employeeCode, 0, 9), 9, "\0"), 0, 9);
        $record = substr_replace($record, chr($fingerIndex & 0xFF), 24, 1);
        $record = substr_replace($record, chr(1), 25, 1);

        return $record;
    }

    public function getFreeSizes(): string
    {
        return $this->sendAndReceive(ZktecoProtocol::CMD_GET_FREE_SIZES)['data'];
    }

    public function getFirmwareVersion(): string
    {
        return trim($this->sendAndReceive(ZktecoProtocol::CMD_VERSION)['data']);
    }

    public function getSerialNumber(): string
    {
        return trim($this->readDeviceParam('~SerialNumber'));
    }

    public function getPlatform(): string
    {
        return trim($this->readDeviceParam('~Platform'));
    }

    private function readDeviceParam(string $key): string
    {
        $reply = $this->sendAndReceive(ZktecoProtocol::CMD_DEVICE, $key . "\0");
        $raw = $reply['data'];

        if (str_contains($raw, '=')) {
            [, $value] = explode('=', $raw, 2);

            return rtrim($value, "\0");
        }

        return rtrim($raw, "\0");
    }

    /**
     * A single bulk-transfer attempt can come back short if a UDP
     * datagram is lost along the way. Since reading never consumes
     * data on the device, it's always safe to just retry the whole
     * request from scratch.
     */
    private function readBufferedDataWithRetry(int $command, string $requestData = ''): string
    {
        $wasConnected = $this->socket !== null;
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_BULK_ATTEMPTS; $attempt++) {
            try {
                if (!$wasConnected || $attempt > 1) {
                    // A fresh connection/session per attempt avoids any stale in-flight datagrams
                    // from a previous partial attempt confusing the next read.
                    $this->close();
                    $this->connect();
                }

                return $this->readBufferedDataOnce($command, $requestData);
            } catch (ZktecoException $e) {
                $lastException = $e;
            }
        }

        throw $lastException ?? new ZktecoException('Bulk data transfer failed after retries.');
    }

    private function readBufferedDataOnce(int $command, string $requestData): string
    {
        $reply = $this->sendAndReceive($command, $requestData);

        if ($reply['command'] === ZktecoProtocol::CMD_ACK_OK && strlen($reply['data']) === 0) {
            return ''; // device reported success with nothing to send (e.g. no records)
        }

        if ($reply['command'] !== ZktecoProtocol::CMD_PREPARE_DATA && $reply['command'] !== ZktecoProtocol::CMD_DATA) {
            throw new ZktecoException("Unexpected reply (command {$reply['command']}) while requesting bulk data.");
        }

        if ($reply['command'] === ZktecoProtocol::CMD_DATA) {
            return $reply['data']; // small enough to have arrived in the very first packet
        }

        $size = unpack('V', substr($reply['data'], 0, 4))[1];
        $buffer = '';
        $emptyStreak = 0;

        // The socket's normal read timeout is several seconds (for the
        // request/reply handshake), but here we're reassembling many
        // back-to-back chunks - a brief gap between them is normal and
        // shouldn't cost a multi-second stall per gap. Poll with a short
        // timeout instead, and restore the original once reassembly ends.
        stream_set_timeout($this->socket, 0, 200_000);

        try {
            while (strlen($buffer) < $size && $emptyStreak < 40) {
                $datagram = @fread($this->socket, 8192);

                if ($datagram === '' || $datagram === false) {
                    $emptyStreak++;
                    continue;
                }

                $emptyStreak = 0;
                $buffer .= ZktecoProtocol::parseHeader($datagram)['data'];
            }
        } finally {
            stream_set_timeout($this->socket, (int) ceil($this->timeout));
        }

        if (strlen($buffer) < $size) {
            throw new ZktecoException("Incomplete transfer: got " . strlen($buffer) . " of {$size} bytes.");
        }

        return substr($buffer, 0, $size);
    }

    private function open(): void
    {
        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            "udp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout
        );

        if ($socket === false) {
            throw new ZktecoException("Unable to reach device at {$this->host}:{$this->port} ({$errstr})");
        }

        stream_set_timeout($socket, (int) ceil($this->timeout));
        $this->socket = $socket;
        $this->sessionId = 0;
        $this->replyId = 0;
    }

    private function sendAndReceive(int $command, string $data = ''): array
    {
        if ($this->socket === null) {
            throw new ZktecoException('Not connected to device.');
        }

        [$packet, $this->replyId] = ZktecoProtocol::buildPacket($command, $this->sessionId, $this->replyId, $data);

        $bytesWritten = fwrite($this->socket, $packet);
        if ($bytesWritten === false) {
            throw new ZktecoException('Failed to write to device socket.');
        }

        $datagram = @fread($this->socket, 8192);

        if ($datagram === '' || $datagram === false) {
            $meta = stream_get_meta_data($this->socket);
            if ($meta['timed_out'] ?? false) {
                throw new ZktecoException('Device connection timed out or closed unexpectedly.');
            }
            throw new ZktecoException('No response from device.');
        }

        return ZktecoProtocol::parseHeader($datagram);
    }

    /**
     * The standard ZK "comm key" scrambling algorithm, as documented by
     * long-standing open-source ZK client implementations. Confirmed
     * working against real hardware with commKey=0 (comm key disabled).
     */
    public static function makeCommKey(int $key, int $sessionId, int $ticks = 50): string
    {
        $k = 0;
        for ($i = 0; $i < 32; $i++) {
            $k = (($key & (1 << $i)) ? (($k << 1) | 1) : ($k << 1)) & 0xFFFFFFFF;
        }
        $k = ($k + $sessionId) & 0xFFFFFFFF;

        $b = array_values(unpack('C4', pack('V', $k)));
        $xored = [$b[0] ^ 0x5A, $b[1] ^ 0x4B, $b[2] ^ 0x53, $b[3] ^ 0x4F]; // 'Z','K','S','O'
        $h = array_values(unpack('v2', pack('C4', ...$xored)));
        $swapped = array_values(unpack('C4', pack('v2', $h[1], $h[0])));

        $B = $ticks & 0xFF;

        return pack('C4', $swapped[0] ^ $B, $swapped[1] ^ $B, $B, $swapped[3] ^ $B);
    }

    /**
     * Builds a 72-byte user record for CMD_USER_WRQ, matching the
     * layout ZktecoDevice::decodeUserRecords reads back
     * (uid:2, privilege:1, password:8, name:24, card:4, group+tz+pad:9, user_id:9, pad:15).
     */
    /**
     * Field layout per the documented ZK protocol (adrobinoga/zk-protocol,
     * "Set User Info"): uid(2)@0, permission(1)@2, password(8)@3,
     * name(24)@11, card(4)@35, group(1)@39, user-tz-flag(2)@40,
     * tz1/tz2/tz3(2 each)@42/44/46, user id(9)@48, zeros(15)@57.
     * Group must be >= 1 - the device rejects writes with group 0.
     */
    public static function buildUserRecord(int $uid, string $employeeCode, string $name, int $privilege, int $cardNumber, int $group = 1): string
    {
        $record = str_repeat("\0", 72);
        $record = substr_replace($record, pack('v', $uid), 0, 2);
        $record = substr_replace($record, chr($privilege & 0xFF), 2, 1);
        $record = substr_replace($record, str_pad(substr($name, 0, 24), 24, "\0"), 11, 24);
        $record = substr_replace($record, pack('V', $cardNumber), 35, 4);
        $record = substr_replace($record, chr($group & 0xFF), 39, 1);
        $record = substr_replace($record, str_pad(substr($employeeCode, 0, 9), 9, "\0"), 48, 9);

        return $record;
    }

    /**
     * The device stamps attendance records with its own local wall-clock
     * time (confirmed against real hardware: device clock reads local
     * time, not UTC). This app stores everything in UTC, so the decoded
     * value must be anchored to the device's real-world timezone here -
     * otherwise every timestamp is off by that offset once displayed
     * (the browser then applies the *viewer's* local offset on top of an
     * already-local value, doubling the error).
     *
     * Fixed offset rather than a DST-aware zone: confirmed this
     * deployment's timezone (UTC+3, e.g. Baghdad/Riyadh/Kuwait/Nairobi)
     * does not observe daylight saving.
     */
    private const DEVICE_TIMEZONE = '+03:00';

    /**
     * Returns null (never a fake "now") when the encoded value doesn't
     * decode to a real calendar date/time - a real symptom of corrupted or
     * misaligned record bytes (e.g. from a dropped UDP datagram), confirmed
     * against real hardware where this previously defaulted to the current
     * timestamp and caused dozens of garbage rows to be silently stored on
     * every re-read of the same corrupted data.
     */
    public static function decodeTimestampPublic(int $encoded): ?\DateTimeImmutable
    {
        $second = $encoded % 60;
        $encoded = intdiv($encoded, 60);
        $minute = $encoded % 60;
        $encoded = intdiv($encoded, 60);
        $hour = $encoded % 24;
        $encoded = intdiv($encoded, 24);
        $day = ($encoded % 31) + 1;
        $encoded = intdiv($encoded, 31);
        $month = ($encoded % 12) + 1;
        $encoded = intdiv($encoded, 12);
        $year = $encoded + 2000;

        $date = \DateTimeImmutable::createFromFormat(
            'Y-n-j-G-i-s',
            "{$year}-{$month}-{$day}-{$hour}-{$minute}-{$second}",
            new \DateTimeZone(self::DEVICE_TIMEZONE)
        );

        if ($date === false) {
            return null;
        }

        // createFromFormat silently rolls invalid dates forward (e.g. "2026-2-30"
        // becomes March 2nd) instead of failing - catch that by checking the
        // parsed components actually round-trip back to what we asked for.
        if ((int) $date->format('Y') !== $year || (int) $date->format('n') !== $month || (int) $date->format('j') !== $day) {
            return null;
        }

        return $date;
    }

    public static function encodeTimestamp(\DateTimeInterface $dateTime): int
    {
        return ((((int) $dateTime->format('Y') - 2000) * 12 + ((int) $dateTime->format('n') - 1)) * 31
                + ((int) $dateTime->format('j') - 1)) * 24 * 60 * 60
                + (int) $dateTime->format('H') * 3600
                + (int) $dateTime->format('i') * 60
                + (int) $dateTime->format('s');
    }

    private function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
