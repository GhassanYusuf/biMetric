<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\EmployeeDeviceMap;
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
 * Catering (DC-031, 2026-10-25) is the case that made claim() call this itself: its
 * 2,112 punches were read three days before its 21 users were imported, and 2,111 of
 * them stayed on nobody until `attendance:reattribute` was run by hand.
 *
 * It is safe to re-run. It only ever recomputes employee_id from the mappings in
 * force at each punch's own timestamp; it never invents, moves or deletes a punch, and
 * the raw device_user_id it recomputes from is never altered.
 *
 * A punch that still resolves to nobody is LEFT UNATTRIBUTED rather than guessed at
 * (rule 10). Unmatched is a true statement about what we know; a plausible employee
 * id is not.
 */
class PunchAttribution
{
    /**
     * Recomputes employee_id for a device's stored punches, or only those under one
     * PIN.
     *
     * @return array{total:int, attributed:int, cleared:int, unmatched:int}
     */
    public function relink(Device $device, ?string $pin = null, bool $dryRun = false): array
    {
        $windows = $this->windows($device, $pin);

        $counts = ['total' => 0, 'attributed' => 0, 'cleared' => 0, 'unmatched' => 0];

        /*
         * Chunked by id rather than paginated: the query's own ORDER BY is stable and
         * the updates below change a column the filter does not use, so pages cannot
         * shift underneath the cursor.
         */
        AttendanceLog::query()
            ->where('device_id', $device->id)
            ->when($pin !== null, fn ($q) => $q->where('device_user_id', $pin))
            ->orderBy('id')
            ->chunkById(2000, function ($logs) use ($windows, $dryRun, &$counts) {
                $updates = [];

                foreach ($logs as $log) {
                    $counts['total']++;

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
                            $counts['unmatched']++;
                        }

                        continue;
                    }

                    if ($resolved === null) {
                        // Our previous answer is no longer supported by any mapping —
                        // the claim was withdrawn, or the window was corrected. Keeping
                        // it would be asserting something we can no longer justify.
                        $counts['cleared']++;
                    } else {
                        $counts['attributed']++;
                    }

                    $updates[$log->id] = $resolved;
                }

                if (! $dryRun) {
                    // updated_at moves so the attendance live stamp does, and open
                    // Attendance screens show the new names without a reload (rule 18).
                    $now = now();

                    foreach ($updates as $id => $employeeId) {
                        DB::table('attendance_logs')->where('id', $id)->update(['employee_id' => $employeeId, 'updated_at' => $now]);
                    }
                }
            });

        return $counts;
    }

    /**
     * Every mapping window this device has had, by PIN, in precedence order.
     *
     * Also the bulk sync's source (AttendanceSyncService), so a punch is given to the
     * same person whether it is attributed on import or re-linked later.
     *
     * PRECEDENCE, when two windows cover one punch: a CLOSED window (an earlier holder
     * with a known end) beats an open-ended one, then the later start wins. The
     * overlap is normal: a PIN once held by a leaver and later claimed off the
     * terminal gets an unbounded start (DeviceAdoptionService::claim(), because we do
     * not know since when the person was enrolled), and that must not take the
     * leaver's punches. Before 2026-10-25 the first row the database returned won
     * (DC-031). EmployeeDeviceMap::scopeByPrecedence() is the same order in SQL.
     *
     * @return array<string,list<array{employee_id:int,from:?int,to:?int}>>
     */
    public function windows(Device $device, ?string $pin = null): array
    {
        $grouped = [];

        foreach (
            EmployeeDeviceMap::query()
                ->where('device_id', $device->id)
                ->when($pin !== null, fn ($q) => $q->where('device_user_id', $pin))
                ->get(['employee_id', 'device_user_id', 'valid_from', 'valid_to']) as $row
        ) {
            $grouped[(string) $row->device_user_id][] = [
                'employee_id' => (int) $row->employee_id,
                'from' => $row->valid_from?->getTimestamp(),
                'to' => $row->valid_to?->getTimestamp(),
            ];
        }

        foreach ($grouped as &$list) {
            usort($list, fn (array $a, array $b) => [$a['to'] === null, -($a['from'] ?? PHP_INT_MIN)]
                <=> [$b['to'] === null, -($b['from'] ?? PHP_INT_MIN)]);
        }
        unset($list);

        return $grouped;
    }

    /**
     * Who held this PIN when the punch was made, or null when nobody did. Null is
     * honest: the punch keeps its raw device_user_id and can be linked later.
     *
     * @param  array<string,list<array{employee_id:int,from:?int,to:?int}>>  $windows  from windows()
     */
    public function employeeAt(array $windows, string $pin, \DateTimeInterface $punchedAt): ?int
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
}
