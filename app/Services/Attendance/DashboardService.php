<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\Employee;
use Carbon\Carbon;

/**
 * Aggregates the tenant-scoped KPIs shown on the dashboard. Every query runs
 * under the current TenantScope, so figures are always for the acting tenant.
 */
class DashboardService
{
    public function summary(): array
    {
        $tz = config('attendance.timezone');
        $nowTenant = Carbon::now($tz);
        $todayStartUtc = $nowTenant->copy()->startOfDay()->setTimezone('UTC');
        $todayEndUtc = $nowTenant->copy()->endOfDay()->setTimezone('UTC');

        // Baseline for the trend deltas: yesterday up to the same clock time, so
        // a half-finished day is never compared against a full one.
        $yesterdayStartUtc = $nowTenant->copy()->subDay()->startOfDay()->setTimezone('UTC');
        $yesterdaySameTimeUtc = $nowTenant->copy()->subDay()->setTimezone('UTC');

        $employeesTotal = Employee::query()->count();
        $employeesActive = Employee::query()->where('is_active', true)->count();

        $devicesTotal = Device::query()->count();
        $devicesActive = Device::query()->where('is_active', true)->count();
        $devicesErrored = Device::query()->where('last_sync_status', 'error')->count();

        $todaysPunches = AttendanceLog::query()
            ->whereBetween('punched_at', [$todayStartUtc, $todayEndUtc])
            ->count();

        $presentToday = AttendanceLog::query()
            ->whereBetween('punched_at', [$todayStartUtc, $todayEndUtc])
            ->distinct('employee_id')
            ->count('employee_id');

        $yesterdayPunches = AttendanceLog::query()
            ->whereBetween('punched_at', [$yesterdayStartUtc, $yesterdaySameTimeUtc])
            ->count();

        $yesterdayPresent = AttendanceLog::query()
            ->whereBetween('punched_at', [$yesterdayStartUtc, $yesterdaySameTimeUtc])
            ->distinct('employee_id')
            ->count('employee_id');

        $absentToday = max(0, $employeesActive - $presentToday);

        return [
            'employees' => [
                'total' => $employeesTotal,
                'active' => $employeesActive,
            ],
            'devices' => [
                'total' => $devicesTotal,
                'active' => $devicesActive,
                'errored' => $devicesErrored,
            ],
            'today' => [
                'punches' => $todaysPunches,
                'present' => $presentToday,
                'absent' => $absentToday,
            ],
            // Day-over-day deltas for the KPI cards. `pct` is null when there is
            // no baseline to divide by — the UI then shows no chip rather than a
            // fabricated one. Devices have no history to compare, so no trend.
            'trends' => [
                'punches' => $this->delta($todaysPunches, $yesterdayPunches),
                'present' => $this->delta($presentToday, $yesterdayPresent),
                // Yesterday's absentee count reuses today's active-headcount as the
                // denominator; exact enough for a direction-of-travel chip.
                'absent' => $this->delta($absentToday, max(0, $employeesActive - $yesterdayPresent)),
            ],
        ];
    }

    /**
     * Percentage change from $previous to $current. Returns a null percentage
     * when there is no baseline, so callers can omit the chip entirely.
     *
     * @return array{pct:float|null,direction:string}
     */
    private function delta(int $current, int $previous): array
    {
        if ($previous === 0) {
            return ['pct' => null, 'direction' => $current > 0 ? 'up' : 'flat'];
        }

        $pct = round((($current - $previous) / $previous) * 100, 1);

        return [
            'pct' => $pct,
            'direction' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat'),
        ];
    }

    /** Trend windows the dashboard range control offers, in days. */
    public const RANGES = [7, 30, 90];

    /**
     * Real punch counts per day over the last $days (tenant timezone), for the
     * dashboard trend chart. No fabricated values — empty days report 0.
     *
     * One query for the whole window rather than one COUNT per day: at 90 days
     * the per-day loop was 90 round trips for a chart above the fold. Bucketing
     * happens in PHP so no database-specific date function is needed — SQLite's
     * date() modifiers and MySQL's CONVERT_TZ are not interchangeable.
     *
     * @return array<int,array{label:string,date:string,punches:int}>
     */
    public function punchSeries(int $days = 7): array
    {
        $days = in_array($days, self::RANGES, true) ? $days : 7;

        $tz = config('attendance.timezone');
        $first = Carbon::now($tz)->subDays($days - 1)->startOfDay();

        // Bucket in the tenant timezone, not UTC, so a punch just after local
        // midnight counts against the local day the employee actually worked.
        $counts = AttendanceLog::query()
            ->where('punched_at', '>=', $first->copy()->setTimezone('UTC'))
            ->pluck('punched_at')
            ->countBy(fn (Carbon $at) => $at->copy()->setTimezone($tz)->format('Y-m-d'));

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $first->copy()->addDays($i);
            $key = $day->format('Y-m-d');

            $series[] = [
                // Weekday initials read well across 7 buckets; longer windows
                // need the date, and only every Nth label is rendered client-side.
                'label' => $days <= 7 ? $day->format('D') : $day->format('j M'),
                'date' => $key,
                'punches' => (int) ($counts[$key] ?? 0),
            ];
        }

        return $series;
    }

    /** The most recent punches across the tenant, for a live activity feed. */
    public function recentActivity(int $limit = 10): array
    {
        return AttendanceLog::query()
            ->with(['employee:id,name', 'device:id,name'])
            ->latest('punched_at')
            ->limit($limit)
            ->get()
            ->map(fn (AttendanceLog $log) => [
                'id' => $log->id,
                'employee_name' => $log->employee?->name,
                'device_name' => $log->device?->name,
                'punched_at' => $log->punched_at?->toIso8601String(),
            ])
            ->all();
    }
}
