<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\Devices\DeviceUserSyncService;
use Illuminate\Console\Command;

/**
 * Creates one employee per unclaimed person already on a terminal.
 *
 * The bulk onboarding path: use it when the people on a device are not in the system
 * at all. It matches nothing against existing records — a device PIN is not evidence
 * that two people are the same human — so onboarding two terminals that share staff
 * produces duplicates ON PURPOSE. They are collapsed afterwards at the Person layer,
 * by national id, which is the only identifier that can honestly make that claim.
 *
 * Writes nothing to the device.
 */
class DeviceImportRoster extends Command
{
    protected $signature = 'device:import-roster {device : Device id or name}';

    protected $description = 'Create employees from a device\'s unclaimed roster entries (no device write)';

    public function handle(DeviceUserSyncService $users): int
    {
        $arg = $this->argument('device');

        $device = Device::withoutGlobalScopes()
            ->where('id', is_numeric($arg) ? (int) $arg : 0)
            ->orWhere('name', $arg)
            ->first();

        if ($device === null) {
            $this->error('No matching device.');

            return self::FAILURE;
        }

        $result = $users->importFromDevice($device);
        $this->info($result['message']);

        return self::SUCCESS;
    }
}
