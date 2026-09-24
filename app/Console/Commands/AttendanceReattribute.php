<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\EmployeeDeviceMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-answers "whose punch is this?" for attendance already stored.
 *
 * WHY IT IS NEEDED. A punch is stored with the raw device_user_id the terminal gave
 * it, and attributed to an employee at import time. But attribution depends on the
 * mapping, and the mapping is a HUMAN DECISION that usually arrives later: a device
 * is read, its punches sync as unmatched, and an operator claims the roster entries
 * that afternoon. Without this, those punches stay unattributed forever and the claim
 * only affects the future — which would make claiming an existing terminal nearly
 * pointless, since all its history is in the past.
 *
 * It is safe to re-run. It only ever recomputes employee_id from the mappings in
 * force at each punch's own timestamp; it never invents, moves or deletes a punch, and
 * the raw device_user_id it recomputes from is never altered.
 *
 * A punch that still resolves to nobody is LEFT UNATTRIBUTED rather than guessed at
 * (rule 10). Unmatched is a true statement about what we know; a plausible employee
 * id is not.
 */
class AttendanceReattribute extends Command
{
    protected $signature = 'attendance:reattribute
                            {device? : Device id or name; omit for every device}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Re-attribute stored punches to employees using the mapping in force at each punch';

    public function handle(): int
    {
        $devices = $this->resolveDevices();

        if ($devices->isEmpty()) {
            $this->error('No matching device.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        foreach ($devices as $device) {
            $this->reattribute($device, $dryRun);
        }

        if ($dryRun) {
            $this->newLine();
            $this->line('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    private function reattribute(Device $device, bool $dryRun): void
    {
        $windows = $this->windows($device);

        $changed = 0;
        $cleared = 0;
        $stillUnmatched = 0;
        $total = 0;

        /*
         * Chunked by id rather than paginated: the query's own ORDER BY is stable and
         * the updates below change a column the filter does not use, so pages cannot
         * shift underneath the cursor.
         */
        AttendanceLog::query()
            ->where('device_id', $device->id)
            ->orderBy('id')
            ->chunkById(2000, function ($logs) use ($windows, $dryRun, &$changed, &$cleared, &$stillUnmatched, &$total) {
                $updates = [];

                foreach ($logs as $log) {
                    $total++;

                    $resolved = $this->employeeAt($windows, (string) $log->device_user_id, $log->punched_at);
                    /*
                     * Normalised to ?int before comparing. SQL Server returns ids as
                     * strings ("1413") where SQLite returned ints, so a strict compare
                     * called every already-correct punch a change — 9,292 of IT Room's
                     * 10,733 on 2026-09-23, when about 3 really were.
                     */
                    $current = $log->employee_id === null ? null : (int) $log->employee_id;

                    if ($resolved === $current) {
                        if ($resolved === null) {
                            $stillUnmatched++;
                        }

                        continue;
                    }

                    if ($resolved === null) {
                        // Our previous answer is no longer supported by any mapping —
                        // the claim was withdrawn, or the window was corrected. Keeping
                        // it would be asserting something we can no longer justify.
                        $cleared++;
                    } else {
                        $changed++;
                    }

                    $updates[$log->id] = $resolved;
                }

                if (! $dryRun && $updates !== []) {
                    foreach (array_chunk($updates, 500, true) as $chunk) {
                        foreach ($chunk as $id => $employeeId) {
                            DB::table('attendance_logs')->where('id', $id)->update(['employee_id' => $employeeId]);
                        }
                    }
                }
            });

        $this->line(sprintf(
            '  %-14s %7d punch(es): %d newly attributed, %d cleared, %d still unmatched',
            $device->name,
            $total,
            $changed,
            $cleared,
            $stillUnmatched,
        ));
    }

    /**
     * Every mapping window this device has had, by PIN.
     *
     * @return array<string,list<array{employee_id:int,from:?int,to:?int}>>
     */
    private function windows(Device $device): array
    {
        $grouped = [];

        foreach (
            EmployeeDeviceMap::query()
                ->where('device_id', $device->id)
                ->get(['employee_id', 'device_user_id', 'valid_from', 'valid_to']) as $row
        ) {
            $grouped[(string) $row->device_user_id][] = [
                'employee_id' => (int) $row->employee_id,
                'from' => $row->valid_from?->getTimestamp(),
                'to' => $row->valid_to?->getTimestamp(),
            ];
        }

        return $grouped;
    }

    /** @param array<string,list<array{employee_id:int,from:?int,to:?int}>> $windows */
    private function employeeAt(array $windows, string $pin, \DateTimeInterface $punchedAt): ?int
    {
        $at = $punchedAt->getTimestamp();

        foreach ($windows[$pin] ?? [] as $window) {
            if ($window['from'] !== null && $at < $window['from']) {
                continue;
            }

            if ($window['to'] !== null && $at >= $window['to']) {
                continue;
            }

            return $window['employee_id'];
        }

        return null;
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
