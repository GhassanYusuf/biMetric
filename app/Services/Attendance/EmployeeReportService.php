<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\Employee;
use App\Support\AttendanceRules;
use Carbon\Carbon;

/**
 * One employee's report: the numbers behind the "Orbit" report screen (2026-09-27).
 *
 * BUILT ON AttendanceSummaryService, never beside it. Every per-day verdict — late,
 * left early, absent, excused, the payroll hours — is that service's, so this
 * screen and the payroll report cannot disagree about the same day. What this adds
 * is only what the summary does not carry: the terminal and verify method of each
 * day's first punch, the period-over-period comparison, and the per-terminal
 * enrollment as the devices last reported it.
 *
 * ONE READ COVERS EVERYTHING. The screen shows the chosen period, the period
 * before it (for the deltas), and always the last 365 days (the year calendar, the
 * weekday and monthly charts). So the summary runs once over whichever is longer —
 * two periods or a year — and every view is a slice of that one result.
 *
 * Rule 3 / rule 10 hold here as everywhere: a statistic with no days under it is
 * null, never 0, days before the person was tracked are `untracked` rather than
 * absent, and enrollment keeps "never read" and "unknown" apart from a count.
 */
class EmployeeReportService
{
    /** The periods the screen offers, in days. */
    public const PERIODS = [30, 90, 365];

    public function __construct(private readonly AttendanceSummaryService $summary) {}

    /** @return array<string,mixed> */
    public function report(Employee $employee, int $periodDays, ?string $to = null): array
    {
        $tz = config('attendance.timezone');
        $toDate = $to ? Carbon::parse($to, $tz)->endOfDay() : now($tz)->endOfDay();
        $span = max(2 * $periodDays, 365);
        $fromDate = $toDate->copy()->subDays($span - 1)->startOfDay();

        $summary = $this->summary->summary($employee, $fromDate->format('Y-m-d'), $toDate->format('Y-m-d'));
        $firstPunch = $this->firstPunchOfEachDay($employee, $fromDate, $toDate);

        $rules = AttendanceRules::current();
        $shiftEnd = $rules->endMinutes();
        $leftEarlyBefore = $rules->leftEarlyBeforeMinutes();
        $trackingFrom = $this->trackingFrom($employee);

        $days = array_map(function (array $d) use ($firstPunch, $leftEarlyBefore, $trackingFrom) {
            $punch = $firstPunch[$d['date']] ?? null;

            return [
                'date' => $d['date'],
                'outcome' => $trackingFrom === null || $d['date'] < $trackingFrom ? 'untracked' : self::outcome($d, $leftEarlyBefore),
                'excused_reason' => $d['excused_reason'],
                'first_minutes' => $d['first_minutes'],
                'last_minutes' => $d['last_minutes'],
                'hours' => $d['status'] === 'present' && ! $d['missed_punch_out'] ? $d['hours'] : null,
                'payroll_hours' => $d['payroll_hours'],
                'device' => $punch['device'] ?? null,
                'verify_method' => $punch['verify_method'] ?? null,
            ];
        }, $summary['daily']);

        $periodFrom = $toDate->copy()->subDays($periodDays - 1)->format('Y-m-d');
        $previousFrom = $toDate->copy()->subDays(2 * $periodDays - 1)->format('Y-m-d');
        $current = array_values(array_filter($days, fn ($d) => $d['date'] >= $periodFrom));
        $previous = array_values(array_filter($days, fn ($d) => $d['date'] >= $previousFrom && $d['date'] < $periodFrom));

        $shiftStart = $rules->startMinutes();
        $grace = $rules->lateGrace;

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_code' => $employee->employee_code,
                'department' => $employee->department()->value('name') ?? $employee->department,
                'job_title' => $employee->job_title,
                'hired_date' => $employee->hired_date?->format('Y-m-d'),
                'privilege' => $employee->privilege,
                'photo_url' => $employee->photo_url,
                'is_active' => (bool) $employee->is_active,
            ],
            'shift' => [
                ...self::shiftPayload($rules),
                'timezone' => $tz,
            ],
            'period' => ['days' => $periodDays, 'from' => $periodFrom, 'to' => $toDate->format('Y-m-d')],
            'previous' => ['from' => $previousFrom, 'to' => $toDate->copy()->subDays($periodDays)->format('Y-m-d')],
            'span' => ['from' => $fromDate->format('Y-m-d'), 'to' => $toDate->format('Y-m-d')],
            'tracking_from' => $trackingFrom,
            'stats' => self::stats($current, $shiftStart, $grace),
            'previous_stats' => self::stats($previous, $shiftStart, $grace),
            'punches' => self::punchBreakdown($employee, $toDate->copy()->subDays($periodDays - 1)->startOfDay(), $toDate),
            'enrollment' => $this->enrollment($employee),
            // Work days only, oldest first; a date that is absent is a non-work day.
            'days' => $days,
        ];
    }

    /**
     * One outcome per work day, for charts that colour a day by a single category.
     * The precedence is "worst thing that happened": an unclosed day is unclosed
     * before it is late, and a late arrival outranks leaving early.
     *
     * "Left early" is judged here against the shift end itself. The summary's own
     * departure flag cannot be used for it: its `leave_on_time` means "left before
     * the end PLUS grace" — which includes everybody who worked the full shift.
     */
    /**
     * The shift as every report screen and PDF carries it — one shape, from one place.
     * `grace_minutes` is the late grace (the name the charts already read).
     */
    public static function shiftPayload(AttendanceRules $rules): array
    {
        return [
            'start_minutes' => $rules->startMinutes(),
            'end_minutes' => $rules->endMinutes(),
            'late_after_minutes' => $rules->lateAfterMinutes(),
            'grace_minutes' => $rules->lateGrace,
            'left_early_before_minutes' => $rules->leftEarlyBeforeMinutes(),
            'left_late_from_minutes' => $rules->leftLateFromMinutes(),
            'work_days' => $rules->workDays,
        ];
    }

    /** @param  int  $leftEarlyBefore  AttendanceRules::leftEarlyBeforeMinutes() */
    public static function outcome(array $day, int $leftEarlyBefore): string
    {
        return match (true) {
            $day['status'] === 'absent' => 'absent',
            $day['status'] === 'excused' => 'excused',
            $day['missed_punch_out'] => 'missed_punch_out',
            $day['arrival'] === 'come_late' => 'late',
            $day['last_minutes'] !== null && $day['last_minutes'] < $leftEarlyBefore => 'left_early',
            default => 'on_time',
        };
    }

    /**
     * The first day this person's attendance means anything: the hire date, else
     * the day of their first punch ever, else null (never punched, no hire date).
     *
     * Without it every work day before somebody joined is "absent" — the summary
     * has no notion of a start date — and a new starter's year would read as a
     * year of absence. Days before this are `untracked` and count in no statistic.
     */
    private function trackingFrom(Employee $employee): ?string
    {
        if ($employee->hired_date !== null) {
            return $employee->hired_date->format('Y-m-d');
        }

        return self::trackingFromValues(null, AttendanceLog::query()->where('employee_id', $employee->id)->min('punched_at'));
    }

    /**
     * The same rule from values already read, so a roster-wide report
     * (AttendanceReport) can apply it from one grouped query instead of one per
     * person — and cannot drift from what this screen decides.
     */
    public static function trackingFromValues(?Carbon $hiredDate, mixed $firstPunchUtc): ?string
    {
        if ($hiredDate !== null) {
            return $hiredDate->format('Y-m-d');
        }

        return $firstPunchUtc === null ? null
            : Carbon::parse($firstPunchUtc, 'UTC')->setTimezone(config('attendance.timezone'))->format('Y-m-d');
    }

    /** @param list<array<string,mixed>> $days */
    public static function stats(array $days, int $shiftStart, int $grace): array
    {
        $days = array_values(array_filter($days, fn ($d) => $d['outcome'] !== 'untracked'));
        $count = fn (string $o) => count(array_filter($days, fn ($d) => $d['outcome'] === $o));
        $present = array_values(array_filter($days, fn ($d) => $d['first_minutes'] !== null));
        $closed = array_values(array_filter($present, fn ($d) => $d['last_minutes'] !== null));
        $late = array_values(array_filter($present, fn ($d) => $d['outcome'] === 'late'
            || ($d['outcome'] === 'missed_punch_out' && $d['first_minutes'] > $shiftStart + $grace)));
        $excused = $count('excused');
        $expected = count($days) - $excused;
        $avg = fn (array $rows, string $k) => $rows === [] ? null : round(array_sum(array_column($rows, $k)) / count($rows), 1);

        return [
            'work_days' => count($days),
            'expected_days' => $expected,
            'present_days' => count($present),
            'outcomes' => [
                'on_time' => $count('on_time'),
                'late' => $count('late'),
                'left_early' => $count('left_early'),
                'missed_punch_out' => $count('missed_punch_out'),
                'excused' => $excused,
                'absent' => $count('absent'),
            ],
            'attendance_rate' => $expected > 0 ? round(count($present) / $expected, 4) : null,
            'on_time_rate' => $present === [] ? null : round((count($present) - count($late)) / count($present), 4),
            'late_arrivals' => count($late),
            'average_minutes_late' => $late === [] ? null
                : round(array_sum(array_map(fn ($d) => $d['first_minutes'] - $shiftStart, $late)) / count($late), 1),
            'average_arrival_minutes' => $avg($present, 'first_minutes'),
            'average_departure_minutes' => $avg($closed, 'last_minutes'),
            'payroll_hours' => round(array_sum(array_column($days, 'payroll_hours')), 1),
            'missed_punch_outs' => count($present) - count($closed),
        ];
    }

    /**
     * The terminal and verify method of each local day's FIRST punch.
     *
     * @return array<string, array{device:?string, verify_method:?int}>
     */
    private function firstPunchOfEachDay(Employee $employee, Carbon $from, Carbon $to): array
    {
        $tz = config('attendance.timezone');
        $names = Device::query()->pluck('name', 'id');
        $out = [];

        AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('punched_at', [$from->copy()->setTimezone('UTC'), $to->copy()->setTimezone('UTC')])
            ->orderBy('punched_at')
            ->get(['punched_at', 'device_id', 'verify_method'])
            ->each(function (AttendanceLog $log) use (&$out, $names, $tz) {
                $day = $log->punched_at->copy()->setTimezone($tz)->format('Y-m-d');
                $out[$day] ??= [
                    'device' => $names[$log->device_id] ?? null,
                    'verify_method' => $log->verify_method,
                ];
            });

        return $out;
    }

    /**
     * Every punch in the period, counted per terminal and per verify method.
     * Verify methods stay RAW numbers here; the screen labels them from the
     * vendor's VerifyType dictionary (attendance/parts.jsx, App\Support\VerifyMethod).
     */
    private static function punchBreakdown(Employee $employee, Carbon $from, Carbon $to): array
    {
        $names = Device::query()->pluck('name', 'id');
        $logs = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('punched_at', [$from->copy()->setTimezone('UTC'), $to->copy()->setTimezone('UTC')])
            ->get(['device_id', 'verify_method']);

        $byDevice = $logs->groupBy('device_id')
            ->map(fn ($rows, $id) => ['device' => $names[$id] ?? 'Unknown terminal', 'count' => $rows->count()])
            ->sortByDesc('count')->values()->all();
        $byMethod = $logs->groupBy(fn ($l) => $l->verify_method ?? 'null')
            ->map(fn ($rows, $m) => ['verify_method' => $m === 'null' ? null : (int) $m, 'count' => $rows->count()])
            ->sortByDesc('count')->values()->all();

        return ['total' => $logs->count(), 'by_device' => $byDevice, 'by_verify_method' => $byMethod];
    }

    /**
     * What each terminal said about this person, in the three states rule 3 keeps
     * apart: never read (no sync yet), unknown (read, but the template table did
     * not decode), and a real count.
     */
    private function enrollment(Employee $employee): array
    {
        return $employee->deviceMaps()
            ->whereNull('valid_to')
            ->with('device:id,name')
            ->get()
            ->map(fn ($m) => [
                'device' => $m->device?->name,
                'state' => $m->synced_at === null ? 'never_read' : ($m->fingerprint_slots === null ? 'unknown' : 'read'),
                'fingerprints' => $m->fingerprintCount(),
                'has_card' => filled($m->card_number),
                'synced_at' => $m->synced_at?->toIso8601String(),
            ])
            ->sortBy('device')->values()->all();
    }
}
