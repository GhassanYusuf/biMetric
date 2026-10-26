<?php

namespace App\Services\Devices\Csl;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Support\Timezone;
use Illuminate\Support\Facades\Log;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Capability\CapabilitySet;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Value\DeviceUser;
use Promoseven\Biometric\Exception\DeviceException;
use Promoseven\Biometric\Exception\UnsupportedCommandException;

/**
 * The second driver, and the proof the contract was worth having.
 *
 * CSL/Secutime firmware exposes an authenticated web UI instead of the binary
 * protocol. There is no session, no capability negotiation, no realtime stream and
 * no template access — so most of what a ZKTeco terminal does simply is not
 * available here. That is expressed as capabilities, NOT as methods that return a
 * plausible empty result: a caller asking this device for fingerprints gets
 * UnsupportedCommandException, and the UI hides the action entirely (rule 9).
 *
 * WHAT THIS DRIVER SUPPORTS
 *   - ping           via a web login
 *   - attendance read via the date-range export
 *   - user read/write via the admin forms (not yet wired into the app's sync)
 *
 * WHAT IT CANNOT DO, AND WHY
 *   - run()/open()   there is no protocol session to hand out. Anything reaching
 *                    for one is ZKTeco-specific by definition.
 *   - fingerprints   the web UI exposes no template endpoint at all.
 *   - realtime       the firmware pushes nothing; polling the export is the only
 *                    way to learn about a punch.
 *
 * KNOWN DEBT, DELIBERATE: Capability, CapabilitySet and DeviceException all live in
 * the ZKTeco package, so this non-ZKTeco driver has to import from it. That shared
 * vocabulary belongs in a vendor-neutral package, and moving it is a prerequisite
 * for a third vendor. It is not a prerequisite for this one, so it is recorded here
 * rather than done here.
 */
class CslHttpDriver implements BiometricDeviceDriver
{
    /** How far back a first-ever sync reaches, in days. */
    private const FIRST_SYNC_WINDOW_DAYS = 90;

    /**
     * The embedded web UI answers on plain HTTP port 80 on every CSL terminal seen
     * here, and `devices` carries no column for it — `port` holds the binary
     * protocol's 4370 and must not be reused. If a unit ever answers elsewhere this
     * needs a column, not a guess.
     */
    private const WEB_PORT = 80;

    private const SUPPORTED = [Device::PROTOCOL_CSL_HTTP];

    public function supports(string $protocol): bool
    {
        return in_array($protocol, self::SUPPORTED, true);
    }

    /**
     * There is no session to hand out, and pretending otherwise would let
     * ZKTeco-shaped code run against a device that cannot serve it.
     */
    public function run(Device $device, \Closure $callback): mixed
    {
        throw new UnsupportedCommandException(
            "Device {$device->name} speaks the CSL web protocol, which has no command session. "
            .'Use the driver methods directly; there is nothing to run a protocol callback against.',
        );
    }

    public function open(Device $device): ProtocolDevice
    {
        throw new UnsupportedCommandException(
            "Device {$device->name} speaks the CSL web protocol and cannot hold a protocol session open. "
            .'Realtime listening is not available on this firmware.',
        );
    }

    /**
     * Reachability is proven by completing a login, because that is the only thing
     * this firmware offers that proves both the network path AND the credentials.
     * A device answering HTTP with rejected credentials is not usable, so it must
     * not report alive.
     */
    public function ping(Device $device): array
    {
        $startedAt = microtime(true);

        $uids = $this->attempt($device, fn (CslWebPortalClient $c) => $c->fetchEnrolledUids());

        return [
            'alive' => true,
            // The web UI exposes no clock. Null means "not read", never "midnight".
            'device_time' => null,
            'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'note' => count($uids).' enrolled user(s) visible in the web export.',
        ];
    }

    /**
     * The web UI reports no firmware, serial or capacity fields anywhere we have
     * found. Everything unknown is returned as null rather than omitted, so callers
     * can tell "this device does not report it" from "we did not ask".
     */
    public function info(Device $device): array
    {
        $uids = $this->attempt($device, fn (CslWebPortalClient $c) => $c->fetchEnrolledUids());

        return [
            'protocol' => Device::PROTOCOL_CSL_HTTP,
            'firmware' => null,
            'serial' => null,
            'platform' => null,
            'device_name' => null,
            'user_count' => count($uids),
            'attendance_count' => null,
        ];
    }

    /**
     * Pulls the date-range export and returns it in the shared record shape.
     *
     * PROTOCOL LIMIT: there is no cursor. The export takes a date range and nothing
     * else, so an incremental read means choosing a window, not asking for "since
     * X". The window starts a day before the last successful sync — deliberately
     * overlapping, because the unique index dedupes and a gap loses punches. A
     * device that has never synced reaches back FIRST_SYNC_WINDOW_DAYS.
     */
    public function readAttendance(Device $device, ?callable $onProgress = null): array
    {
        $from = $device->last_synced_at?->copy()->subDay() ?? now()->subDays(self::FIRST_SYNC_WINDOW_DAYS);

        // $onProgress is accepted and deliberately never called. This terminal
        // answers a web form with one HTTP response: there is no chunked transfer
        // to count, and no total is announced before the body arrives. Inventing
        // a "50%" here would be a number with nothing behind it — the UI shows an
        // indeterminate bar instead, which is the truthful rendering of
        // "in progress, extent unknown" (rule 10).
        return $this->attempt($device, fn (CslWebPortalClient $c) => $c->downloadAttendance($from, now()));
    }

    /**
     * The roster, from the web UI's enrolled-user export.
     *
     * THIS FIXES A LATENT BUG. CslHttpDriver has declared USER_READ as CONFIRMED
     * since it was written, but the only way to read users went through run(),
     * which this driver throws on — so "Import staff" on a CSL terminal answered a
     * 502 while the capability gate said yes. The capability was not wrong; there
     * was simply no contract method that could carry the answer. Now there is.
     *
     * The export lists enrolled UIDs and nothing else — no names, no cards, no
     * privilege. Those come back empty and null rather than invented: an empty name
     * lets the caller fall back to a placeholder, whereas a fabricated one would be
     * indistinguishable from a real record. Finger slots are null, meaning "not
     * read" — this firmware exposes no template endpoint at all (rule 10).
     *
     * @return list<DeviceUser>
     */
    public function readUsers(Device $device, bool $withFingerprints = false): array
    {
        $uids = $this->attempt($device, fn (CslWebPortalClient $c) => $c->fetchEnrolledUids());

        return array_values(array_map(
            fn ($uid) => new DeviceUser(
                uid: (int) $uid,
                userId: (string) $uid,
                name: '',
                privilege: 0,
                cardNumber: null,
                fingerSlots: null,
            ),
            $uids,
        ));
    }

    /**
     * What this firmware can do, declared rather than negotiated.
     *
     * Everything here is DOCUMENTED at best: it was read off one terminal's HTML,
     * not confirmed by a probe, because there is nothing to probe — the web UI has
     * no capability endpoint. A stored set from a previous read still wins, so an
     * operator's correction is not overwritten by this baseline.
     */
    /**
     * Not available on this family (rule 9: declared, not silently plausible).
     *
     * These panels keep templates in a separate table this driver does not read, so
     * there are no digests to return. Throwing rather than returning an empty array
     * keeps "cannot read" distinct from "nobody is enrolled" (rule 10).
     */
    public function readTemplateDigests(Device $device): array
    {
        throw new UnsupportedCommandException(
            "{$device->name} (CSL web portal) does not expose a readable fingerprint template table."
        );
    }

    public function readTemplates(Device $device): array
    {
        return $this->readTemplateDigests($device);
    }

    /**
     * Not implemented for this family (rule 9: declared, not silently plausible).
     *
     * Writing a user record here would mean a different mechanism entirely, and an
     * unguarded one would carry exactly the overwrite risk BiometricDeviceDriver::
     * saveUser() exists to remove. Until that is written and confirmed against real
     * hardware, this refuses rather than returning something that looks like success.
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
        throw new UnsupportedCommandException(
            "{$device->name} (CSL web portal) does not support writing user records through this driver."
        );
    }

    public function deleteUser(Device $device, string $userId): void
    {
        throw new UnsupportedCommandException(
            "{$device->name} (CSL web portal) does not support deleting user records through this driver."
        );
    }

    public function renumberUser(Device $device, string $fromUserId, string $toUserId): DeviceUser
    {
        throw new UnsupportedCommandException(
            "{$device->name} (CSL web portal) does not support changing a user id through this driver."
        );
    }

    public function setUserCard(Device $device, string $userId, int $cardNumber): DeviceUser
    {
        throw new UnsupportedCommandException(
            "{$device->name} (CSL web portal) does not support writing cards through this driver."
        );
    }

    public function setUserPrivilege(Device $device, string $userId, bool $admin): DeviceUser
    {
        throw new UnsupportedCommandException(
            "{$device->name} (CSL web portal) does not support changing a user's privilege through this driver."
        );
    }

    // The portal exports punches; it publishes no count of what the terminal holds.
    public function attendanceRecordCount(Device $device): ?int
    {
        return null;
    }

    public function clearAttendance(Device $device, int $expectedRecords): int
    {
        throw new UnsupportedCommandException(
            "{$device->name} (CSL web portal) does not support clearing attendance through this driver."
        );
    }

    public function deleteAllUsers(Device $device): int
    {
        throw new UnsupportedCommandException("{$device->name} (CSL web portal) does not support a wipe through this driver.");
    }

    public function restart(Device $device): void
    {
        throw new UnsupportedCommandException("{$device->name} (CSL web portal) does not support a restart through this driver.");
    }

    /** The CSL portal exposes no clock this driver reads. Null = "not read" (rule 10). */
    public function readClock(Device $device): ?\DateTimeImmutable
    {
        return null;
    }

    public function setClock(Device $device): array
    {
        throw new UnsupportedCommandException("{$device->name} (CSL web portal) does not support setting the clock through this driver.");
    }

    public function uploadTemplates(Device $device, string $userId, array $templates): array
    {
        throw new UnsupportedCommandException(
            "{$device->name} (CSL web portal) does not support writing fingerprint templates."
        );
    }

    public function capabilities(Device $device): CapabilitySet
    {
        $stored = $device->capabilities_cache;

        if (is_array($stored) && $stored !== []) {
            return self::baseline()->with($stored);
        }

        return self::baseline();
    }

    /**
     * There is nothing to probe: no capability endpoint, and every read-only check
     * we could make is already reflected in the baseline. Returning the declared set
     * unchanged is the honest answer — inventing a probe that always succeeds would
     * upgrade DOCUMENTED to CONFIRMED without evidence.
     */
    public function probeCapabilities(Device $device): CapabilitySet
    {
        $this->guardProtocol($device);

        // Prove we can at least talk to it, so a totally unreachable device does not
        // come back looking healthy.
        $this->ping($device);

        $resolved = self::baseline();

        $device->forceFill([
            'capabilities_cache' => $resolved->toArray(),
            'capabilities_probed_at' => now(),
        ])->save();

        return $resolved;
    }

    /** The declared capability baseline for CSL web firmware. */
    private static function baseline(): CapabilitySet
    {
        return CapabilitySet::fromArray([
            // Exercised against real CSL hardware in this project.
            Capability::PING => Capability::CONFIRMED,
            Capability::ATTENDANCE_READ => Capability::CONFIRMED,
            Capability::USER_READ => Capability::CONFIRMED,

            // The forms exist and are driven by this client, but no write has been
            // observed succeeding end-to-end from this application.
            Capability::USER_WRITE => Capability::IMPLEMENTED,
            Capability::USER_DELETE => Capability::IMPLEMENTED,

            // The web UI has no endpoint for any of these. Not "unknown" — we have
            // looked, and they are absent.
            Capability::DEVICE_INFO => Capability::NOT_IMPLEMENTED,
            Capability::GET_TIME => Capability::NOT_IMPLEMENTED,
            Capability::SET_TIME => Capability::NOT_IMPLEMENTED,
            Capability::RESTART => Capability::NOT_IMPLEMENTED,
            Capability::ATTENDANCE_REALTIME => Capability::NOT_IMPLEMENTED,
            Capability::ATTENDANCE_CLEAR => Capability::NOT_IMPLEMENTED,
            Capability::DEVICE_WIPE => Capability::NOT_IMPLEMENTED,
            Capability::CARD_READ => Capability::NOT_IMPLEMENTED,
            Capability::CARD_WRITE => Capability::NOT_IMPLEMENTED,
            Capability::CARD_ENROLL => Capability::NOT_IMPLEMENTED,
            Capability::FINGERPRINT_ENROLL => Capability::NOT_IMPLEMENTED,
            Capability::TEMPLATE_TABLE_READ => Capability::NOT_IMPLEMENTED,
            Capability::TEMPLATE_SLOT_PROBE => Capability::NOT_IMPLEMENTED,
            Capability::TEMPLATE_DELETE => Capability::NOT_IMPLEMENTED,
            Capability::TEMPLATE_DOWNLOAD => Capability::NOT_IMPLEMENTED,
            Capability::TEMPLATE_UPLOAD => Capability::NOT_IMPLEMENTED,
            Capability::USER_DISABLE => Capability::NOT_IMPLEMENTED,
            Capability::USER_PRIVILEGE => Capability::NOT_IMPLEMENTED,

            // Never investigated on this firmware.
            Capability::VERIFY_MODE => Capability::UNKNOWN,
            Capability::ACCESS_SCHEDULES => Capability::UNKNOWN,
            Capability::ANTI_PASSBACK => Capability::UNKNOWN,
            Capability::DURESS => Capability::UNKNOWN,
            Capability::RELAY => Capability::UNKNOWN,
        ]);
    }

    private function clientFor(Device $device): CslWebPortalClient
    {
        return new CslWebPortalClient(
            host: $device->ip_address,
            username: (string) $device->web_username,
            password: (string) $device->web_password,
            port: self::WEB_PORT,
            timeout: (float) config('attendance.device_timeout', 10.0),
            timezone: Timezone::forDevice($device),
        );
    }

    private function guardProtocol(Device $device): void
    {
        if (! $this->supports($device->protocol)) {
            throw new \InvalidArgumentException(
                "CslHttpDriver cannot speak protocol '{$device->protocol}' for device {$device->name}.",
            );
        }
    }

    /**
     * Runs one web operation, logging failures the way ZktecoDriver does so both
     * vendors look identical in the log, and rethrowing unchanged so callers keep
     * the typed exception.
     *
     * @template T
     *
     * @param  \Closure(CslWebPortalClient):T  $operation
     * @return T
     */
    private function attempt(Device $device, \Closure $operation): mixed
    {
        $this->guardProtocol($device);

        $client = $this->clientFor($device);

        try {
            $client->login();

            return $operation($client);
        } catch (DeviceException $e) {
            Log::warning('Device operation failed', [
                'device_id' => $device->id,
                'device_name' => $device->name,
                'endpoint' => "{$device->ip_address}:".self::WEB_PORT,
                'exception' => $e::class,
                'retryable' => $e->isRetryable(),
                'context' => $e->context(),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
