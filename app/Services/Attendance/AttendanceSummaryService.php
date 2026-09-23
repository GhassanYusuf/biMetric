<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use Carbon\Carbon;

/**
 * Computes an employee's per-day attendance breakdown and payroll-hours total
 * against the office's local timezone/shift, moved verbatim (behaviour-wise)
 * out of the old Inertia controller so it can be reused by the JSON API,
 * reports, and dashboards. All queries run under the current tenant scope.
 *
 * Attendance is defined by the first and last punch of each scheduled work day
 * only; a work day with no punches is "absent" unless a holiday or waiver
 * excuses it (then it's credited the full shift so payroll isn't reduced).
 *
 * It reads NOTHING itself. Punches, holidays, waivers and the per-day shift marks
 * all arrive in a SummaryBatch, which one caller builds for a whole roster and this
 * one builds for a single employee. That is what keeps the payroll report and the
 * employee screen on the same arithmetic — see SummaryBatch for the numbers that
 * forced the split.
 */
class AttendanceSummaryService
{
    /**
     * The local-time window a (possibly absent) range actually means: no end
     * date is today, and no start date is the 29 days before that end.
     *
     * Public because callers have to be able to STATE the window they got. A
     * report that defaulted the range and then printed "all dates" on the cover
     * would be describing a different document than the one it is.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    public static function window(?string $from, ?string $to): array
    {
        $tz = config('attendance.timezone');

        $toDate = $to ? Carbon::parse($to, $tz)->endOfDay() : now($tz)->endOfDay();
        $fromDate = $from ? Carbon::parse($from, $tz)->startOfDay() : $toDate->copy()->subDays(29)->startOfDay();

        return [$fromDate, $toDate];
    }

    /**
     * @param  ?SummaryBatch  $batch  rows and shift marks already read for a SET of
     *                                employees over this same window. Pass one when
     *                                summarising more than one person; leaving it
     *                                null builds a batch of this employee alone, so
     *                                both callers run identical code.
     * @return array{employee:array,from:string,to:string,daily:array,categories:array,total_punches:int,total_payroll_hours:float}
     */
    public function summary(Employee $employee, ?string $from = null, ?string $to = null, ?SummaryBatch $batch = null): array
    {
        $tz = config('attendance.timezone');

        [$fromDate, $toDate] = self::window($from, $to);

        $batch ??= SummaryBatch::for([$employee], $fromDate, $toDate);

        $logs = $batch->logsFor($employee->id);

        $punchesByDay = [];
        foreach ($logs as $log) {
            $local = $log->punched_at->copy()->setTimezone($tz);
            $punchesByDay[$local->format('Y-m-d')][] = $local;
        }

        $byDay = [];
        foreach ($punchesByDay as $day => $times) {
            $byDay[$day] = ['first' => $times[0], 'last' => end($times), 'count' => count($times)];
        }

        $shiftLengthHours = $batch->shiftLengthHours;

        $categories = [
            'early' => 0, 'come_late' => 0, 'leave_on_time' => 0, 'leave_late' => 0,
            'absent' => 0, 'excused' => 0, 'missed_punch_out' => 0,
        ];
        $daily = [];
        $totalPayrollHours = 0;

        /*
         * The work days, and each day's shift marks, come from the batch. They
         * depend only on the date and the configured shift — never on the employee
         * — so recomputing them here meant four Carbon::parse() calls per person per
         * day, which was the single largest cost in the payroll report.
         */
        foreach ($batch->days as $day) {
            $key = $day['key'];
            $info = $byDay[$key] ?? null;
            $holiday = $batch->holidayOn($key);
            $waiver = $batch->waiverOn($employee->id, $key);

            $arrival = null;
            $departure = null;
            $hours = 0;
            $payrollHours = 0;

            if ($info) {
                $status = 'present';
                $expectedStart = $day['expectedStart'];
                $expectedEnd = $day['expectedEnd'];

                $arrival = $info['first']->lte($expectedStart) ? 'early' : 'come_late';
                $categories[$arrival]++;

                if ($info['count'] > 1) {
                    $departure = $info['last']->gte($expectedEnd) ? 'leave_late' : 'leave_on_time';
                    $categories[$departure]++;
                    $hours = round($info['first']->diffInMinutes($info['last']) / 60, 1);

                    $windowStart = $day['windowStart'];
                    $windowEnd = $day['windowEnd'];
                    $clippedStart = $info['first']->greaterThan($windowStart) ? $info['first'] : $windowStart;
                    $clippedEnd = $info['last']->lessThan($windowEnd) ? $info['last'] : $windowEnd;
                    $payrollHours = $clippedEnd->greaterThan($clippedStart)
                        ? round($clippedStart->diffInMinutes($clippedEnd) / 60, 1)
                        : 0;
                } else {
                    $categories['missed_punch_out']++;
                }
            } elseif ($holiday || $waiver) {
                $status = 'excused';
                $categories['excused']++;
                $payrollHours = $shiftLengthHours;
            } else {
                $status = 'absent';
                $categories['absent']++;
            }

            $totalPayrollHours += $payrollHours;

            $daily[] = [
                'date' => $key,
                'status' => $status,
                'excused_reason' => $holiday?->name ?? $waiver?->reason ?? ($waiver ? 'Waived' : null),
                'excused_source' => $holiday ? 'holiday' : ($waiver ? 'waiver' : null),
                'hours' => $hours,
                'payroll_hours' => $payrollHours,
                'first' => $info ? $info['first']->format('g:i A') : null,
                'last' => $info ? $info['last']->format('g:i A') : null,
                'arrival' => $arrival,
                'departure' => $departure,
                'missed_punch_out' => $info !== null && $info['count'] === 1,
            ];
        }

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_code' => $employee->employee_code,
                'email' => $employee->email,
                'department' => $employee->department,
                'photo_url' => $employee->photo_url,
                'is_active' => $employee->is_active,
            ],
            'from' => $fromDate->format('Y-m-d'),
            'to' => $toDate->format('Y-m-d'),
            'daily' => $daily,
            'categories' => $categories,
            'total_punches' => $logs->count(),
            'total_payroll_hours' => round($totalPayrollHours, 1),
        ];
    }
}
