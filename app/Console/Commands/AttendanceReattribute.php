<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\Attendance\PunchAttribution;
use Illuminate\Console\Command;

/**
 * Re-answers "whose punch is this?" for attendance already stored, for one device or
 * all of them. The rules live in PunchAttribution, which claiming a roster entry also
 * calls for that entry's PIN (DC-031); this is the by-hand, whole-device form, for
 * after a merge, an identity reset or a corrected mapping window.
 */
class AttendanceReattribute extends Command
{
    protected $signature = 'attendance:reattribute
                            {device? : Device id or name; omit for every device}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Re-attribute stored punches to employees using the mapping in force at each punch';

    public function handle(PunchAttribution $attribution): int
    {
        $devices = $this->resolveDevices();

        if ($devices->isEmpty()) {
            $this->error('No matching device.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        foreach ($devices as $device) {
            $counts = $attribution->relink($device, null, $dryRun);

            $this->line(sprintf(
                '  %-14s %7d punch(es): %d newly attributed, %d cleared, %d still unmatched',
                $device->name,
                $counts['total'],
                $counts['attributed'],
                $counts['cleared'],
                $counts['unmatched'],
            ));
        }

        if ($dryRun) {
            $this->newLine();
            $this->line('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    private function resolveDevices()
    {
        $arg = $this->argument('device');

        if ($arg === null) {
            return Device::withoutGlobalScopes()->orderBy('id')->get();
        }

        return Device::withoutGlobalScopes()
            ->where('id', is_numeric($arg) ? (int) $arg : 0)
            ->orWhere('name', $arg)
            ->get();
    }
}
