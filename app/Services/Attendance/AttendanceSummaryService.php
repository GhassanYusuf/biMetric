<?php

namespace App\Services\Attendance;

use App\Models\AttendanceWaiver;
use App\Models\Employee;
use App\Models\Holiday;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * Computes an employee's per-day attendance breakdown and payroll-hours total
 * against the office's local timezone/shift, moved verbatim (behaviour-wise)
 * out of the old Inertia controller so it can be reused by the JSON API,
 * reports, and dashboards. All queries run under the current tenant scope.
 *
 * Attendance is defined by the first and last punch of each scheduled work day
 * only; a work day with no punches is "absent" unless a holiday or waiver
 * excuses it (then it's credited the full shift so payroll isn't reduced).
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
     * @return array{employee:array,from:string,to:string,daily:array,categories:array,total_punches:int,total_payroll_hours:float}
     */
    public function summary(Employee $employee, ?string $from = null, ?string $to = null): array
    {
        $tz = config('attendance.timezone');

        [$fromDate, $toDate] = self::window($from, $to);

        $logs = $employee->attendanceLogs()
            ->whereBetween('punched_at', [$fromDate->copy()->setTimezone('UTC'), $toDate->copy()->setTimezone('UTC')])
            ->orderBy('punched_at')
            ->get(['punched_at']);

        $punchesByDay = [];
        foreach ($logs as $log) {
            $local = $log->punched_at->copy()->setTimezone($tz);
            $punchesByDay[$local->format('Y-m-d')][] = $local;
        }

        $byDay = [];
        foreach ($punchesByDay as $day => $times) {
            $byDay[$day] = ['first' => $times[0], 'last' => end($times), 'count' => count($times)];
        }

        $holidays = Holiday::query()
            ->whereBetween('date', [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')])
            ->get()->keyBy(fn (Holiday $h) => $h->date->format('Y-m-d'));

        $waivers = AttendanceWaiver::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')])
            ->get()->keyBy(fn (AttendanceWaiver $w) => $w->date->format('Y-m-d'));

        $shiftStart = config('attendance.shift_start');
        $shiftEnd = config('attendance.shift_end');
        $grace = config('attendance.grace_minutes');
        $workDays = config('attendance.work_days');
        $shiftLengthHours = round(Carbon::parse($shiftStart)->diffInMinutes(Carbon::parse($shiftEnd)) / 60, 1);

        $categories = [
            'early' => 0, 'come_late' => 0, 'leave_on_time' => 0, 'leave_late' => 0,
            'absent' => 0, 'excused' => 0, 'missed_punch_out' => 0,
        ];
        $daily = [];
        $totalPayrollHours = 0;

        foreach (CarbonPeriod::create($fromDate, $toDate) as $date) {
            if (! in_array($date->dayOfWeek, $workDays, true)) {
                continue;
            }

            $key = $date->format('Y-m-d');
            $info = $byDay[$key] ?? null;
            $holiday = $holidays->get($key);
            $waiver = $waivers->get($key);

            $arrival = null;
            $departure = null;
            $hours = 0;
            $payrollHours = 0;

            if ($info) {
                $status = 'present';
                $expectedStart = Carbon::parse("{$key} {$shiftStart}", $tz)->addMinutes($grace);
                $expectedEnd = Carbon::parse("{$key} {$shiftEnd}", $tz)->addMinutes($grace);

                $arrival = $info['first']->lte($expectedStart) ? 'early' : 'come_late';
                $categories[$arrival]++;

                if ($info['count'] > 1) {
                    $departure = $info['last']->gte($expectedEnd) ? 'leave_late' : 'leave_on_time';
                    $categories[$departure]++;
                    $hours = round($info['first']->diffInMinutes($info['last']) / 60, 1);

                    $windowStart = Carbon::parse("{$key} {$shiftStart}", $tz);
                    $windowEnd = Carbon::parse("{$key} {$shiftEnd}", $tz);
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
