<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one definition of "which punches may this user see, under these filters".
 *
 * The punch log is read by the JSON list endpoint and by the PDF export, and the
 * two must never disagree: an export that ignored the self-only rule would hand
 * an employee the whole company's movements in a downloadable file. Keeping the
 * scoping here means the rule is written once and both callers inherit it.
 *
 * Tenant isolation is not this class's job — TenantScope already applies.
 */
final class PunchLogQuery
{
    /** May this user read the punch log at all — their own rows or everyone's? */
    public static function allowed(User $user): bool
    {
        return $user->can('attendance.view') || $user->can('attendance.view.self');
    }

    /**
     * True when the user may see ONLY their own punches. Such a user's
     * `employee_id` filter is ignored rather than honoured, so a crafted query
     * string cannot widen what they get back.
     */
    public static function selfOnly(User $user): bool
    {
        return ! $user->can('attendance.view');
    }

    /**
     * @param  array{employee_id?:int|string|null,device_id?:int|string|null,from?:string|null,to?:string|null}  $filters
     */
    public static function build(User $user, bool $selfOnly, array $filters): Builder
    {
        $value = fn (string $key) => ($filters[$key] ?? null) === '' ? null : ($filters[$key] ?? null);

        return AttendanceLog::query()
            ->when($selfOnly, fn ($q) => $q->where('employee_id', $user->employee_id))
            ->when(! $selfOnly && $value('employee_id') !== null, fn ($q) => $q->where('employee_id', (int) $filters['employee_id']))
            ->when($value('device_id') !== null, fn ($q) => $q->where('device_id', (int) $filters['device_id']))
            ->when($value('from') !== null, fn ($q) => $q->where('punched_at', '>=', self::dayStart($filters['from'])))
            ->when($value('to') !== null, fn ($q) => $q->where('punched_at', '<=', self::dayEnd($filters['to'])))
            ->latest('punched_at');
    }

    /*
    | A date typed into a filter is an OFFICE-LOCAL date, and punched_at is UTC.
    |
    | Both conversions below therefore go through config('attendance.timezone'),
    | the same zone AttendanceSummaryService uses, so a range means the same thing
    | on the punch list, in a summary and in a printed export.
    |
    | This is the rule-10 trap in its filtering form. Comparing a bare
    | 'YYYY-MM-DD' against a UTC column silently shifted every boundary by the
    | office offset (+03:00 here), so "from the 1st" dropped the first three hours
    | of the 1st, and "to the 14th" — midnight, not end of day — dropped the whole
    | of the 14th. An export header that printed those dates would have been
    | stating a range the query did not actually run.
    */
    private static function dayStart(string $date): Carbon
    {
        return Carbon::parse($date, config('attendance.timezone'))->startOfDay()->utc();
    }

    private static function dayEnd(string $date): Carbon
    {
        return Carbon::parse($date, config('attendance.timezone'))->endOfDay()->utc();
    }
}
