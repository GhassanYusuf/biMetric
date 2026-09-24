<?php

namespace App\Services\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Support\DeviceProgress;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Promoseven\Biometric\Capability\CapabilitySet;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Codec\AttendanceCodec;
use Promoseven\Biometric\Codec\RecordBuffer;
use Promoseven\Biometric\Contracts\ProgressListener;
use Promoseven\Biometric\Exception\DeviceException;
use Promoseven\Biometric\Exception\VerificationException;
use Promoseven\Biometric\Value\DeviceConfig;
use Promoseven\Biometric\Value\DeviceUser;
use Promoseven\Biometric\Value\Progress;

/**
 * Adapts the framework-agnostic zkteco-protocol package to this application.
 *
 * This is the ONLY class in the app that imports anything from Promoseven\Biometric.
 * Its whole job is translation:
 *
 *   Device (Eloquent)  ->  DeviceConfig (value object)
 *   stored capabilities ->  CapabilitySet
 *   DeviceException     ->  logged with app context, rethrown unchanged
 *
 * It deliberately does NOT add business logic. Sync orchestration, watermarks,
 * enrollment sessions and reconciliation live in their own services above it.
 */
class ZktecoDriver implements BiometricDeviceDriver
{
    /** Protocol values from Device this driver handles. */
    private const SUPPORTED = [Device::PROTOCOL_BINARY];

    public function run(Device $device, \Closure $callback): mixed
    {
        $this->guardProtocol($device);

        try {
            return ProtocolDevice::run(
                $this->configFor($device),
                $callback,
                $this->capabilities($device),
            );
        } catch (DeviceException $e) {
            $this->logFailure($device, $e);

            throw $e;
        }
    }

    /**
     * Logs with app-side identity the library cannot know about.
     *
     * The exception is always rethrown unchanged — callers decide how to present it,
     * and the typed exception is what lets them tell a timeout from an unsupported
     * command.
     */
    private function logFailure(Device $device, DeviceException $e): void
    {
        Log::warning('Device operation failed', [
            'device_id' => $device->id,
            'device_name' => $device->name,
            'endpoint' => "{$device->ip_address}:{$device->port}",
            'exception' => $e::class,
            'retryable' => $e->isRetryable(),
            'context' => $e->context(),
            'message' => $e->getMessage(),
        ]);
    }

    /**
     * Opens a session the caller owns. See the contract — this is for the realtime
     * listener only; everything else uses run().
     */
    public function open(Device $device): ProtocolDevice
    {
        $this->guardProtocol($device);

        try {
            return ProtocolDevice::open($this->configFor($device), $this->capabilities($device));
        } catch (DeviceException $e) {
            $this->logFailure($device, $e);

            throw $e;
        }
    }

    public function ping(Device $device): array
    {
        return $this->run($device, fn (ProtocolDevice $d) => $d->ping());
    }

    public function info(Device $device): array
    {
        return $this->run($device, fn (ProtocolDevice $d) => $d->info());
    }

    /**
     * Reads the whole attendance table, then decodes it.
     *
     * The transfer happens inside one session and the decode happens after it
     * closes: decoding is pure, and holding a session open through it would keep a
     * device locked for no reason. The device stamps LOCAL time, so its configured
     * offset is what the codec is given — see configFor().
     */
    public function readAttendance(Device $device, ?callable $onProgress = null): array
    {
        $raw = $this->run($device, function (ProtocolDevice $d) use ($onProgress) {
            if ($onProgress !== null) {
                $d->onProgress($this->progressAdapter($onProgress));
            }

            return $d->attendance()->raw();
        });

        return AttendanceCodec::decode(
            $raw,
            $device->timezone ?: config('attendance.device_timezone', '+03:00'),
        );
    }

    /**
     * Turns the library's byte counts into the record counts an operator cares
     * about, and hands them to app code as a vendor-neutral DeviceProgress.
     *
     * THIS IS THE ONLY PLACE THE CONVERSION CAN HAPPEN. The transport honestly
     * knows only bytes — it is generic over users, attendance and templates. The
     * app layer must not name AttendanceCodec (rule 8). The driver is the seam
     * that legitimately knows both, exactly as it already knows how to turn a
     * Device model into a DeviceConfig.
     *
     * The record width is DERIVED, not assumed: this protocol never announces it,
     * so RecordBuffer::detectRecordSize() infers it from the declared total the
     * same way the decoder later infers it from the received total. When it cannot
     * be inferred — a length divisible by none of the known widths — progress is
     * reported in BYTES rather than in invented records. A percentage that is
     * honest about its unit beats a record count that is quietly wrong.
     */
    private function progressAdapter(callable $onProgress): ProgressListener
    {
        return new class($onProgress) implements ProgressListener
        {
            public function __construct(private $sink) {}

            public function onProgress(Progress $progress): void
            {
                // The 4-byte length prefix is part of the transfer but not part of
                // any record, so it is excluded from both sides of the ratio.
                $bodyTotal = max(0, $progress->bytesTotal - 4);
                $bodyRead = max(0, $progress->bytesRead - 4);

                $width = RecordBuffer::detectRecordSize($bodyTotal, AttendanceCodec::WIDTHS);

                $mapped = $width > 0
                    ? new DeviceProgress(
                        $progress->operation,
                        intdiv($bodyRead, $width),
                        intdiv($bodyTotal, $width),
                        DeviceProgress::UNIT_RECORDS,
                        $progress->attempt,
                    )
                    : new DeviceProgress(
                        $progress->operation,
                        $progress->bytesRead,
                        $progress->bytesTotal,
                        DeviceProgress::UNIT_BYTES,
                        $progress->attempt,
                    );

                ($this->sink)($mapped);
            }
        };
    }

    /**
     * Capabilities as this app has them stored, falling back to the library's
     * declared baseline.
     *
     * No network access — safe to call while rendering a list of devices.
     */
    /**
     * The roster, straight off the standalone protocol's user table.
     *
     * Deliberately the SAME call DeviceUserSyncService made inline before this
     * method existed — `$d->users()->all($withFingerprints)` — so the F18 path is
     * byte-for-byte what it was and nothing needed re-confirming against hardware
     * (rule 15). All that changed is who owns the closure.
     *
     * @return list<DeviceUser>
     */
    public function readUsers(Device $device, bool $withFingerprints = false): array
    {
        return $this->run($device, fn (ProtocolDevice $d) => $d->users()->all($withFingerprints));
    }

    /**
     * Template digests, from the table the enrollment sync already reads.
     *
     * No new command and no extra round trip — CMD_DB_RRQ with FCT_FINGERTMP has
     * always returned the blobs; the codec was stepping over them.
     *
     * The blob is dropped here, at the lowest layer that can drop it: the app gets a
     * SHA-1 and a size, never biometric bytes.
     */
    public function readTemplateDigests(Device $device): array
    {
        $records = $this->run($device, fn (ProtocolDevice $d) => $d->templates()->allWithTemplates());

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
        $records = $this->run($device, fn (ProtocolDevice $d) => $d->templates()->allWithTemplates());

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
     * Guarded write. The read and the write share ONE session, deliberately.
     *
     * The guard runs between them, still inside that session, so nothing can take the
     * slot in between. Splitting this into "find, then save" at the service layer is
     * what the contract exists to prevent — see BiometricDeviceDriver::saveUser().
     */
    public function saveUser(
        Device $device,
        string $userId,
        string $name,
        ?\Closure $guard = null,
        bool $onlyIfMissing = false,
    ): DeviceUser {
        return $this->run($device, function (ProtocolDevice $d) use ($userId, $name, $guard, $onlyIfMissing) {
            $occupant = $d->users()->find($userId);

            // Throwing here abandons the write. That is the whole point: the exception
            // propagates out of the session and save() is never reached.
            if ($guard !== null) {
                $guard($occupant);
            }

            if ($onlyIfMissing && $occupant !== null) {
                return $occupant;
            }

            // save() preserves the card and privilege already on the device — writing
            // the defaults would wipe a card and demote an admin, both silently.
            return $d->users()->save($userId, $name);
        });
    }

    public function deleteUser(Device $device, string $userId): void
    {
        $this->run($device, fn (ProtocolDevice $d) => $d->users()->delete($userId));
    }

    /*
     * DELETE + RE-UPLOAD, not the in-place rewrite. The F18 refuses to change the user
     * id of an existing uid (ACK_ERROR, 2026-09-23, IT Room), so in-place renumber() is
     * a dead end on the reference model. renumberByReupload() copies the record and
     * every fingerprint template, deletes, writes them back under the new id with
     * SAVE_USERTEMPS, verifies, and restores the original on any failure.
     *
     * EXPERIMENTAL: gated by `user.renumber`, still `not_implemented` in
     * CapabilitySet::defaults() until the owner-authorised run on a test user
     * (tools/zkprobe/renumber-reupload.php) has succeeded on the F18.
     *
     * THE SNAPSHOT is written BEFORE the first device write — encrypted, because it
     * holds fingerprint templates — and deleted only once the move is verified. If the
     * library's own restore also fails, that file is the only copy of this person's
     * fingerprints, and the error names it.
     */
    public function renumberUser(Device $device, string $fromUserId, string $toUserId): DeviceUser
    {
        $path = sprintf('renumber-snapshots/device-%d-%s-%s.json.enc', $device->id, $fromUserId, now()->format('Ymd-His'));

        $snapshot = function (array $s) use ($path, $device) {
            Storage::disk('local')->put($path, Crypt::encryptString(json_encode([
                'device_id' => $device->id,
                'uid' => $s['uid'],
                'user_id' => $s['user_id'],
                'record' => base64_encode($s['record']),
                'templates' => array_map(fn (array $t) => [
                    'fid' => $t['fid'], 'valid' => $t['valid'], 'sha1' => $t['sha1'],
                    'template' => base64_encode($t['template']),
                ], $s['templates']),
                'taken_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR)));
        };

        try {
            $user = $this->run($device, fn (ProtocolDevice $d) => $d->users()->renumberByReupload($fromUserId, $toUserId, $snapshot));
        } catch (VerificationException $e) {
            if (($e->context()['restored'] ?? true) === false) {
                Log::channel('device')->critical('Renumber failed AND restore failed; snapshot kept.', [
                    'device_id' => $device->id, 'from' => $fromUserId, 'to' => $toUserId, 'snapshot' => $path,
                ]);
                throw new VerificationException($e->getMessage()." Snapshot: storage/app/private/{$path}", $e->context() + ['snapshot' => $path], 0, $e);
            }
            Storage::disk('local')->delete($path);
            throw $e;
        }

        Storage::disk('local')->delete($path);

        return $user;
    }

    public function setUserCard(Device $device, string $userId, int $cardNumber): DeviceUser
    {
        // UserService::setCard() refuses a user the device does not hold, keeps name
        // and privilege from the record it just read, and reads back after writing.
        return $this->run($device, fn (ProtocolDevice $d) => $d->users()->setCard($userId, $cardNumber));
    }

    public function capabilities(Device $device): CapabilitySet
    {
        $stored = $device->capabilities_cache;

        if (is_array($stored) && $stored !== []) {
            return CapabilitySet::defaults()->with($stored);
        }

        return CapabilitySet::defaults();
    }

    /**
     * Probes the device and persists the refined set.
     *
     * Read-only probes only — the resolver never tests a capability by mutating the
     * device. Costs several round trips, so this is explicit rather than implicit.
     */
    public function probeCapabilities(Device $device): CapabilitySet
    {
        $resolved = $this->run($device, fn (ProtocolDevice $d) => $d->probeCapabilities());

        $device->forceFill([
            'capabilities_cache' => $resolved->toArray(),
            'capabilities_probed_at' => now(),
        ])->save();

        return $resolved;
    }

    public function supports(string $protocol): bool
    {
        return in_array($protocol, self::SUPPORTED, true);
    }

    /**
     * Maps the Eloquent model onto the library's value object.
     *
     * The device stamps punches in its own LOCAL time, so the configured attendance
     * timezone is passed through — without it every imported timestamp is offset,
     * and a browser then applies the viewer's offset on top, doubling the error.
     */
    private function configFor(Device $device): DeviceConfig
    {
        return new DeviceConfig(
            host: $device->ip_address,
            port: $device->port ?: 4370,
            commKey: (int) ($device->comm_key ?: 0),
            timeout: (float) config('attendance.device_timeout', 8.0),
            timezone: $device->timezone ?: config('attendance.device_timezone', '+03:00'),
            receiveBufferBytes: (int) config('attendance.device_receive_buffer', 4 * 1024 * 1024),
            // Windowed-read stall tolerance. A LAN device never stalls a window, so
            // the default is harmless there; a device reached over the internet
            // needs the retries to complete a large table on the UDP path.
            bulkWindowRetries: (int) config('attendance.device_window_retries', 4),
            transport: $this->transportFor($device),
        );
    }

    /**
     * Chooses UDP or TCP for a device.
     *
     * `attendance.device_transport` is 'udp', 'tcp', or 'auto' (default). On 'auto'
     * a device on the public internet gets TCP and a LAN device gets UDP: UDP is
     * proven and fast on the LAN, while over the internet it loses bursts and stalls
     * and only TCP's flow control and retransmission complete a large read reliably.
     * A single device can be forced either way by setting the config explicitly.
     */
    private function transportFor(Device $device): string
    {
        $configured = (string) config('attendance.device_transport', 'auto');

        if (in_array($configured, [DeviceConfig::TRANSPORT_UDP, DeviceConfig::TRANSPORT_TCP], true)) {
            return $configured;
        }

        $isPublic = filter_var(
            $device->ip_address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;

        return $isPublic ? DeviceConfig::TRANSPORT_TCP : DeviceConfig::TRANSPORT_UDP;
    }

    private function guardProtocol(Device $device): void
    {
        if (! $this->supports($device->protocol)) {
            throw new \InvalidArgumentException(
                "ZktecoDriver cannot speak protocol '{$device->protocol}' for device {$device->name}. "
                .'Direct device operations require the Binary/TCP-IP protocol; CSL HTTP devices are '
                .'handled by the web-portal client instead.',
            );
        }
    }
}
