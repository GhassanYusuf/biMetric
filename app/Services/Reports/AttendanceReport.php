<?php

namespace App\Services\Reports;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Attendance\AttendanceSummaryService;
use App\Services\Attendance\EmployeeReportService;
use App\Services\Attendance\SummaryBatch;
use Carbon\Carbon;

/**
 * The company attendance report (2026-10-18): one period, the whole roster or one
 * branch of the department tree, for management and HR. Rendered as a PDF by
 * ExportController::attendanceReport().
 *
 * NOTHING HERE JUDGES A DAY. Every day's verdict is AttendanceSummaryService's,
 * turned into one outcome by EmployeeReportService::outcome(), and every rate is
 * EmployeeReportService::stats() — the exact functions behind the employee report
 * screen. So a person's line in this report, their own report and the payroll
 * summary are three views of one computation and always reconcile.
 *
 * Company, department and weekday figures are stats() over the POOLED person-days of
 * the people in them: "attendance 93%" means 93% of the expected person-days were
 * attended, not an average of per-person percentages (which would weigh someone who
 * joined last week the same as someone here all month).
 *
 * Rule 3 / rule 10 hold: work days before a person was tracked (hire date, else their
 * first punch ever) are `untracked` and count nowhere; a person never tracked at all
 * is listed as such, not counted absent; a rate with no days under it is null.
 *
 * ONE READ. The previous period (same length, immediately before) is needed for the
 * deltas, so a single SummaryBatch covers both, and the window is then sliced.
 */
class AttendanceReport
{
    /** Above this many work days, the daily chart is drawn per week instead. */
    public const DAILY_LIMIT = 62;

    public function __construct(private readonly AttendanceSummaryService $summaries) {}

    /** @return array<string,mixed> */
    public function build(?string $from = null, ?string $to = null, ?int $departmentId = null): array
    {
        $tz = config('attendance.timezone');
        [$fromDate, $toDate] = AttendanceSummaryService::window($from, $to);
        $spanDays = (int) $fromDate->copy()->startOfDay()->diffInDays($toDate->copy()->startOfDay()) + 1;
        $prevFrom = $fromDate->copy()->subDays($spanDays);
        $prevTo = $fromDate->copy()->subDay()->endOfDay();
        $periodFrom = $fromDate->format('Y-m-d');

        $departments = Department::query()->with('parent')->get()->keyBy('id');
        $scopeIds = $departmentId !== null ? Department::subtreeIds($departmentId) : null;

        $employees = Employee::query()
            ->where('is_active', true)
            ->when($scopeIds !== null, fn ($q) => $q->whereIn('department_id', $scopeIds))
            ->orderBy('name')
            ->get();

        $ids = $employees->pluck('id')->all();
        $firstPunch = $ids === [] ? collect() : AttendanceLog::query()
            ->whereIn('employee_id', $ids)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, min(punched_at) as first_punch')
            ->pluck('first_punch', 'employee_id');

        $batch = SummaryBatch::for($employees, $prevFrom, $toDate);
        $rules = $batch->rules;
        $shiftStart = $rules->startMinutes();
        $leftEarlyBefore = $rules->leftEarlyBeforeMinutes();
        $grace = $rules->lateGrace;

        $people = [];
        $neverTracked = [];

        foreach ($employees as $employee) {
            $trackingFrom = EmployeeReportService::trackingFromValues($employee->hired_date, $firstPunch[$employee->id] ?? null);
            $summary = $this->summaries->summary($employee, $prevFrom->format('Y-m-d'), $toDate->format('Y-m-d'), $batch);

            $days = array_map(fn (array $d) => [
                'date' => $d['date'],
                'outcome' => $trackingFrom === null || $d['date'] < $trackingFrom ? 'untracked' : EmployeeReportService::outcome($d, $leftEarlyBefore),
                'first_minutes' => $d['first_minutes'],
                'last_minutes' => $d['last_minutes'],
                'payroll_hours' => $d['payroll_hours'],
            ], $summary['daily']);

            $mine = array_values(array_filter($days, fn ($d) => $d['date'] >= $periodFrom));
            $before = array_values(array_filter($days, fn ($d) => $d['date'] < $periodFrom));

            $department = $departments[$employee->department_id] ?? null;
            $person = [
                'id' => $employee->id,
                'name' => $employee->fullName(),
                'employee_code' => $employee->employee_code,
                'department' => $department?->path() ?? ($employee->department ?: null),
                'stats' => EmployeeReportService::stats($mine, $shiftStart, $grace),
            ];

            if ($trackingFrom === null || $trackingFrom > $toDate->format('Y-m-d')) {
                $neverTracked[] = $person;

                continue;
            }

            $people[] = $person + ['days' => $mine, 'before' => $before];
        }

        /*
         * Who punched at all. On this tenant's data (September 2026) a third of the
         * roster had no punch in the whole month — whole companies on a terminal that
         * was not syncing, people never enrolled. Pooled in, they read as 1,884 days of
         * absence and an attendance rate of 35%. So every RATE in this report is over
         * the people who punched at least once; the others are counted, named, and the
         * all-in rate is printed beside the headline. Nobody is hidden, and nobody's
         * missing enrollment is reported as somebody's absence.
         */
        $noShow = array_values(array_filter($people, fn ($p) => self::isNoShow($p['stats'])));
        $punching = array_values(array_filter($people, fn ($p) => ! self::isNoShow($p['stats'])));
        $previousPunching = array_filter($people, fn ($p) => ! self::isNoShow(EmployeeReportService::stats($p['before'], $shiftStart, $grace)));

        $current = array_merge([], ...array_column($punching, 'days'));
        $previous = array_merge([], ...array_column($previousPunching, 'before'));
        $strip = fn (array $rows) => array_map(fn ($p) => array_diff_key($p, ['days' => true, 'before' => true]), $rows);

        return [
            'from' => $periodFrom,
            'to' => $toDate->format('Y-m-d'),
            'days' => $spanDays,
            'previous' => ['from' => $prevFrom->format('Y-m-d'), 'to' => $prevTo->format('Y-m-d')],
            'scope' => $departmentId !== null ? (($departments[$departmentId] ?? null)?->path() ?? "#{$departmentId}") : null,
            'shift' => [...EmployeeReportService::shiftPayload($rules), 'timezone' => $tz],
            'headcount' => count($people),
            'punching' => count($punching),
            'stats' => EmployeeReportService::stats($current, $shiftStart, $grace),
            'all_in_stats' => EmployeeReportService::stats(array_merge([], ...array_column($people, 'days')), $shiftStart, $grace),
            'previous_stats' => EmployeeReportService::stats($previous, $shiftStart, $grace),
            'timeline' => self::timeline($punching),
            'weekdays' => self::weekdays($current, $shiftStart, $grace, $rules->workDays),
            'arrivals' => array_values(array_filter(array_column($current, 'first_minutes'), fn ($m) => $m !== null)),
            'departments' => self::departments($people, $shiftStart, $grace),
            'attention' => array_map($strip, self::attention($punching)) + ['no_show' => $strip($noShow)],
            'never_tracked' => array_map(fn ($p) => ['name' => $p['name'], 'employee_code' => $p['employee_code'], 'department' => $p['department']], $neverTracked),
            // Without the per-day rows: the appendix needs only each person's totals.
            'people' => $strip($people),
        ];
    }

    /**
     * Outcome counts per work day across everybody tracked that day — or per week
     * once the period has more work days than a page can draw one column for.
     *
     * @return array{grain:string, buckets:list<array{key:string, label:string, outcomes:array<string,int>, tracked:int}>}
     */
    private static function timeline(array $people): array
    {
        $byDay = [];
        foreach ($people as $p) {
            foreach ($p['days'] as $d) {
                if ($d['outcome'] !== 'untracked') {
                    $byDay[$d['date']][$d['outcome']] = ($byDay[$d['date']][$d['outcome']] ?? 0) + 1;
                }
            }
        }
        ksort($byDay);

        $weekly = count($byDay) > self::DAILY_LIMIT;
        $buckets = [];
        foreach ($byDay as $date => $counts) {
            $day = Carbon::parse($date);
            $key = $weekly ? $day->copy()->startOfWeek(Carbon::SUNDAY)->format('Y-m-d') : $date;
            $buckets[$key] ??= [
                'key' => $key,
                'label' => $weekly ? 'w/c '.Carbon::parse($key)->format('j M') : $day->format('D j M'),
                'outcomes' => array_fill_keys(['on_time', 'late', 'left_early', 'excused', 'missed_punch_out', 'absent'], 0),
                'tracked' => 0,
            ];
            foreach ($counts as $outcome => $n) {
                $buckets[$key]['outcomes'][$outcome] += $n;
                $buckets[$key]['tracked'] += $n;
            }
        }

        return ['grain' => $weekly ? 'week' : 'day', 'buckets' => array_values($buckets)];
    }

    /** Attendance and punctuality per configured work day of the week. */
    private static function weekdays(array $days, int $shiftStart, int $grace, array $workDays): array
    {
        $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $grouped = [];
        foreach ($days as $d) {
            $grouped[Carbon::parse($d['date'])->dayOfWeek][] = $d;
        }

        return array_map(function (int $w) use ($grouped, $names, $shiftStart, $grace) {
            $s = EmployeeReportService::stats($grouped[$w] ?? [], $shiftStart, $grace);

            return ['label' => $names[$w], 'attendance_rate' => $s['attendance_rate'], 'on_time_rate' => $s['on_time_rate'], 'work_days' => $s['work_days']];
        }, $workDays);
    }

    /** Pooled stats per department, best attendance first; "Unassigned" last. */
    private static function departments(array $people, int $shiftStart, int $grace): array
    {
        $groups = [];
        foreach ($people as $p) {
            $key = $p['department'] ?? '';
            $groups[$key] ??= ['people' => [], 'days' => []];
            $groups[$key]['people'][] = $p;
            if (! self::isNoShow($p['stats'])) {
                array_push($groups[$key]['days'], ...$p['days']);
            }
        }

        $rows = [];
        foreach ($groups as $name => $g) {
            $rows[] = [
                'name' => $name === '' ? 'Unassigned' : $name,
                'unassigned' => $name === '',
                'headcount' => count($g['people']),
                'punching' => count(array_filter($g['people'], fn ($p) => ! self::isNoShow($p['stats']))),
                'stats' => EmployeeReportService::stats($g['days'], $shiftStart, $grace),
            ];
        }

        usort($rows, fn ($a, $b) => [$a['unassigned'], -($a['stats']['attendance_rate'] ?? -1), $a['name']]
            <=> [$b['unassigned'], -($b['stats']['attendance_rate'] ?? -1), $b['name']]);

        return $rows;
    }

    /**
     * The short lists management reads first, over the people who punched. Each list holds only people for whom
     * the thing actually happened — a "most absent" list padded with people absent
     * zero times would be a list of names, not a finding.
     */
    private static function attention(array $people, int $limit = 10): array
    {
        $top = function (callable $value, callable $tiebreak) use ($people, $limit) {
            $rows = array_values(array_filter($people, fn ($p) => $value($p) > 0));
            usort($rows, fn ($a, $b) => [$value($b), $tiebreak($b)] <=> [$value($a), $tiebreak($a)]);

            return array_slice($rows, 0, $limit);
        };

        $perfect = array_values(array_filter($people, fn ($p) => $p['stats']['expected_days'] > 0
            && $p['stats']['present_days'] >= $p['stats']['expected_days']
            && $p['stats']['late_arrivals'] === 0
            && $p['stats']['missed_punch_outs'] === 0));

        return [
            'late' => $top(fn ($p) => $p['stats']['late_arrivals'], fn ($p) => $p['stats']['average_minutes_late'] ?? 0),
            'absent' => $top(fn ($p) => $p['stats']['outcomes']['absent'], fn ($p) => -$p['stats']['present_days']),
            'missed' => $top(fn ($p) => $p['stats']['missed_punch_outs'], fn ($p) => 0),
            'perfect' => $perfect,
        ];
    }

    /**
     * Expected on at least one day and present on none: almost always somebody who
     * has left, or is on no terminal that syncs — not somebody absent for a month.
     * A person whose every day was excused is not one.
     */
    private static function isNoShow(array $stats): bool
    {
        return $stats['expected_days'] > 0 && $stats['present_days'] === 0;
    }
}
