<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DeviceRosterEntry;
use App\Services\Devices\DeviceAdoptionService;
use Illuminate\Console\Command;

/**
 * Reads who a terminal already holds, and shows what is still undecided.
 *
 * READ-ONLY. This is the first step of adoption and the only one that touches the
 * hardware, and it cannot change anything on it.
 */
class DeviceRoster extends Command
{
    protected $signature = 'device:roster
                            {device? : Device id or name; omit for every active device}
                            {--list : Show the stored roster without contacting the device}';

    protected $description = 'Read a terminal\'s existing user roster into evidence (read-only)';

    public function handle(DeviceAdoptionService $adoption): int
    {
        $devices = $this->resolveDevices();

        if ($devices->isEmpty()) {
            $this->error('No matching device.');

            return self::FAILURE;
        }

        foreach ($devices as $device) {
            $this->newLine();
            $this->line("<comment>{$device->name}</comment> ({$device->ip_address}, {$device->protocol})");

            if (! $this->option('list')) {
                try {
                    $result = $adoption->readRoster($device);
                    $this->info($result['message']);
                } catch (\Throwable $e) {
                    // A device that will not answer is not evidence about its roster
                    // (rule 9). It stays unadopted, which is the safe state.
                    $this->error("Could not read: {$e->getMessage()}");

                    continue;
                }
            }

            $this->renderRoster($device);
        }

        return self::SUCCESS;
    }

    private function renderRoster(Device $device): void
    {
        $entries = DeviceRosterEntry::query()
            ->where('device_id', $device->id)
            ->orderByDeviceUserId()
            ->get();

        if ($entries->isEmpty()) {
            $this->line('  (no roster entries stored)');

            return;
        }

        $this->table(
            ['PIN', 'Name on device', 'Card', 'Fingers', 'Status', 'Claimed by'],
            $entries->map(fn (DeviceRosterEntry $e) => [
                $e->device_user_id,
                $e->device_name ?: '—',
                $e->card_number ?: '—',
                // null is UNKNOWN, not none. Rendering it as 0 would tell an operator
                // somebody is unenrolled when they may not be (rule 10).
                $e->fingerprintCount() === null ? '?' : $e->fingerprintCount(),
                $e->claim_status,
                $e->claimedEmployee?->name ?? '—',
            ])->all(),
        );

        $pending = $entries->where('claim_status', DeviceRosterEntry::UNCLAIMED)->count();

        $this->line($pending === 0
            ? "  Nothing undecided. Ready for: php artisan device:adopt {$device->id}"
            : "  <comment>{$pending} undecided.</comment> Device stays read-only until each is claimed or ignored.");
    }

    private function resolveDevices()
    {
        $arg = $this->argument('device');

        if ($arg === null) {
            return Device::withoutGlobalScopes()->where('is_active', true)->orderBy('id')->get();
        }

        return Device::withoutGlobalScopes()
            ->where('id', is_numeric($arg) ? (int) $arg : 0)
            ->orWhere('name', $arg)
            ->get();
    }
}
