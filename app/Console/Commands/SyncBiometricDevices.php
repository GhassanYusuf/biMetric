<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\Zkteco\DeviceSyncService;
use App\Services\Zkteco\ZktecoException;
use Illuminate\Console\Command;

class SyncBiometricDevices extends Command
{
    protected $signature = 'app:sync-biometric-devices';

    protected $description = 'Poll every active biometric device over the network and import new attendance punches';

    public function handle(DeviceSyncService $sync): int
    {
        $devices = Device::query()->where('is_active', true)->get();

        if ($devices->isEmpty()) {
            $this->info('No active devices to sync.');

            return self::SUCCESS;
        }

        foreach ($devices as $device) {
            $this->line("Syncing {$device->name} ({$device->ip_address})...");

            try {
                $count = $sync->syncAttendance($device);
                $this->info("  imported {$count} new punch(es)");
            } catch (ZktecoException $e) {
                $this->error("  failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
