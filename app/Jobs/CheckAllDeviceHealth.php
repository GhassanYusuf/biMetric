<?php

namespace App\Jobs;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled fan-out: queues a health check for every addressable device, across
 * every tenant.
 *
 * Deliberately does NOT ping devices itself. One long job sequentially polling a
 * fleet would take as long as the slowest terminal times out, and a single
 * unreachable device would delay every check behind it. Instead each device gets its
 * own command row, which also means each has its own retry history and its own audit
 * trail.
 *
 * Runs unscoped by design — the scheduler has no tenant — and restores tenancy per
 * device so the created command rows land in the right tenant.
 */
class CheckAllDeviceHealth implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 120;

    public function handle(): void
    {
        // No tenant is resolved in a scheduled context, so query across all of them
        // and re-scope per device below.
        $devices = Device::withoutGlobalScopes()
            ->where('protocol', Device::PROTOCOL_BINARY)
            ->where('is_active', true)
            ->get();

        foreach ($devices as $device) {
            if ($device->tenant_id !== null) {
                Tenancy::set($device->tenant_id);
            }

            /*
             * Skip a device that already has a health check waiting. A slow terminal
             * plus a five-minute schedule would otherwise pile up checks faster than
             * they drain, and the queue would fill with stale duplicates.
             */
            $pending = DeviceCommand::withoutGlobalScopes()
                ->where('device_id', $device->id)
                ->where('command', DeviceCommand::HEALTH_CHECK)
                ->whereIn('status', [DeviceCommand::STATUS_QUEUED, DeviceCommand::STATUS_RUNNING])
                ->exists();

            if ($pending) {
                continue;
            }

            // tap() the helper, not ->tap(): a Model has no tap() of its own, so the
            // method call fell through to a query Builder, $command->id threw, and the
            // row was left `queued` with no job behind it — which then made every later
            // run skip the terminal (casebook DC-024).
            tap(DeviceCommand::create([
                'tenant_id' => $device->tenant_id,
                'device_id' => $device->id,
                'command' => DeviceCommand::HEALTH_CHECK,
                'payload' => [],
                'status' => DeviceCommand::STATUS_QUEUED,
                // A health check is a probe, not a task worth hammering: one attempt.
                // The next scheduled run is the retry.
                'max_attempts' => 1,
                'available_at' => now(),
            ]), fn ($command) => ProcessDeviceCommand::dispatch($command->id));

            Tenancy::clear();
        }
    }
}
