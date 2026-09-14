<?php

namespace App\Console\Commands;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Services\Devices\AttendanceSyncService;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Exception\DeviceException;

/**
 * Holds a session open to each terminal and records punches as they happen,
 * instead of waiting for the hourly sync to notice them.
 *
 * PURELY ADDITIVE. If this is not running, or a link drops, the scheduled sync
 * still imports everything — the device keeps its own table and the importer
 * re-reads it. Nothing is lost by this command being down; punches just arrive
 * late. That is why a connection failure here warns and retries rather than
 * failing the command.
 *
 * ONE PROCESS, MANY DEVICES. There is no pcntl on Windows, so instead of a
 * process per device this blocks on every device's socket at once via
 * stream_select(). That is what BiometricDeviceDriver::open() and
 * Device::selectableStream() exist for — run() cannot express a session that
 * outlives a single callback.
 *
 * Only binary-protocol devices have an event stream at all; CSL/HTTP terminals
 * export over the web portal and are skipped with a note.
 */
class ListenZkteco extends Command
{
    protected $signature = 'zkteco:listen
                            {--device=* : Specific device ids (default: every active binary-protocol device)}';

    protected $description = 'Hold a session open to ZKTeco terminals and record punches live as they happen';

    /** Seconds before a dropped device is retried. */
    private const RETRY_AFTER = 30;

    /** @var array<int, ProtocolDevice> device id => open session */
    private array $sessions = [];

    /** @var array<int, int> device id => unix time of next allowed reconnect */
    private array $nextRetryAt = [];

    public function handle(BiometricDeviceDriver $driver, AttendanceSyncService $attendance): int
    {
        /*
         * Console context: no request, so no resolved tenant. Read across all of
         * them, then set the tenant per device before storing anything — the same
         * discipline ProcessDeviceCommand follows for the same reason.
         */
        Tenancy::spanAllTenants();

        $devices = $this->targetDevices();

        foreach ($this->skippedDeviceNames() as $name) {
            $this->warn("Skipping {$name}: CSL/HTTP terminals have no realtime event stream.");
        }

        if ($devices->isEmpty()) {
            $this->error('No active binary-protocol devices to listen on.');

            return self::FAILURE;
        }

        foreach ($devices as $device) {
            $this->connect($driver, $device);
        }

        $this->info('Listening on: '.$devices->pluck('name')->join(', '));
        $this->info('(The scheduled sync remains the fallback if this stops — Ctrl+C to exit.)');

        try {
            while (true) {
                foreach ($devices as $device) {
                    if (! isset($this->sessions[$device->id]) && ($this->nextRetryAt[$device->id] ?? 0) <= time()) {
                        $this->connect($driver, $device);
                    }
                }

                if ($this->sessions === []) {
                    sleep(5);

                    continue;
                }

                $this->pollOnce($devices, $attendance);
            }
        } finally {
            // open() hands us the session, so closing it is our job — including on
            // Ctrl+C, which unwinds through here.
            foreach ($this->sessions as $session) {
                $session->close();
            }
        }
    }

    /** @return Collection<int, Device> */
    private function targetDevices(): Collection
    {
        $query = Device::query()
            ->where('is_active', true)
            ->where('protocol', Device::PROTOCOL_BINARY);

        if ($ids = $this->option('device')) {
            $query->whereIn('id', $ids);
        }

        return $query->get()->keyBy('id');
    }

    /** @return Collection<int, string> */
    private function skippedDeviceNames(): Collection
    {
        return Device::query()
            ->where('is_active', true)
            ->where('protocol', Device::PROTOCOL_CSL_HTTP)
            ->pluck('name');
    }

    /**
     * Waits for any device to push, then drains whichever ones did.
     *
     * @param  Collection<int, Device>  $devices
     */
    private function pollOnce(Collection $devices, AttendanceSyncService $attendance): void
    {
        $streams = [];

        foreach ($this->sessions as $deviceId => $session) {
            $stream = $session->selectableStream();

            // Null means the transport cannot be selected on. Not expected for UDP,
            // but the contract permits it, so fall back to polling that device
            // rather than dropping it silently.
            if ($stream === null) {
                $this->drain($deviceId, $devices, $attendance, pollSeconds: 0.05);

                continue;
            }

            $streams[$deviceId] = $stream;
        }

        if ($streams === []) {
            return;
        }

        $read = $streams;
        $write = $except = [];

        $ready = @stream_select($read, $write, $except, 1);

        if ($ready === false || $ready === 0) {
            return;
        }

        foreach ($streams as $deviceId => $stream) {
            if (in_array($stream, $read, true)) {
                $this->drain($deviceId, $devices, $attendance, pollSeconds: 0.0);
            }
        }
    }

    /**
     * Reads and stores one pending push from a device.
     *
     * @param  Collection<int, Device>  $devices
     */
    private function drain(
        int $deviceId,
        Collection $devices,
        AttendanceSyncService $attendance,
        float $pollSeconds,
    ): void {
        $device = $devices->get($deviceId);
        $session = $this->sessions[$deviceId];

        try {
            $record = $session->attendance()->nextRealtimePunch($pollSeconds);
        } catch (DeviceException $e) {
            $this->warn("[{$device->name}] link error: {$e->getMessage()} — retrying in ".self::RETRY_AFTER.'s.');
            $this->drop($deviceId);

            return;
        }

        if ($record === null) {
            return;
        }

        // Scope writes to the device's own tenant. Without this the global scope
        // would attribute a punch to whichever tenant happened to be set last.
        Tenancy::set($device->tenant_id);

        try {
            $log = $attendance->recordRealtimePunch($device, $record);
        } finally {
            Tenancy::spanAllTenants();
        }

        if ($log === null) {
            $this->warn("[{$device->name}] a realtime push would not decode — archived, not counted.");

            return;
        }

        if (! $log->wasRecentlyCreated) {
            return; // already imported by a sync; not worth a line of output
        }

        $this->line(sprintf(
            '[%s] punch: %s at %s',
            $device->name,
            $record['user_id'] ?? '?',
            $record['timestamp']->format('Y-m-d H:i:s'),
        ));
    }

    private function connect(BiometricDeviceDriver $driver, Device $device): void
    {
        /*
         * Capability gate, per rule 9: a terminal that cannot stream events must be
         * skipped, not retried forever. Levels below `implemented` mean the library
         * has no realtime path for this firmware.
         */
        if (! $driver->capabilities($device)->supports(Capability::ATTENDANCE_REALTIME)) {
            $this->warn("Skipping {$device->name}: no realtime event stream on this firmware.");
            $this->nextRetryAt[$device->id] = PHP_INT_MAX;

            return;
        }

        try {
            $session = $driver->open($device);
            $session->attendance()->listen();

            $this->sessions[$device->id] = $session;
            unset($this->nextRetryAt[$device->id]);

            $this->info("Connected to {$device->name} ({$device->ip_address}).");
        } catch (DeviceException $e) {
            $this->warn("Could not connect to {$device->name}: {$e->getMessage()} — retrying in ".self::RETRY_AFTER.'s.');
            $this->nextRetryAt[$device->id] = time() + self::RETRY_AFTER;
        }
    }

    private function drop(int $deviceId): void
    {
        try {
            $this->sessions[$deviceId]->close();
        } catch (\Throwable) {
            // Already gone. Nothing to salvage, and the reconnect is what matters.
        }

        unset($this->sessions[$deviceId]);
        $this->nextRetryAt[$deviceId] = time() + self::RETRY_AFTER;
    }
}
