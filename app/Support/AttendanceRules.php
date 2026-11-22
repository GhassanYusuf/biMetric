<?php

namespace App\Support;

use App\Services\Settings\TenantSettings;

/**
 * The company's attendance rules: the work week, the shift, and the thresholds behind
 * every indicator — Late, Left early, Left late (overtime) — in one value.
 *
 * Set on the Holidays screen (owner, 2026-10-19: "set the weekend days so attendance can
 * be accounted accurately … all kinds of indicators like late, left late"), stored as
 * `attendance.*` tenant settings. Before that they were fixed config values: 09:00–18:00,
 * Sun–Thu, one 15-minute grace for both ends — which is not how OTR works.
 *
 * PER COMPANY since 2026-11-22: forCompany() / forEmployee() give a company's own rules when it has them
 * (company_attendance_rules), else these defaults. EVERY reader goes through them (or current() for the
 * default itself), so the summary, the screens, the
 * reports and the Monthly Statement can never judge a day by different rules. Without a
 * resolved company (a console command, a test with no tenant) it is config('attendance').
 *
 *   Late          first punch after  start + lateGrace
 *   Left early    last punch before  end − earlyLeaveGrace
 *   Left late     last punch at/after end + leaveLateAfter; time after `end` is then overtime
 *
 * Times are minutes after local midnight. A shift must end after it starts: the summary
 * groups punches by calendar day, so an overnight shift is not representable yet.
 */
final class AttendanceRules
{
    /** @param list<int> $workDays Carbon dayOfWeek values, 0 = Sunday */
    public function __construct(
        public readonly string $shiftStart,
        public readonly string $shiftEnd,
        public readonly int $lateGrace,
        public readonly int $earlyLeaveGrace,
        public readonly int $leaveLateAfter,
        public readonly array $workDays,
    ) {}

    public static function current(): self
    {
        if (Tenancy::id() === null) {
            return new self(
                config('attendance.shift_start'),
                config('attendance.shift_end'),
                (int) config('attendance.grace_minutes'),
                0,
                (int) config('attendance.grace_minutes'),
                config('attendance.work_days'),
            );
        }

        $s = app(TenantSettings::class)->all();

        return new self(
            $s['attendance.shift_start'],
            $s['attendance.shift_end'],
            $s['attendance.late_grace_minutes'],
            $s['attendance.early_leave_grace_minutes'],
            $s['attendance.leave_late_after_minutes'],
            $s['attendance.work_days'],
        );
    }

    /**
     * The rules for someone in $companyId (2026-11-22): the company's own when it has them
     * (company_attendance_rules), otherwise the system default (current()). Not cached: a queue worker
     * outlives a request, and a rule changed between two jobs must be seen by the second.
     */
    public static function forCompany(?int $companyId): self
    {
        if ($companyId !== null && Tenancy::id() !== null) {
            $row = \App\Models\CompanyAttendanceRule::query()->where('company_id', $companyId)->first();
            if ($row !== null) {
                return self::fromCompanyRow($row);
            }
        }

        return self::current();
    }

    public static function forEmployee(\App\Models\Employee $employee): self
    {
        return self::forCompany($employee->company_id === null ? null : (int) $employee->company_id);
    }

    public static function fromCompanyRow(\App\Models\CompanyAttendanceRule $row): self
    {
        return new self(
            $row->shift_start,
            $row->shift_end,
            (int) $row->late_grace_minutes,
            (int) $row->early_leave_grace_minutes,
            (int) $row->leave_late_after_minutes,
            array_values(array_map('intval', (array) $row->work_days)),
        );
    }

    public function startMinutes(): int
    {
        return self::minutes($this->shiftStart);
    }

    public function endMinutes(): int
    {
        return self::minutes($this->shiftEnd);
    }

    /** The first minute that counts as Late. */
    public function lateAfterMinutes(): int
    {
        return $this->startMinutes() + $this->lateGrace;
    }

    /** A last punch before this minute is Left early. */
    public function leftEarlyBeforeMinutes(): int
    {
        return $this->endMinutes() - $this->earlyLeaveGrace;
    }

    /** A last punch at or after this minute is Left late. */
    public function leftLateFromMinutes(): int
    {
        return $this->endMinutes() + $this->leaveLateAfter;
    }

    public function shiftLengthMinutes(): int
    {
        return $this->endMinutes() - $this->startMinutes();
    }

    /** The shape the report screens and the API carry. */
    public function toArray(): array
    {
        return [
            'shift_start' => $this->shiftStart,
            'shift_end' => $this->shiftEnd,
            'late_grace_minutes' => $this->lateGrace,
            'early_leave_grace_minutes' => $this->earlyLeaveGrace,
            'leave_late_after_minutes' => $this->leaveLateAfter,
            'work_days' => $this->workDays,
            'weekend_days' => array_values(array_diff(range(0, 6), $this->workDays)),
        ];
    }

    public static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }
}
