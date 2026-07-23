<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\Zkteco\DeviceSyncService;
use App\Services\Zkteco\ZktecoDevice;
use App\Services\Zkteco\ZktecoException;
use Illuminate\Console\Command;

/**
 * Stays connected to ZKTeco device(s) and records attendance punches live as
 * they happen (via the device's own realtime event stream), instead of
 * waiting for the manual "Read from Device" button or the 5-minute
 * scheduled sync to pick them up. Those two remain the fallback/backfill
 * path - this command is purely additive; if it isn't running (or a device
 * connection drops), attendance sync keeps working exactly as it did
 * before this command existed.
 *
 * Only devices with protocol=binary support this (the CSL/HTTP web-export
 * devices have no realtime mechanism); CSL devices are skipped with a note.
 *
 * No pcntl/multi-process on Windows, so multiple devices are handled in one
 * process via stream_select() across each device's raw socket.
 */
class ListenZkteco extends Command
{
    protected $signature = 'zkteco:listen {--device=* : Specific device IDs to listen on (default: all active binary-protocol devices)}';

    protected $description = 'Stay connected to ZKTeco device(s) and record attendance punches live as they happen';

    /** @var array<int, ZktecoDevice> */
    private array $connections = [];

    /** @var array<int, int> device id => unix timestamp of next allowed reconnect attempt */
    private array $nextRetryAt = [];

    public function handle(DeviceSyncService $sync): int
    {
        $query = Device::query()->where('is_active', true)->where('protocol', Device::PROTOCOL_BINARY);

        if ($ids = $this->option('device')) {
            $query->whereIn('id', $ids);
        }

        $devices = $query->get()->keyBy('id');

        $skipped = Device::query()
            ->where('is_active', true)
            ->where('protocol', Device::PROTOCOL_CSL_HTTP)
            ->pluck('name');

        foreach ($skipped as $name) {
            $this->warn("Skipping {$name}: CSL/HTTP-protocol devices have no realtime event stream.");
        }

        if ($devices->isEmpty()) {
            $this->error('No active binary-protocol devices to listen on.');

            return self::FAILURE;
        }

        foreach ($devices as $device) {
            $this->connectDevice($device);
        }

        $this->info('Listening for realtime punches on: ' . $devices->pluck('name')->join(', '));
        $this->info('(Manual sync and the scheduled sync remain the fallback if this stops - Ctrl+C to exit.)');

        while (true) {
            foreach ($devices as $device) {
                if (!isset($this->connections[$device->id]) && ($this->nextRetryAt[$device->id] ?? 0) <= time()) {
                    $this->connectDevice($device);
                }
            }

            if ($this->connections === []) {
                sleep(5);

                continue;
            }

            $this->pollOnce($devices, $sync);
        }
    }

    private function pollOnce($devices, DeviceSyncService $sync): void
    {
        $read = array_map(fn (ZktecoDevice $zk) => $zk->socket(), $this->connections);
        $write = $except = [];

        $changed = @stream_select($read, $write, $except, 1);

        if ($changed === false || $changed === 0) {
            return;
        }

        foreach ($this->connections as $deviceId => $zk) {
            if (!in_array($zk->socket(), $read, true)) {
                continue;
            }

            $device = $devices->get($deviceId);

            try {
                $record = $zk->readNextRealtimeAttendance();
            } catch (\Throwable $e) {
                $this->warn("[{$device->name}] connection error: {$e->getMessage()} - will retry in 30s.");
                unset($this->connections[$deviceId]);
                $this->nextRetryAt[$deviceId] = time() + 30;

                continue;
            }

            if ($record === null) {
                continue;
            }

            $log = $sync->storeRealtimePunch($device, $record);

            if ($log !== null) {
                $this->line("[{$device->name}] punch recorded: {$record['user_id']} at {$record['timestamp']->format('Y-m-d H:i:s')}");
            }
        }
    }

    private function connectDevice(Device $device): void
    {
        try {
            $zk = ZktecoDevice::fromModel($device);
            $zk->connect();
            $zk->beginRealtimeListening();

            $this->connections[$device->id] = $zk;
            unset($this->nextRetryAt[$device->id]);

            $this->info("Connected to {$device->name} ({$device->ip_address}).");
        } catch (ZktecoException $e) {
            $this->warn("Could not connect to {$device->name}: {$e->getMessage()} - retrying in 30s.");
            $this->nextRetryAt[$device->id] = time() + 30;
        }
    }
}
