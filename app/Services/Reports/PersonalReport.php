<?php

namespace App\Services\Reports;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Services\Attendance\AttendanceSummaryService;
use App\Services\Attendance\EmployeeReportService;
use App\Services\Attendance\SummaryBatch;
use App\Support\AttendanceRules;
use App\Support\VerifyMethod;
use Carbon\Carbon;

/**
 * One person's attendance report over ANY period (2026-10-18): the PDF that HR is
 * asked for — "everyone for September", "this person from the 3rd to the 21st".
 *
 * ONE PATH FOR ONE PERSON AND FOR THE WHOLE ROSTER. build() takes a set of
 * employees and reads everything for the set at once (the SummaryBatch rule: a
 * per-person loop of queries is how the payroll report reached 1,072 queries). The
 * single-person export is a set of one; the bulk export (BuildEmployeeReports) calls
 * it a chunk at a time. So the file one person is handed and their pages in the
 * company-wide file cannot differ.
 *
 * NOTHING HERE JUDGES A DAY. Verdicts are AttendanceSummaryService's, made into one
 * outcome by EmployeeReportService::outcome(); every rate is EmployeeReportService::
 * stats(). A person's figures here equal their Employee Report screen over the same
 * days — PersonalReportTest pins it.
 *
 * What it adds is the punch detail the screen does not carry: EVERY punch of every
 * day, with its terminal and verify method, for the table HR asked for.
 */
class PersonalReport
{
    public function __construct(private readonly AttendanceSummaryService $summaries) {}

    /**
     * @param  iterable<Employee>  $employees
     * @return list<array<string,mixed>> one report per employee, in the order given
     */
    public function build(iterable $employees, ?string $from, ?string $to): array
    {
        $employees = collect($employees)->values();
        if ($employees->isEmpty()) {
            return [];
        }

        $tz = config('attendance.timezone');
        [$fromDate, $toDate] = AttendanceSummaryService::window($from, $to);
        $spanDays = (int) $fromDate->copy()->startOfDay()->diffInDays($toDate->copy()->startOfDay()) + 1;
        $prevFrom = $fromDate->copy()->subDays($spanDays);
        $periodFrom = $fromDate->format('Y-m-d');
        $ids = $employees->pluck('id')->all();

        $rules = AttendanceRules::current();
        $shiftStart = $rules->startMinutes();
        $leftEarlyBefore = $rules->leftEarlyBeforeMinutes();
        $grace = $rules->lateGrace;
        $shift = [...EmployeeReportService::shiftPayload($rules), 'timezone' => $tz];

        $departments = Department::query()->with('parent')->get()->keyBy('id');
        $deviceNames = Device::query()->pluck('name', 'id');

        $firstPunch = AttendanceLog::query()
            ->whereIn('employee_id', $ids)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, min(punched_at) as first_punch')
            ->pluck('first_punch', 'employee_id');

        $batch = SummaryBatch::for($employees, $prevFrom, $toDate);

        // Every punch in the period, with where and how — the punch table's rows.
        $punches = [];
        AttendanceLog::query()
            ->whereIn('employee_id', $ids)
            ->whereBetween('punched_at', [$fromDate->copy()->setTimezone('UTC'), $toDate->copy()->setTimezone('UTC')])
            ->orderBy('punched_at')
            ->get(['employee_id', 'punched_at', 'device_id', 'verify_method'])
            ->each(function (AttendanceLog $log) use (&$punches, $tz, $deviceNames) {
                $local = $log->punched_at->copy()->setTimezone($tz);
                $punches[$log->employee_id][$local->format('Y-m-d')][] = [
                    'minutes' => $local->hour * 60 + $local->minute,
                    'device' => $deviceNames[$log->device_id] ?? 'Unknown terminal',
                    'method' => $log->verify_method !== null ? VerifyMethod::label($log->verify_method) : null,
                ];
            });

        $enrollment = EmployeeDeviceMap::query()
            ->whereIn('employee_id', $ids)
            ->whereNull('valid_to')
            ->with('device:id,name')
            ->get()
            ->groupBy('employee_id');

        $reports = [];
        foreach ($employees as $employee) {
            $trackingFrom = EmployeeReportService::trackingFromValues($employee->hired_date, $firstPunch[$employee->id] ?? null);
            $summary = $this->summaries->summary($employee, $prevFrom->format('Y-m-d'), $toDate->format('Y-m-d'), $batch);
            $mine = $punches[$employee->id] ?? [];

            $days = array_map(fn (array $d) => [
                'date' => $d['date'],
                'outcome' => $trackingFrom === null || $d['date'] < $trackingFrom ? 'untracked' : EmployeeReportService::outcome($d, $leftEarlyBefore),
                'excused_reason' => $d['excused_reason'],
                'first_minutes' => $d['first_minutes'],
                'last_minutes' => $d['last_minutes'],
                // Same rule as the screen: hours only for a closed day.
                'hours' => $d['status'] === 'present' && ! $d['missed_punch_out'] ? $d['hours'] : null,
                'payroll_hours' => $d['payroll_hours'],
                'punches' => $mine[$d['date']] ?? [],
            ], $summary['daily']);

            $current = array_values(array_filter($days, fn ($d) => $d['date'] >= $periodFrom));
            $previous = array_values(array_filter($days, fn ($d) => $d['date'] < $periodFrom));
            $tracked = array_values(array_filter($current, fn ($d) => $d['outcome'] !== 'untracked'));
            $stats = EmployeeReportService::stats($current, $shiftStart, $grace);
            $department = $departments[$employee->department_id] ?? null;

            $reports[] = [
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->fullName(),
                    'employee_code' => $employee->employee_code,
                    'department' => $department?->path() ?? ($employee->department ?: null),
                    'job_title' => $employee->job_title,
                    'hired_date' => $employee->hired_date?->format('Y-m-d'),
                    'is_active' => (bool) $employee->is_active,
                ],
                'period' => ['from' => $periodFrom, 'to' => $toDate->format('Y-m-d'), 'days' => $spanDays],
                'previous' => ['from' => $prevFrom->format('Y-m-d'), 'to' => $fromDate->copy()->subDay()->format('Y-m-d')],
                'shift' => $shift,
                'tracking_from' => $trackingFrom,
                'stats' => $stats,
                'previous_stats' => EmployeeReportService::stats($previous, $shiftStart, $grace),
                'days' => $tracked,
                'calendar' => array_column($current, 'outcome', 'date'),
                'first_minutes' => array_column($current, 'first_minutes', 'date'),
                'weekdays' => self::weekdays($tracked, $shift),
                'months' => $spanDays >= 60 ? self::months($tracked, $shift['late_after_minutes']) : [],
                'where' => self::where($mine),
                'enrollment' => ($enrollment[$employee->id] ?? collect())->map(fn (EmployeeDeviceMap $m) => [
                    'device' => $m->device?->name,
                    // Rule 3: never read, unknown and a count are three different things.
                    'state' => $m->synced_at === null ? 'never_read' : ($m->fingerprint_slots === null ? 'unknown' : 'read'),
                    'fingerprints' => $m->fingerprintCount(),
                    'has_card' => filled($m->card_number),
                    'synced_at' => $m->synced_at?->copy()->setTimezone($tz)->format('j M Y g:i A'),
                ])->sortBy('device')->values()->all(),
                'alerts' => self::alerts($tracked, $stats),
            ];
        }

        return $reports;
    }

    /** Attendance and late arrivals per configured work day of the week. */
    private static function weekdays(array $days, array $shift): array
    {
        $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        return array_map(function (int $w) use ($days, $names, $shift) {
            $on = array_filter($days, fn ($d) => Carbon::parse($d['date'])->dayOfWeek === $w);
            $expected = count(array_filter($on, fn ($d) => $d['outcome'] !== 'excused'));
            $present = array_filter($on, fn ($d) => $d['first_minutes'] !== null);

            return [
                'label' => $names[$w],
                'attendance_rate' => $expected > 0 ? count($present) / $expected : null,
                'late' => (float) count(array_filter($present, fn ($d) => $d['first_minutes'] > $shift['late_after_minutes'])),
            ];
        }, $shift['work_days']);
    }

    /** On-time share of days present, per calendar month of the period. */
    private static function months(array $days, int $lateAfter): array
    {
        $out = [];
        foreach ($days as $d) {
            $key = substr($d['date'], 0, 7);
            $out[$key] ??= ['label' => Carbon::parse($d['date'])->format('M'), 'present' => 0, 'late' => 0];
            if ($d['first_minutes'] !== null) {
                $out[$key]['present']++;
                $out[$key]['late'] += $d['first_minutes'] > $lateAfter ? 1 : 0;
            }
        }

        return array_values(array_map(fn ($m) => [
            'label' => $m['label'],
            'value' => $m['present'] ? ($m['present'] - $m['late']) / $m['present'] : null,
        ], $out));
    }

    /** Punches per terminal and per verify method, most used first. */
    private static function where(array $byDay): array
    {
        $all = array_merge([], ...array_values($byDay));
        $total = count($all);
        $count = function (string $key) use ($all, $total) {
            $counts = array_count_values(array_map(fn ($p) => $p[$key] ?? 'Unknown', $all));
            arsort($counts);

            return array_map(fn ($label, $n) => ['label' => $label, 'count' => $n, 'share' => $total ? $n / $total : 0], array_keys($counts), $counts);
        };

        return ['total' => $total, 'devices' => $count('device'), 'methods' => $count('method')];
    }

    /** The screen's "Needs attention" items, minus the comparison it draws from a year. */
    private static function alerts(array $tracked, array $stats): array
    {
        $alerts = [];
        $day = fn (string $d) => Carbon::parse($d)->format('D j M');

        $absent = array_values(array_filter($tracked, fn ($d) => $d['outcome'] === 'absent'));
        if ($absent) {
            $alerts[] = ['level' => 'Critical', 'tone' => 'critical', 'title' => count($absent).' unexplained absence'.(count($absent) === 1 ? '' : 's'),
                'detail' => 'Latest '.$day(end($absent)['date']).' — no punches and no waiver on file.'];
        }

        $missed = array_values(array_filter($tracked, fn ($d) => $d['outcome'] === 'missed_punch_out'));
        if ($missed) {
            $alerts[] = ['level' => 'Serious', 'tone' => 'serious', 'title' => count($missed).' missed punch-out'.(count($missed) === 1 ? '' : 's'),
                'detail' => 'Latest '.$day(end($missed)['date']).'. Those days are not in the payroll hours.'];
        }

        if ($stats['late_arrivals'] >= 3) {
            $alerts[] = ['level' => 'Warning', 'tone' => 'warning', 'title' => "{$stats['late_arrivals']} late arrivals",
                'detail' => 'On-time on '.number_format(($stats['on_time_rate'] ?? 0) * 100).'% of days present.'];
        }

        return $alerts;
    }
}
