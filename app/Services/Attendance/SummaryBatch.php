<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\AttendanceWaiver;
use App\Models\Employee;
use App\Models\Holiday;
use App\Support\AttendanceRules;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Everything AttendanceSummaryService needs to summarise a SET of employees over
 * one date range, fetched once instead of once per person.
 *
 * WHY THIS EXISTS
 * ---------------
 * The payroll report summarised 357 employees by calling the service 357 times.
 * Measured on 2026-09-15 that was 1,072 queries and 694 ms, and it split into two
 * separate problems that look like one:
 *
 *   ~119 ms  SQL — three identical-shaped queries per employee, one of which (the
 *            holiday list) did not even vary by employee.
 *   ~575 ms  PHP — Carbon::parse() called four times per work day per employee to
 *            rebuild the SAME shift marks. Thirty days across 357 people is more
 *            than forty thousand date parses to produce thirty distinct answers.
 *
 * Batching the queries alone would have fixed the smaller half. So this object
 * carries both: the rows, and the per-day shift arithmetic that does not depend on
 * who is being summarised.
 *
 * SINGLE-EMPLOYEE CALLS GO THROUGH IT TOO. `summary()` builds a batch of one when
 * it is not given a batch, so there is exactly one code path. A "fast path" for
 * reports and a "simple path" for the employee screen is how a total stops
 * reconciling with the days behind it — which is the one thing this whole area of
 * the app exists to guarantee.
 *
 * It is a snapshot: everything is read at construction. That is deliberate and it
 * is why it is passed as an argument rather than cached on the service — a
 * long-lived queue worker holding a stateful service would serve one job's
 * attendance to the next.
 */
final class SummaryBatch
{
    /**
     * @param  array<int, Collection<int, AttendanceLog>>  $logsByEmployee
     * @param  Collection<string, Holiday>  $holidays  keyed Y-m-d
     * @param  array<int, Collection<string, AttendanceWaiver>>  $waiversByEmployee  each keyed Y-m-d
     * @param  list<array{key:string, expectedStart:Carbon, expectedEnd:Carbon, windowStart:Carbon, windowEnd:Carbon}>  $days
     */
    private function __construct(
        private readonly array $logsByEmployee,
        private readonly Collection $holidays,
        private readonly array $waiversByEmployee,
        public readonly array $days,
        public readonly float $shiftLengthHours,
        public readonly AttendanceRules $rules,
    ) {}

    /**
     * Reads every employee's punches and waivers for the window in three queries,
     * and works out the shift marks for each work day once.
     *
     * @param  iterable<Employee>  $employees
     */
    public static function for(iterable $employees, Carbon $fromDate, Carbon $toDate): self
    {
        $ids = Collection::make($employees)->pluck('id')->all();

        $fromDay = $fromDate->format('Y-m-d');
        $toDay = $toDate->format('Y-m-d');

        /*
         * Only the two columns the computation reads. The punch rows are the bulk
         * of this object and `select *` would carry raw device ids, verify methods
         * and timestamps for tens of thousands of rows that nothing looks at.
         *
         * Ordered globally rather than per employee: groupBy preserves the order it
         * receives, so one ORDER BY gives every group its chronological order, which
         * the first/last-punch logic depends on.
         */
        $logsByEmployee = AttendanceLog::query()
            ->whereIn('employee_id', $ids)
            ->whereBetween('punched_at', [$fromDate->copy()->setTimezone('UTC'), $toDate->copy()->setTimezone('UTC')])
            ->orderBy('punched_at')
            ->get(['employee_id', 'punched_at'])
            ->groupBy('employee_id')
            ->all();

        // Not per employee — it never was. It was simply re-read 357 times.
        $holidays = Holiday::query()
            ->whereBetween('date', [$fromDay, $toDay])
            ->get()
            ->keyBy(fn (Holiday $h) => $h->date->format('Y-m-d'));

        $waiversByEmployee = AttendanceWaiver::query()
            ->whereIn('employee_id', $ids)
            ->whereBetween('date', [$fromDay, $toDay])
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $rows) => $rows->keyBy(fn (AttendanceWaiver $w) => $w->date->format('Y-m-d')))
            ->all();

        // Read once: every day and every employee in this batch is judged by the same rules.
        $rules = AttendanceRules::current();
        [$days, $shiftLengthHours] = self::calendar($fromDate, $toDate, $rules);

        return new self($logsByEmployee, $holidays, $waiversByEmployee, $days, $shiftLengthHours, $rules);
    }

    /**
     * The work days in the window, each with its shift marks already resolved.
     *
     * These depend only on the date and the configured shift, so they are the same
     * for every employee in the report — which is exactly why recomputing them per
     * person cost more than every database query in the request put together.
     *
     * @return array{0:list<array{key:string, expectedStart:Carbon, expectedEnd:Carbon, windowStart:Carbon, windowEnd:Carbon}>, 1:float}
     */
    private static function calendar(Carbon $fromDate, Carbon $toDate, AttendanceRules $rules): array
    {
        $tz = config('attendance.timezone');
        $shiftStart = $rules->shiftStart;
        $shiftEnd = $rules->shiftEnd;
        $workDays = $rules->workDays;

        $days = [];

        foreach (CarbonPeriod::create($fromDate, $toDate) as $date) {
            if (! in_array($date->dayOfWeek, $workDays, true)) {
                continue;
            }

            $key = $date->format('Y-m-d');

            $days[] = [
                'key' => $key,
                // The thresholds are part of "were they late / did they stay late",
                // not part of the payroll window — keep the two pairs distinct.
                'expectedStart' => Carbon::parse("{$key} {$shiftStart}", $tz)->addMinutes($rules->lateGrace),
                'expectedEnd' => Carbon::parse("{$key} {$shiftEnd}", $tz)->addMinutes($rules->leaveLateAfter),
                'windowStart' => Carbon::parse("{$key} {$shiftStart}", $tz),
                'windowEnd' => Carbon::parse("{$key} {$shiftEnd}", $tz),
            ];
        }

        $shiftLengthHours = round(
            Carbon::parse($shiftStart)->diffInMinutes(Carbon::parse($shiftEnd)) / 60,
            1,
        );

        return [$days, $shiftLengthHours];
    }

    /** @return Collection<int, AttendanceLog> */
    public function logsFor(int $employeeId): Collection
    {
        // Empty, never null: an employee with no punches in the window is an
        // ordinary case (it is what "absent every day" looks like), not a gap.
        return $this->logsByEmployee[$employeeId] ?? Collection::make();
    }

    public function holidayOn(string $day): ?Holiday
    {
        return $this->holidays->get($day);
    }

    public function waiverOn(int $employeeId, string $day): ?AttendanceWaiver
    {
        return ($this->waiversByEmployee[$employeeId] ?? null)?->get($day);
    }
}
