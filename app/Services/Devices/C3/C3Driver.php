<?php

namespace App\Services\Devices\C3;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Support\DeviceProgress;
use Illuminate\Support\Facades\Log;
use Promoseven\Biometric\C3\Capability\C3Capabilities;
use Promoseven\Biometric\C3\Client\Panel;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Capability\CapabilitySet;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Exception\DeviceException;
use Promoseven\Biometric\Exception\UnsupportedCommandException;
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
 *
 * WHAT IT DELIBERATELY CANNOT DO
 *   - run()/open()   there is no standalone protocol session to hand out.
 *   - every write    enrollment, user push, and the door relay are UNBUILT. We know
 *                    from the vendor SDK exactly which commands would do it; rule 16
 *                    requires the owner's explicit go-ahead and a terminal out of
 *                    service before any of it is probed. A door relay is not
 *                    something to discover by accident on a live entrance.
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

        return [
            'alive' => true,
            // These panels expose no clock parameter we have found. Null means
            // "not read", never midnight (rule 10).
            'device_time' => null,
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
        return $this->attempt($device, fn (Panel $panel) => $panel->readUsers());
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

        $resolved = C3Capabilities::forModel($device->model);

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
                timezone: $device->timezone ?: config('attendance.device_timezone', '+03:00'),
            ),
            $device->model,
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
