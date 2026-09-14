<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\Devices\DeviceCommandBus;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Queues an attendance sync for every active device.
 *
 * WHAT CHANGED: this used to call the legacy DeviceSyncService inline, blocking the
 * scheduler on each terminal in turn and writing attendance through a second import
 * path with its own dedupe and timezone handling. It now dispatches through
 * DeviceCommandBus like every other device operation, so one unreachable terminal
 * cannot hold up the rest, retries are owned by the command row, and only one
 * command runs against a device at a time (rule 11).
 *
 * It queues work; it does not wait for it. The outcome lands on the DeviceCommand
 * row and on MQTT.
 *
 * --full forces a complete re-read. See the note in handle(): it changes what is
 * IMPORTED, not what is transferred, because the protocol has no partial read.
 *
 * NOTE ON CADENCE: routes/console.php still runs this every five minutes AND
 * dispatches the same SYNC_ATTENDANCE command hourly. Both now go through the same
 * bus, so they are safe — the unique punch index dedupes and the command bus
 * serialises per device — but they are redundant. Pick one cadence.
 */
class SyncBiometricDevices extends Command
{
    protected $signature = 'app:sync-biometric-devices
                            {--device=* : Specific device ids (default: every active device)}
                            {--full : Ignore the watermark and reconsider every record on the terminal}';

    protected $description = 'Queue an attendance sync for every active biometric device';

    public function handle(DeviceCommandBus $bus): int
    {
        // Console context: no request, so no resolved tenant. Read across all of
        // them and set the tenant per device before dispatching, so the command row
        // is written under the tenant that owns the device.
        Tenancy::spanAllTenants();

        $query = Device::query()->where('is_active', true);

        if ($ids = $this->option('device')) {
            $query->whereIn('id', $ids);
        }

        /*
         * --full does NOT change what comes off the terminal.
         *
         * ATTLOG_RRQ always returns the whole table — this protocol has no "since
         * X" — so every sync already transfers everything. The flag only stops the
         * watermark filtering those records out before import, which is what makes
         * it the way to recover after a decoder fix. It costs database work, not
         * network, and the unique punch index still prevents duplicates.
         */
        $full = (bool) $this->option('full');
        $mode = $full ? 'FULL' : 'incremental';

        $devices = $query->get();

        if ($devices->isEmpty()) {
            $this->info('No active devices to sync.');

            return self::SUCCESS;
        }

        $queued = 0;

        foreach ($devices as $device) {
            Tenancy::set($device->tenant_id);

            try {
                $command = $bus->dispatch($device, DeviceCommand::SYNC_ATTENDANCE, ['full' => $full]);
                $queued++;

                $this->line("Queued {$mode} sync for {$device->name} (command #{$command->id}).");
            } catch (\Throwable $e) {
                // One device that cannot be queued must not stop the others.
                $this->error("  could not queue {$device->name}: {$e->getMessage()}");
            } finally {
                Tenancy::spanAllTenants();
            }
        }

        $this->info("Queued {$queued} of {$devices->count()} device(s) for a {$mode} sync.");

        if ($full) {
            $this->comment('A full re-read reconsiders every record on each terminal and takes considerably longer than a normal sync.');
        }

        // Queued, not done. Nothing happens without a worker, and a scheduler that
        // silently queued into a void is how this goes unnoticed for a week.
        $this->comment('These are queued commands — they need `php artisan queue:work` running to execute.');

        return self::SUCCESS;
    }
}
