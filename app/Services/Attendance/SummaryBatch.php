<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\AttendanceWaiver;
use App\Models\CompanyAttendanceRule;
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
     * @param  Collection<string, Collection<int, Holiday>>  $holidays  keyed Y-m-d; a day may hold one holiday per company
     * @param  array<int, Collection<string, AttendanceWaiver>>  $waiversByEmployee  each keyed Y-m-d
     * @param  array<string, array{rules:AttendanceRules, days:list<array{key:string, expectedStart:Carbon, expectedEnd:Carbon, windowStart:Carbon, windowEnd:Carbon}>, hours:float}>  $profiles
     *                                                                                                                                                                            keyed 'default' or 'company:N'
     * @param  array<int, array{profile:string, company:?int}>  $employees  keyed employee id
     */
    private function __construct(
        private readonly array $logsByEmployee,
        private readonly Collection $holidays,
        private readonly array $waiversByEmployee,
        private readonly array $profiles,
        private readonly array $employees,
    ) {}

    /**
     * Reads every employee's punches and waivers for the window in three queries,
     * and works out the shift marks for each work day once PER RULE SET — the
     * system default, plus one per company in the batch that keeps its own rules
     * (company_attendance_rules, 2026-11-22). Still one calendar per rule set,
     * never one per person.
     *
     * @param  iterable<Employee>  $employees
     */
    public static function for(iterable $employees, Carbon $fromDate, Carbon $toDate): self
    {
        $employees = Collection::make($employees);
        $ids = $employees->pluck('id')->all();

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
        // An overnight shift's last work day runs into the next morning: read that far too. Day shifts read
        // exactly the window, as always. The summary drops any punch whose work day falls outside it.
        $overnight = self::anyOvernight($employees);
        $logsTo = $overnight ? $toDate->copy()->addDay() : $toDate;

        $logsByEmployee = AttendanceLog::query()
            ->whereIn('employee_id', $ids)
            ->whereBetween('punched_at', [$fromDate->copy()->setTimezone('UTC'), $logsTo->copy()->setTimezone('UTC')])
            ->orderBy('punched_at')
            ->get(['employee_id', 'punched_at'])
            ->groupBy('employee_id')
            ->all();

        // Not per employee — read once with the companies each applies to (none = every company).
        $holidays = Holiday::query()
            ->with('companies:id')
            ->whereBetween('date', [$fromDay, $toDay])
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Holiday $h) => $h->date->format('Y-m-d'));

        $waiversByEmployee = AttendanceWaiver::query()
            ->whereIn('employee_id', $ids)
            ->whereBetween('date', [$fromDay, $toDay])
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $rows) => $rows->keyBy(fn (AttendanceWaiver $w) => $w->date->format('Y-m-d')))
            ->all();

        // One rule set per company that has its own; everyone else is judged by the default.
        $companyIds = $employees->pluck('company_id')->filter()->map(fn ($c) => (int) $c)->unique()->values()->all();
        $own = $companyIds === [] ? collect() : CompanyAttendanceRule::query()->whereIn('company_id', $companyIds)->get()->keyBy('company_id');

        $profile = function (AttendanceRules $rules) use ($fromDate, $toDate): array {
            [$days, $hours] = self::calendar($fromDate, $toDate, $rules);

            return ['rules' => $rules, 'days' => $days, 'hours' => $hours];
        };
        $profiles = ['default' => $profile(AttendanceRules::current())];
        foreach ($own as $companyId => $row) {
            $profiles['company:'.$companyId] = $profile(AttendanceRules::fromCompanyRow($row));
        }

        $map = [];
        foreach ($employees as $e) {
            $company = $e->company_id === null ? null : (int) $e->company_id;
            $map[(int) $e->id] = [
                'profile' => $company !== null && isset($profiles['company:'.$company]) ? 'company:'.$company : 'default',
                'company' => $company,
            ];
        }

        return new self($logsByEmployee, $holidays, $waiversByEmployee, $profiles, $map);
    }

    /**
     * The work days in the window, each with its shift marks already resolved.
     *
     * These depend only on the date and the rule set, so they are the same for
     * every employee judged by that set — which is exactly why recomputing them per
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

            // An overnight shift (17:00–02:00) ends on the next calendar day.
            $endDay = $rules->isOvernight() ? $date->copy()->addDay()->format('Y-m-d') : $key;

            $days[] = [
                'key' => $key,
                // The thresholds are part of "were they late / did they stay late",
                // not part of the payroll window — keep the two pairs distinct.
                'expectedStart' => Carbon::parse("{$key} {$shiftStart}", $tz)->addMinutes($rules->lateGrace),
                'expectedEnd' => Carbon::parse("{$endDay} {$shiftEnd}", $tz)->addMinutes($rules->leaveLateAfter),
                'windowStart' => Carbon::parse("{$key} {$shiftStart}", $tz),
                'windowEnd' => Carbon::parse("{$endDay} {$shiftEnd}", $tz),
            ];
        }

        $shiftLengthHours = round($rules->shiftLengthMinutes() / 60, 1);

        return [$days, $shiftLengthHours];
    }

    /** Whether the default, or the own rules of a company in $employees, is an overnight shift. */
    private static function anyOvernight(Collection $employees): bool
    {
        if (AttendanceRules::current()->isOvernight()) {
            return true;
        }
        $companyIds = $employees->pluck('company_id')->filter()->unique()->values()->all();

        return $companyIds !== [] && CompanyAttendanceRule::query()->whereIn('company_id', $companyIds)->get()
            ->contains(fn (CompanyAttendanceRule $r) => AttendanceRules::fromCompanyRow($r)->isOvernight());
    }

    /** @return array{rules:AttendanceRules, days:list<array<string,mixed>>, hours:float} */
    private function profileOf(int $employeeId): array
    {
        // Someone summarised outside the batch's list (never by design) gets the default, not an error.
        return $this->profiles[$this->employees[$employeeId]['profile'] ?? 'default'];
    }

    /** The rules this employee is judged by: their company's own, else the default. */
    public function rulesFor(int $employeeId): AttendanceRules
    {
        return $this->profileOf($employeeId)['rules'];
    }

    /** @return list<array{key:string, expectedStart:Carbon, expectedEnd:Carbon, windowStart:Carbon, windowEnd:Carbon}> */
    public function daysFor(int $employeeId): array
    {
        return $this->profileOf($employeeId)['days'];
    }

    public function shiftLengthHoursFor(int $employeeId): float
    {
        return $this->profileOf($employeeId)['hours'];
    }

    /**
     * The distinct rule sets the employees in this batch are judged by — one when
     * nobody's company keeps its own. A report printing "the shift" prints the one,
     * or says it varies.
     *
     * @return list<AttendanceRules>
     */
    public function rulesInUse(): array
    {
        $used = array_unique(array_column($this->employees, 'profile')) ?: ['default'];

        return array_values(array_map(fn (string $k) => $this->profiles[$k]['rules'], $used));
    }

    /** @return Collection<int, AttendanceLog> */
    public function logsFor(int $employeeId): Collection
    {
        // Empty, never null: an employee with no punches in the window is an
        // ordinary case (it is what "absent every day" looks like), not a gap.
        return $this->logsByEmployee[$employeeId] ?? Collection::make();
    }

    /**
     * The holiday this employee has on $day: one for every company, or one ticked
     * for their company. Someone in no company has only the every-company ones.
     */
    public function holidayOn(string $day, int $employeeId): ?Holiday
    {
        $company = $this->employees[$employeeId]['company'] ?? null;

        return $this->holidays->get($day)?->first(fn (Holiday $h) => $h->appliesToCompany($company));
    }

    public function waiverOn(int $employeeId, string $day): ?AttendanceWaiver
    {
        return ($this->waiversByEmployee[$employeeId] ?? null)?->get($day);
    }
}
