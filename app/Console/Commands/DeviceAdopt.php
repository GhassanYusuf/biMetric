<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DeviceRosterEntry;
use App\Services\Devices\DeviceAdoptionService;
use Illuminate\Console\Command;

/**
 * The two steps between reading a roster and unlocking writes.
 *
 * `device:import-roster` creates one employee per unclaimed entry — the bulk path for
 * onboarding a terminal whose people are not in the system at all. `device:adopt`
 * unlocks writes, and refuses while anything is still undecided.
 */
class DeviceAdopt extends Command
{
    protected $signature = 'device:adopt
                            {device : Device id or name}
                            {--ignore-all= : First mark every remaining unclaimed entry as ignored, with this reason}';

    protected $description = 'Unlock device writes, once every roster entry has been claimed or ignored';

    public function handle(DeviceAdoptionService $adoption): int
    {
        $device = $this->resolve();

        if ($device === null) {
            $this->error('No matching device.');

            return self::FAILURE;
        }

        if ($reason = $this->option('ignore-all')) {
            $entries = DeviceRosterEntry::query()
                ->where('device_id', $device->id)
                ->where('claim_status', DeviceRosterEntry::UNCLAIMED)
                ->get();

            foreach ($entries as $entry) {
                $adoption->ignore($entry, $reason);
            }

            $this->line("Ignored {$entries->count()} entr".($entries->count() === 1 ? 'y' : 'ies').": {$reason}");
        }

        try {
            $result = $adoption->finishAdoption($device);
            $this->info($result['message']);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function resolve(): ?Device
    {
        $arg = $this->argument('device');

        return Device::withoutGlobalScopes()
            ->where('id', is_numeric($arg) ? (int) $arg : 0)
            ->orWhere('name', $arg)
            ->first();
    }
}
