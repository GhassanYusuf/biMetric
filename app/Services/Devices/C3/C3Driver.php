<?php

namespace App\Services\Devices\C3;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Support\DeviceProgress;
use App\Support\Timezone;
use Illuminate\Support\Facades\Log;
use Promoseven\Biometric\C3\Capability\C3Capabilities;
use Promoseven\Biometric\C3\Client\Panel;
use Promoseven\Biometric\C3\Service\Personnel;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Capability\CapabilitySet;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Exception\DeviceException;
use Promoseven\Biometric\Exception\UnsupportedCommandException;
use Promoseven\Biometric\Exception\VerificationException;
use Promoseven\Biometric\Value\DeviceConfig;
use Promoseven\Biometric\Value\DeviceUser;
use Promoseven\Biometric\Value\Progress;

/**
 * The third driver: ZKTeco C3 / PullSDK access-control panels.
 *
 * These are the inBIO and C3 ranges — and, as this project learned twice over, at
 * least one device sold as a standalone fingerprint terminal (the TF1700). A model
 * name does not tell you the protocol family; only reading the device does. Both
 * panels here spent weeks recorded as "pings but does not answer" while being
 * spoken to in the wrong protocol.
 *
 * WHY A NEW DRIVER AND NOT A BRANCH IN ZktecoDriver: C3 shares a vendor and a port
 * number with the standalone protocol and nothing else — different transport,
 * frame, checksum and session model. Rules 8 and 17 both say a second
 * vendor-shaped protocol is another implementation of the contract, never a
 * conditional inside an existing one.
 *
 * WHAT THIS DRIVER SUPPORTS
 *   - ping           GET_PARAM identity round-trip
 *   - info           serial, firmware, MAC, door and reader counts
 *   - attendance     the full `transaction` table, staged and CRC-verified
 *   - roster/fingers `user` and `templatev10` reads
 *   - people         add / rename / card / delete and fingerprint push
 *                    (library C3\Service\Personnel, 2026-09-24), each gated by its
 *                    capability and promoted per panel only after a hardware run
 *   - ID moves       Panel::moveUser (user.renumber)
 *
 * WHAT IT DELIBERATELY CANNOT DO
 *   - run()/open()   there is no standalone protocol session to hand out.
 *   - enrollment     the panels have no remote-capture command (PullSDK documents
 *                    none; ZKAccess enrolls on a USB reader or a standalone terminal).
 *                    A finger is enrolled on an F18 and pushed here with
 *                    uploadTemplates().
 *   - door relay     CONTROL_DEVICE is unbuilt: not something to discover by accident
 *                    on a live entrance (rule 16).
 *
 * KNOWN DEBT, same as CslHttpDriver: Capability, CapabilitySet and DeviceException
 * are the app's shared vendor-neutral vocabulary but live in the protocol package.
 * That vocabulary wants its own package. This is now the THIRD driver importing it
 * that way, which is the point at which rule 8 said it should move.
 */
class C3Driver implements BiometricDeviceDriver
{
    private const SUPPORTED = [Device::PROTOCOL_C3];

    /**
     * How often a transfer callback is allowed to fire, in bytes.
     *
     * A 1.7 MB table arrives in 337 chunks and the callback writes to the progress
     * store, so reporting every chunk would mean 337 writes for one read. The
     * callback runs inside the transfer loop and must stay cheap (see the contract).
     */
    private const PROGRESS_EVERY_BYTES = 65536;

    public function supports(string $protocol): bool
    {
        return in_array($protocol, self::SUPPORTED, true);
    }

    /**
     * There is no standalone session to hand out, and returning something that
     * merely looks like one would let ZKTeco-shaped code run against a panel that
     * cannot serve it.
     */
    public function run(Device $device, \Closure $callback): mixed
    {
        throw new UnsupportedCommandException(
            "Device {$device->name} speaks the C3/PullSDK panel protocol, which shares no session type "
            .'with the ZKTeco standalone protocol. Use the driver methods directly.',
        );
    }

    public function open(Device $device): ProtocolDevice
    {
        throw new UnsupportedCommandException(
            "Device {$device->name} is a C3/PullSDK panel and cannot hold a standalone protocol session open. "
            .'Realtime listening is not built for this family.',
        );
    }

    /**
     * Reachability is proven by reading identity, not by opening a socket.
     *
     * A TCP connect succeeding proves nothing here: that is exactly the state the
     * TF1700 sat in for weeks while being called dead. The panel has to answer a
     * command before we will call it alive.
     */
    public function ping(Device $device): array
    {
        $startedAt = microtime(true);

        $info = $this->attempt($device, fn (Panel $panel) => $panel->info());

        // The clock: GET_PARAM "DateTime" (2026-10-11). Best effort — a panel that
        // will not report it is still alive. Null means "not read", never midnight.
        try {
            $clock = $this->attempt($device, fn (Panel $panel) => $panel->readClock(Timezone::forDevice($device)));
        } catch (\Throwable) {
            $clock = null;
        }

        return [
            'alive' => true,
            'device_time' => $clock,
            'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'note' => trim(($info['~DeviceName'] ?? 'C3 panel').' '.($info['FirmVer'] ?? '')),
        ];
    }

    /** @return array<string,mixed> */
    public function info(Device $device): array
    {
        $info = $this->attempt($device, fn (Panel $panel) => $panel->info());

        // Everything unread is null rather than omitted, so a caller can tell
        // "this panel does not report it" from "we did not ask".
        return [
            'protocol' => Device::PROTOCOL_C3,
            'firmware' => $info['FirmVer'] ?? null,
            'serial' => $info['~SerialNumber'] ?? null,
            'platform' => null,
            'device_name' => $info['~DeviceName'] ?? null,
            // `mac`, not `mac_address`: this is the key ZktecoDriver already
            // returns, and DeviceHealthService::identify() reads it. A second
            // spelling would work everywhere except the one screen that matters.
            'mac' => $info['MAC'] ?? null,
            'door_count' => isset($info['LockCount']) ? (int) $info['LockCount'] : null,
            'reader_count' => isset($info['ReaderCount']) ? (int) $info['ReaderCount'] : null,
            'user_count' => isset($info['MaxUserCount']) ? (int) $info['MaxUserCount'] : null,
            'attendance_count' => null,
        ];
    }

    /**
     * Reads the whole `transaction` table.
     *
     * PROTOCOL LIMIT, and it matters operationally: there is no cursor and no
     * server-side filter we have confirmed, so this reads EVERYTHING the panel
     * holds every time — 1.7 MB and 87,756 rows on the inBIO460. The watermark and
     * dedupe live in AttendanceSyncService, which is where they already live for
     * the CSL driver's overlapping date windows. Do not add a filter here on a
     * guess: an unproven Filter clause that the panel silently ignores would look
     * exactly like one that works.
     *
     * The read is CRC-verified by the protocol itself. A checksum mismatch throws
     * rather than returning a short table, so a partial read can never be mistaken
     * for a complete one.
     */
    public function readAttendance(Device $device, ?callable $onProgress = null): array
    {
        $lastReported = 0;

        $relay = $onProgress === null ? null : function (Progress $progress) use ($onProgress, &$lastReported): void {
            if ($progress->bytesRead - $lastReported < self::PROGRESS_EVERY_BYTES
                && $progress->bytesRead < $progress->bytesTotal) {
                return;
            }

            $lastReported = $progress->bytesRead;

            // BYTES, not records: the panel declares a staged byte count and never
            // a row count, so converting would mean inventing a record width. The
            // UI renders bytes honestly rather than guessing at "N of M punches".
            $onProgress(new DeviceProgress(
                $progress->operation,
                $progress->bytesRead,
                $progress->bytesTotal,
                DeviceProgress::UNIT_BYTES,
                $progress->attempt,
            ));
        };

        return $this->attempt($device, fn (Panel $panel) => $panel->readAttendance($relay));
    }

    /**
     * The panel's roster, from its `user` table.
     *
     * $withFingerprints is accepted and cannot be honoured: templates live in a
     * separate `templatev10` table this library does not read yet, so every
     * DeviceUser comes back with null finger slots. Null means "not read" and the
     * UI must keep it distinct from "none enrolled" (rule 10) — reporting zero
     * fingerprints for somebody who is enrolled is the specific lie this project
     * has a rule about.
     *
     * @return list<DeviceUser>
     */
    public function readUsers(Device $device, bool $withFingerprints = false): array
    {
        // Slots come from a second table (templatev10) and are only fetched when
        // asked for — see Panel::readUsers(). Until 2026-09-15 they were always null
        // here, which the roster correctly but unhelpfully rendered as "unknown".
        return $this->attempt($device, fn (Panel $panel) => $panel->readUsers(null, $withFingerprints));
    }

    /**
     * Template digests from the panel's own `templatev10` table.
     *
     * Was declared unreadable on this family until 2026-09-15. It was not: the table
     * was simply never asked for, and when it was, its blob field turned out to use a
     * TWO-byte length prefix where every other C3 field uses one — see RecordCodec.
     *
     * CONFIRMED on the inBIO460 (.61) and the TF1700 (.60): 238 templates each, which
     * is exactly the fingercount the vendor software reports for those panels.
     *
     * The blob is dropped here, at the lowest layer that can drop it: the app receives
     * a SHA-1 and a size, never biometric bytes.
     */
    public function readTemplateDigests(Device $device): array
    {
        $records = $this->attempt($device, fn (Panel $panel) => $panel->readTemplates());

        return array_map(static fn (array $r) => [
            'user_id' => $r['user_id'],
            'uid' => $r['uid'],
            'slot' => $r['fid'],
            'size' => $r['size'],
            'sha1' => $r['sha1'],
        ], $records);
    }

    /**
     * The same read as readTemplateDigests(), keeping the bytes — for
     * FingerprintVault only (2026-09-24). See BiometricDeviceDriver::readTemplates().
     */
    public function readTemplates(Device $device): array
    {
        $records = $this->attempt($device, fn (Panel $panel) => $panel->readTemplates());

        return array_map(static fn (array $r) => [
            'user_id' => $r['user_id'],
            'uid' => $r['uid'],
            'slot' => $r['fid'],
            'valid' => $r['valid'],
            'size' => $r['size'],
            'sha1' => $r['sha1'],
            'template' => $r['template'],
        ], $records);
    }

    /**
     * Adds a person to the panel, or renames one it already holds — guard first, in
     * the same session as the write (the contract's reason for existing).
     *
     * NEW PERSON: `user` row, then door permissions copied from what most people on
     * that panel have (Personnel::commonAuthorization — time zone 1 / door 1 on both
     * panels here, 2026-09-24). Without a door row a C3 user opens nothing.
     * EXISTING PERSON: the name is rewritten on the same UID; card, doors and
     * fingerprints are verified unchanged (Personnel::update).
     *
     * EXPERIMENTAL: gated by `user.write`, promoted per panel only after an
     * owner-approved run there (tools/zkprobe/c3-personnel.php).
     */
    public function saveUser(
        Device $device,
        string $userId,
        string $name,
        ?\Closure $guard = null,
        bool $onlyIfMissing = false,
        ?int $privilege = null,
        ?string $password = null,
    ): DeviceUser {
        $pin = $this->pin($userId);

        return $this->attempt($device, function (Panel $panel) use ($pin, $name, $guard, $onlyIfMissing, $password) {
            $people = new Personnel($panel);
            $row = $people->find($pin);
            $occupant = $row === null ? null : $this->toUser($row, $people->fingerprints($pin));

            // Throwing here abandons the write: nothing below runs.
            if ($guard !== null) {
                $guard($occupant);
            }

            if ($onlyIfMissing && $occupant !== null) {
                return $occupant;
            }

            // A null password keeps the row's own on an edit, and adds none (2026-10-12).
            $row = $row === null
                ? $people->add($pin, $name, 0, null, $password ?? '')
                : $people->update($pin, $name, null, $password);

            return $this->toUser($row, $people->fingerprints($pin));
        });
    }

    /** Fingerprints, doors, then the user row, each read back (Personnel::delete). EXPERIMENTAL. */
    public function deleteUser(Device $device, string $userId): void
    {
        $pin = $this->pin($userId);

        $this->attempt($device, fn (Panel $panel) => (new Personnel($panel))->delete($pin));
    }

    /*
     * Moves a person to a new Pin on the panel: user row, door permissions
     * (`userauthorize`) and fingerprints (`templatev10`, under the panel's NEW UID),
     * copy-verify-then-remove (Panel::moveUser). Gated by `user.renumber`, enabled per
     * panel after an owner-approved run on a test user there — CONFIRMED on the TF1700
     * (Entrance) 2026-09-24, trace tools/zkprobe/traces-2026-09-24/c3-user-move-02.json.
     */
    public function renumberUser(Device $device, string $fromUserId, string $toUserId): DeviceUser
    {
        if (! ctype_digit($fromUserId) || ! ctype_digit($toUserId)) {
            throw new UnsupportedCommandException("A C3 panel's user ids are numbers; cannot move {$fromUserId} to {$toUserId}.");
        }

        return $this->attempt($device, function (Panel $panel) use ($fromUserId, $toUserId) {
            /*
             * Already there: 41112750 IS 041112750 to a panel that stores Pins as
             * numbers. Asking it to move a user onto itself is refused ("needs two
             * different Pins", 2026-10-11, every save of Tariq after his move). Hand
             * back the record as it stands; the caller sees the Pin did not change.
             */
            if (self::samePin($fromUserId, $toUserId)) {
                foreach ($panel->readUsers(null, true) as $user) {
                    if (self::samePin($user->userId, $fromUserId)) {
                        return $user;
                    }
                }

                throw new VerificationException("{$fromUserId} is not on the panel.");
            }

            $panel->moveUser((int) $fromUserId, (int) $toUserId);

            // Returned as the PANEL reports it, so the mapping records the number the
            // panel will stamp on punches — 41112750, not the employee code 041112750.
            foreach ($panel->readUsers(null, true) as $user) {
                if (self::samePin($user->userId, $toUserId)) {
                    return $user;
                }
            }

            throw new VerificationException("{$toUserId} was written but is not on the panel when read back.");
        });
    }

    /** GET_PARAM "DateTime" (library 2.12.0, Panel::readClock). */
    public function readClock(Device $device): ?\DateTimeImmutable
    {
        return $this->attempt($device, fn (Panel $panel) => $panel->readClock(Timezone::forDevice($device)));
    }

    /**
     * DATETIME (0x03) "DateTime=<packed>" to now in the panel's zone, read back
     * (library 2.12.0, Panel::setClock — transcribed from vwout/zkaccess-c3-py).
     * Gated by device.time.write in the model's own set.
     */
    public function setClock(Device $device): array
    {
        $this->capabilities($device)->require(Capability::SET_TIME);
        $zone = Timezone::forDevice($device);

        return $this->attempt($device, function (Panel $panel) use ($zone) {
            $before = $panel->readClock($zone);
            $after = $panel->setClock(new \DateTimeImmutable('now', new \DateTimeZone($zone)), $zone);

            return ['before' => $before, 'after' => $after];
        });
    }

    /**
     * C3 panels have no terminal menu to grant (2026-10-18); the `user` table's
     * Privilege field is read, never written. user.privilege is NOT_IMPLEMENTED.
     */
    public function setUserPrivilege(Device $device, string $userId, bool $admin): DeviceUser
    {
        throw new UnsupportedCommandException("{$device->name} is a C3 panel; this app does not change privileges on it.");
    }

    /*
     * The C3 protocol has GET_DATA_COUNT (0x0A) for the `transaction` table, but nothing
     * here has sent it to a panel yet, so the count is not known rather than guessed.
     */
    public function attendanceRecordCount(Device $device): ?int
    {
        return null;
    }

    public function clearAttendance(Device $device, int $expectedRecords): int
    {
        throw new UnsupportedCommandException("{$device->name} is a C3 panel; clearing its transaction log is not implemented.");
    }

    public function deleteAllUsers(Device $device): int
    {
        throw new UnsupportedCommandException("{$device->name} is a C3 panel; wiping its users is not implemented (device.wipe).");
    }

    /**
     * Writes a card onto a person the panel holds, keeping UID, doors and fingers,
     * refusing a card another Pin already carries. 0 clears it. EXPERIMENTAL.
     */
    public function setUserCard(Device $device, string $userId, int $cardNumber): DeviceUser
    {
        $pin = $this->pin($userId);

        return $this->attempt($device, function (Panel $panel) use ($pin, $cardNumber) {
            $people = new Personnel($panel);

            return $this->toUser($people->update($pin, null, $cardNumber), $people->fingerprints($pin));
        });
    }

    /**
     * Adds fingerprint templates to a person the panel holds (Personnel::addFingerprints):
     * add-only, ZKFinger v10 only, under the person's UID, verified byte for byte, and
     * the person's original fingers restored on any failure. EXPERIMENTAL.
     */
    public function uploadTemplates(Device $device, string $userId, array $templates): array
    {
        $pin = $this->pin($userId);

        return $this->attempt($device, fn (Panel $panel) => (new Personnel($panel))->addFingerprints(
            $pin,
            array_map(fn (array $t) => ['fid' => (int) $t['slot'], 'template' => (string) $t['template'], 'valid' => (int) ($t['valid'] ?? 1)], $templates),
        ));
    }

    /**
     * What this panel can do, by model.
     *
     * A stored set from a previous probe wins, so an operator's correction is not
     * overwritten by the baseline on every call.
     */
    public function capabilities(Device $device): CapabilitySet
    {
        $baseline = C3Capabilities::forModel($device->model);
        $stored = $device->capabilities_cache;

        if (is_array($stored) && $stored !== []) {
            return $baseline->with($stored);
        }

        return $baseline;
    }

    /**
     * Probes READ-ONLY and refines the set.
     *
     * Rule 9: only read-only checks, and a probe that throws yields `unknown`, never
     * `not_implemented` — a network blip is not evidence about firmware. Nothing
     * here writes, and nothing here touches the door relay.
     */
    public function probeCapabilities(Device $device): CapabilitySet
    {
        $this->guardProtocol($device);

        /*
         * Start from what the device ALREADY has, not the bare baseline: write levels
         * cannot be probed (rule 9), and a per-panel promotion recorded after a
         * hardware run (user.renumber on Glass Door, 2026-09-24) used to be wiped by
         * every re-probe.
         */
        $resolved = $this->capabilities($device);

        try {
            $tables = $this->attempt($device, fn (Panel $panel) => $panel->catalogue()->tableNames());

            $resolved = $resolved->with([
                Capability::PING => Capability::CONFIRMED,
                Capability::DEVICE_INFO => Capability::CONFIRMED,
            ]);

            // The panel's own catalogue is evidence about what it holds — but only
            // that the TABLE exists. Reading it is a separate claim, so this lifts
            // nothing to CONFIRMED.
            if (! in_array('transaction', $tables, true)) {
                $resolved = $resolved->with([Capability::ATTENDANCE_READ => Capability::NOT_IMPLEMENTED]);
            }

            if (! in_array('user', $tables, true)) {
                $resolved = $resolved->with([Capability::USER_READ => Capability::NOT_IMPLEMENTED]);
            }
        } catch (DeviceException $e) {
            // Unreachable, or refused. That says nothing about the firmware, so the
            // declared set stands unchanged and nothing is demoted.
            Log::warning('C3 capability probe could not reach the panel', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return $resolved;
        }

        $device->forceFill([
            'capabilities_cache' => $resolved->toArray(),
            'capabilities_probed_at' => now(),
        ])->save();

        return $resolved;
    }

    /**
     * Maps the Eloquent model onto the library's value object.
     *
     * This is the seam rule 8 exists for: the library never sees a Device, and the
     * timezone is passed explicitly because the panel stamps LOCAL time with nothing
     * in the payload saying so (rule 10).
     */
    private function panelFor(Device $device): Panel
    {
        return new Panel(
            new DeviceConfig(
                host: $device->ip_address,
                port: $device->port ?: 4370,
                commKey: (int) ($device->comm_key ?? 0),
                timeout: (float) config('attendance.device_timeout', 10.0),
                timezone: Timezone::forDevice($device),
            ),
            $device->model,
        );
    }

    /** A C3 Pin is a number; anything else would be written into an integer field as 0. */
    /**
     * Whether two user ids name the same C3 Pin.
     *
     * A C3 Pin is an INTEGER (the `user` table's Pin column), so a leading zero does
     * not survive the write: employee code 041112750 is stored as 41112750. Comparing
     * the strings reported a move that had succeeded as a failure (2026-10-11,
     * Entrance and Glass Door) — and left our records on the old PIN while the panel
     * had moved on. Compared as numbers instead, as the panel does.
     */
    public static function samePin(string $a, string $b): bool
    {
        return ctype_digit($a) && ctype_digit($b) && (int) $a === (int) $b;
    }

    private function pin(string $userId): int
    {
        if (! ctype_digit($userId) || (int) $userId <= 0) {
            throw new UnsupportedCommandException("A C3 panel's user ids are positive numbers; '{$userId}' is not one.");
        }

        return (int) $userId;
    }

    /**
     * @param  array<string,int|string>  $row  a `user` row
     * @param  array<int,string>  $fingers  slot => sha1, read in the same session
     */
    private function toUser(array $row, array $fingers): DeviceUser
    {
        $card = (int) ($row['CardNo'] ?? 0);

        return new DeviceUser(
            uid: (int) ($row['UID'] ?? 0),
            userId: (string) $row['Pin'],
            name: (string) ($row['Name'] ?? ''),
            privilege: (int) ($row['Privilege'] ?? 0),
            cardNumber: $card > 0 ? $card : null,
            fingerSlots: array_keys($fingers),
            password: array_key_exists('Password', $row) ? (string) $row['Password'] : null,
        );
    }

    private function guardProtocol(Device $device): void
    {
        if (! $this->supports($device->protocol)) {
            throw new \InvalidArgumentException(
                "C3Driver cannot speak protocol '{$device->protocol}' for device {$device->name}.",
            );
        }
    }

    /**
     * Runs one panel operation, always closing the session.
     *
     * Failures are logged the way the other two drivers log them so all three
     * vendors look identical in the log, and rethrown unchanged so callers keep the
     * typed exception the retry machinery reads.
     *
     * @template T
     *
     * @param  \Closure(Panel):T  $operation
     * @return T
     */
    private function attempt(Device $device, \Closure $operation): mixed
    {
        $this->guardProtocol($device);

        $panel = $this->panelFor($device);

        try {
            $panel->open();

            return $operation($panel);
        } catch (DeviceException $e) {
            Log::warning('C3 panel operation failed', [
                'device_id' => $device->id,
                'device' => $device->name,
                'host' => $device->ip_address,
                'model' => $panel->modelName(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $panel->close();
        }
    }
}
