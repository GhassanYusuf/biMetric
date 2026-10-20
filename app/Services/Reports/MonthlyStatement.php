<?php

namespace App\Services\Reports;

use App\Models\Department;
use App\Models\Employee;
use App\Services\Attendance\AttendanceSummaryService;
use App\Services\Attendance\SummaryBatch;
use App\Support\AttendanceRules;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * The "Monthly Statement Report": one block per employee, one row per calendar
 * day, in the column layout of the ZKTeco software the company used before this
 * system (draft/OTR-SEP.xlsx, 2026-10-19 — the owner's reference format):
 *
 *   Date | Week | Timetable | In | Out | In | Out | Break | Late | Early | Work |
 *   Absent | OT1 | OT2 | OT3 | Exception | Holiday
 *
 * THE LAYOUT IS ZKTECO'S; THE ARITHMETIC IS OURS. Every row is read off
 * AttendanceSummaryService — the same computation behind the payroll summary and
 * the employee screens — so this document cannot disagree with them. Where the
 * ZKTeco export applied a rule this system does not have, the column follows this
 * system's configured shift instead, and each rule is printed on the document:
 *
 *   In / Out    first and last punch of the day; Out is blank when there was only
 *               one punch (and the row says so — ZKTeco printed 0:00 work with no
 *               flag). The second In/Out pair is kept for the layout and is empty,
 *               exactly as it was in every ZKTeco export we have.
 *   Break       0:00. No break is deducted from Work, here or in ZKTeco's output.
 *   Late        minutes after shift start, on a day the arrival was judged late
 *               (past the late grace). All thresholds: App\Support\AttendanceRules.
 *   Early       minutes before shift end, when the Out punch is before the end
 *               minus the early-leave allowance.
 *   Work        Out − In, unclipped. Matches ZKTeco's Work to the minute.
 *   Absent      the shift length, on a work day with no punch and no excuse.
 *               ZKTeco counted 15:00 on EVERY day without punches, weekends included.
 *   OT1         minutes after shift end on a work day, once the Out punch reached
 *               the Left-late threshold.
 *   OT2         all time worked on a non-work day.
 *   OT3         unused: a holiday with punches is an ordinary present day in the
 *               summary, so there is no separate holiday-overtime figure to print.
 *   Exception   the shift length, credited by a waiver.
 *   Holiday     the shift length, credited by a holiday.
 *
 * All durations are whole minutes; the Blade view formats them as H:MM.
 */
class MonthlyStatement
{
    public function __construct(private readonly AttendanceSummaryService $summaries) {}

    /**
     * Who is on it: every active employee, or those in one or more companies /
     * departments AND THEIR SUB-DEPARTMENTS (the dialog says "and below"; a company holds
     * no staff directly), or exactly the people picked by id (owner, 2026-10-19: "one
     * person or multiple", then "multiple departments"). Picked people are included even
     * if inactive — somebody chose them by name.
     *
     * @param  list<int>|null  $departmentIds
     * @param  list<int>|null  $employeeIds
     * @return array{from:string,to:string,shift:array,employees:list<array>}
     */
    public function build(?string $from = null, ?string $to = null, ?array $departmentIds = null, ?array $employeeIds = null): array
    {
        [$fromDate, $toDate] = AttendanceSummaryService::window($from, $to);
        $from = $fromDate->format('Y-m-d');
        $to = $toDate->format('Y-m-d');

        $inDepartments = $departmentIds === null ? null
            : array_values(array_unique(array_merge(...array_map(fn (int $id) => Department::subtreeIds($id), $departmentIds))));

        $employees = Employee::query()
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('id', $employeeIds), fn ($q) => $q->where('is_active', true))
            ->when($inDepartments !== null, fn ($q) => $q->whereIn('department_id', $inDepartments))
            ->orderBy('name')
            ->get();

        $batch = SummaryBatch::for($employees, $fromDate, $toDate);

        $rules = $batch->rules;
        $timetable = $rules->shiftStart.'-'.$rules->shiftEnd;

        // Every calendar day, not only work days: the ZKTeco statement lists them all,
        // and a gap in the dates reads as a missing page.
        $calendar = [];
        foreach (CarbonPeriod::create($fromDate->copy()->startOfDay(), $toDate->copy()->startOfDay()) as $date) {
            $calendar[] = $date->format('Y-m-d');
        }

        $blocks = $employees->map(function (Employee $employee) use ($from, $to, $batch, $calendar, $rules, $timetable) {
            $summary = $this->summaries->summary($employee, $from, $to, $batch);
            $workDays = array_column($summary['daily'], null, 'date');
            $offDays = array_column($summary['off_days'], null, 'date');

            $rows = [];
            foreach ($calendar as $key) {
                $rows[] = isset($workDays[$key])
                    ? self::workDayRow($key, $workDays[$key], $rules, $timetable)
                    : self::offDayRow($key, $offDays[$key] ?? null);
            }

            $totals = [];
            foreach (self::DURATIONS as $column) {
                $totals[$column] = array_sum(array_column($rows, $column));
            }

            return [
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'employee_code' => $employee->employee_code,
                    'department' => $employee->department,
                ],
                'rows' => $rows,
                'totals' => $totals,
            ];
        })->values()->all();

        return [
            'from' => $from,
            'to' => $to,
            'shift' => [
                'start' => $rules->shiftStart,
                'end' => $rules->shiftEnd,
                'grace' => $rules->lateGrace,
                'early_leave_grace' => $rules->earlyLeaveGrace,
                'leave_late_after' => $rules->leaveLateAfter,
                'work_days' => $rules->workDays,
                'length_minutes' => $rules->shiftLengthMinutes(),
            ],
            'employees' => $blocks,
        ];
    }

    /** The columns that are durations, in minutes, and are totalled. */
    public const DURATIONS = ['break', 'late', 'early', 'work', 'absent', 'ot1', 'ot2', 'ot3', 'exception', 'holiday'];

    private static function workDayRow(string $key, array $day, AttendanceRules $rules, string $timetable): array
    {
        $shiftStart = $rules->startMinutes();
        $shiftEnd = $rules->endMinutes();
        $row = self::blank($key, $timetable);
        $credit = (int) round($day['payroll_hours'] * 60);

        if ($day['status'] === 'absent') {
            $row['absent'] = $shiftEnd - $shiftStart;

            return $row;
        }

        if ($day['status'] === 'excused') {
            $row[$day['excused_source'] === 'holiday' ? 'holiday' : 'exception'] = $credit;
            $row['note'] = $day['excused_reason'];

            return $row;
        }

        $first = $day['first_minutes'];
        $last = $day['last_minutes'];
        $row['in'] = self::clock($first);

        if ($day['arrival'] === 'come_late') {
            $row['late'] = max(0, $first - $shiftStart);
        }

        if ($last === null) {
            $row['missed_punch_out'] = true;

            return $row;
        }

        $row['out'] = self::clock($last);
        $row['work'] = $last - $first;
        // Counted in full from the shift edge once the threshold is crossed, as ZKTeco did:
        // leaving at 16:50 with a 15-minute allowance is 70 minutes early, not 55.
        if ($last < $rules->leftEarlyBeforeMinutes()) {
            $row['early'] = $shiftEnd - $last;
        }
        if ($last >= $rules->leftLateFromMinutes()) {
            $row['ot1'] = max(0, $last - max($first, $shiftEnd));
        }

        return $row;
    }

    private static function offDayRow(string $key, ?array $day): array
    {
        $row = self::blank($key, 'Off');

        if ($day === null) {
            return $row;
        }

        $row['in'] = self::clock($day['first_minutes']);

        if ($day['last_minutes'] === null) {
            $row['missed_punch_out'] = true;

            return $row;
        }

        $row['out'] = self::clock($day['last_minutes']);
        $row['work'] = $day['last_minutes'] - $day['first_minutes'];
        $row['ot2'] = $row['work'];

        return $row;
    }

    private static function blank(string $key, string $timetable): array
    {
        $date = Carbon::parse($key);

        return [
            'date' => $date->format('m/d/Y'),
            'week' => self::WEEKDAYS[$date->dayOfWeek],
            'timetable' => $timetable,
            'in' => null,
            'out' => null,
            'missed_punch_out' => false,
            'note' => null,
            ...array_fill_keys(self::DURATIONS, 0),
        ];
    }

    /** ZKTeco's own weekday abbreviations, Sunday first like Carbon's dayOfWeek. */
    private const WEEKDAYS = ['Sun.', 'Mon.', 'Tues.', 'Wed.', 'Thur.', 'Fri.', 'Sat.'];

    private static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** "199:12" — hours run past 24, the way the ZKTeco totals do. */
    public static function duration(int $minutes): string
    {
        return intdiv($minutes, 60).':'.sprintf('%02d', $minutes % 60);
    }
}
