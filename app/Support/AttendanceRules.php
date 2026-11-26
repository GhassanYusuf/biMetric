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
        /**
         * Open 24 hours (2026-11-26): no Late, Left early or Left late; days are found from the punches
         * (a gap of more than NO_FIXED_HOURS_GAP_MINUTES starts a new one) and paid first to last punch.
         * shiftStart / shiftEnd then give only the length of a normal day (holiday credit, absence minutes).
         */
        public readonly bool $noFixedHours = false,
    ) {}

    /** No fixed hours: punches further apart than this belong to different work days. */
    public const NO_FIXED_HOURS_GAP_MINUTES = 600;

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
            (bool) $row->no_fixed_hours,
        );
    }

    public function startMinutes(): int
    {
        return self::minutes($this->shiftStart);
    }

    /**
     * Minutes after the work day's own midnight. An overnight shift (owner, 2026-11-22: Camelot works
     * 17:00–02:00) ends the next day, so its end is past 1440 — 02:00 is 1560 — and every threshold below
     * follows from that without a special case.
     */
    public function endMinutes(): int
    {
        return self::minutes($this->shiftEnd) + ($this->isOvernight() ? 1440 : 0);
    }

    /** The shift ends on the day after it starts (end before start on the clock). */
    public function isOvernight(): bool
    {
        return self::minutes($this->shiftEnd) < self::minutes($this->shiftStart);
    }

    /**
     * When a work day begins, in minutes after midnight. A day shift's day is the calendar day (0), exactly
     * as before. An overnight shift's day starts halfway through the hours off between its end and the next
     * start — 17:00–02:00 gives 09:30 — so the 02:00 punch, and a late one at 04:00, belong to the shift that
     * began the evening before, and an early arrival at 15:00 to the one about to begin.
     */
    public function dayStartsAtMinutes(): int
    {
        if ($this->noFixedHours || ! $this->isOvernight()) {
            return 0;
        }
        $end = self::minutes($this->shiftEnd);

        return $end + intdiv(self::minutes($this->shiftStart) - $end, 2);
    }

    /** The work day (Y-m-d) a punch at $local office time belongs to. */
    public function workDayOf(\Carbon\Carbon $local): string
    {
        return $local->copy()->subMinutes($this->dayStartsAtMinutes())->format('Y-m-d');
    }

    /** $local as minutes after the midnight of work day $day — past 1440 for the small hours of an overnight shift. */
    public static function minutesInto(\Carbon\Carbon $local, string $day): int
    {
        return $local->hour * 60 + $local->minute + ($local->format('Y-m-d') > $day ? 1440 : 0);
    }

    /** The first minute that counts as Late. No fixed hours: 1440 — a day's first punch is always before it. */
    public function lateAfterMinutes(): int
    {
        return $this->noFixedHours ? 1440 : $this->startMinutes() + $this->lateGrace;
    }

    /** A last punch before this minute is Left early. No fixed hours: never. */
    public function leftEarlyBeforeMinutes(): int
    {
        return $this->noFixedHours ? 0 : $this->endMinutes() - $this->earlyLeaveGrace;
    }

    /** A last punch at or after this minute is Left late. No fixed hours: never (two whole days). */
    public function leftLateFromMinutes(): int
    {
        return $this->noFixedHours ? 2880 : $this->endMinutes() + $this->leaveLateAfter;
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
            'no_fixed_hours' => $this->noFixedHours,
        ];
    }

    public static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }
}
