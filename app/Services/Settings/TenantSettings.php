<?php

namespace App\Services\Settings;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\Tenancy;
use App\Support\Timezone;
use Cron\CronExpression as CronExpression;
use Illuminate\Support\Facades\Auth;

/**
 * Per-company settings, stored in the `settings` table (tenant_id, key, value).
 *
 * ALLOW-LISTED: only keys declared in DEFAULTS can be read or written, each with its
 * type and default, so the table can never fill with arbitrary keys from a request.
 * Every change is audited (setting.updated, old and new value).
 */
class TenantSettings
{
    /**
     * key => [default, type]
     *
     * employees.auto_push_on_save (owner's decision, 2026-09-24): when on, saving an
     * employee pushes name, card and — where the terminal supports it — the user ID to
     * every terminal on their Access levels list without the "Push now / Not now"
     * question. OFF by default: it turns every edit into immediate terminal writes, so
     * an administrator switches it on knowingly. Removals from a terminal always ask.
     */
    public const DEFAULTS = [
        'employees.auto_push_on_save' => [false, 'bool'],

        /*
         * The company timezone (owner, 2026-10-05). Terminals follow this unless one
         * carries its own `devices.timezone`, which is how a machine in another country
         * is kept on its local clock: the override is per device, this is the default
         * every other machine inherits.
         *
         * A ZKT terminal has no timezone database — no zones, no daylight saving. What
         * "follow this timezone" means is therefore DeviceTimezoneService writing that
         * zone's current wall-clock time to the device clock, not configuring a zone.
         * That is why it is applied explicitly and never on a timer.
         *
         * Stored validated (see asTimezone) so a typo is refused at save time rather
         * than becoming a device whose clock is set from an unknown zone and
         * surfacing hours later as wrong punches. Default is the IANA name, not the
         * '+03:00' offset this project used historically, so that a company in a DST
         * country gets DST-aware behaviour without anyone having to remember that.
         */
        'system.timezone' => ['Asia/Bahrain', 'timezone'],

        /*
         * Scheduled attendance import (owner, 2026-10-05).
         *
         * OFF by default, and that default is deliberate rather than cautious: this
         * server had no `schedule:run` wired to cron until 2026-10-05, so switching
         * this on is the step that starts real traffic at real terminals. Every read
         * transfers a device's WHOLE attendance table (87,806 rows on Glass Door,
         * ~5 min on Camelot) because the protocol cannot be asked for "punches since
         * X" — so the frequency is the operator's cost decision, not a preference.
         *
         * `schedule` is a standard 5-field cron expression, evaluated in the
         * SERVER's timezone. Someone who wants "03:00 Gulf time" should say so rather
         * than have it silently mean whatever the app server is set to.
         */
        'attendance.sync_enabled' => [false, 'bool'],
        'attendance.sync_schedule' => ['0 3 * * *', 'cron'],

        /*
         * Daily clock sync (owner, 2026-10-11: "every day sync the time from server to
         * the biometrics, at 5 am"; casebook DC-019). Every terminal that can take a
         * clock is set to the server's NTP-synchronised time in its own zone and read
         * back. ON by default — unlike the attendance read it is one tiny command per
         * terminal — at 05:00 in the COMPANY's timezone (system.timezone).
         */
        'devices.clock_sync_enabled' => [true, 'bool'],
        'devices.clock_sync_schedule' => ['0 5 * * *', 'cron'],

        /*
         * Right after the clock sync, read the PAST DAY's attendance (yesterday and
         * today, in each terminal's own zone) from every terminal (owner, 2026-10-11:
         * "after that, a job to read attendance from all devices from the past day").
         * Queued behind the clock sync on each terminal, so the read runs once the
         * clock is right. A range read does not move the watermark and the unique
         * punch index keeps it free of duplicates. ON by default.
         */
        'devices.daily_read_enabled' => [true, 'bool'],

        /*
         * The attendance rules (owner, 2026-10-19), set on the Holidays screen and read
         * ONLY through App\Support\AttendanceRules::current(). Defaults are what the
         * fixed config used to be, so a company that never opens the screen is judged
         * exactly as before:
         *
         *   work_days                 0 = Sunday … 6 = Saturday; the rest is the weekend —
         *                             never absent or late, time worked is overtime
         *   shift_start / shift_end   "HH:MM", local time; end after start
         *   late_grace_minutes        Late = first punch after start + this
         *   early_leave_grace_minutes Left early = last punch before end − this
         *   leave_late_after_minutes  Left late = last punch at/after end + this
         */
        'attendance.work_days' => [[0, 1, 2, 3, 4], 'weekdays'],
        'attendance.shift_start' => ['09:00', 'time'],
        'attendance.shift_end' => ['18:00', 'time'],
        'attendance.late_grace_minutes' => [15, 'minutes'],
        'attendance.early_leave_grace_minutes' => [0, 'minutes'],
        'attendance.leave_late_after_minutes' => [15, 'minutes'],
    ];

    /** @return array<string,mixed> every known key with its current value */
    public function all(): array
    {
        $stored = Setting::query()->whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key');

        $out = [];
        foreach (self::DEFAULTS as $key => [$default, $type]) {
            $out[$key] = $stored->has($key) ? $this->cast($stored[$key], $type) : $default;
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? throw new \InvalidArgumentException("Unknown setting {$key}.");
    }

    public function set(string $key, mixed $value): mixed
    {
        if (! isset(self::DEFAULTS[$key])) {
            throw new \InvalidArgumentException("Unknown setting {$key}.");
        }

        $old = $this->get($key);
        $new = $this->cast($value, self::DEFAULTS[$key][1]);

        Setting::updateOrCreate(['tenant_id' => Tenancy::id(), 'key' => $key], ['value' => $new]);

        if ($old !== $new) {
            AuditLog::create([
                'tenant_id' => Tenancy::id(),
                'user_id' => Auth::id(),
                'action' => 'setting.updated',
                'auditable_type' => Setting::class,
                'auditable_id' => null,
                'changes' => ['key' => $key, 'old' => $old, 'new' => $new],
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 255),
            ]);
        }

        return $new;
    }

    private function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            /*
             * A timezone is only valid if PHP knows the identifier. Saved unchecked, a
             * typo becomes a device whose clock is set from an unknown zone, and the
             * failure shows up as wrong punches hours later rather than here.
             */
            'timezone' => $this->asTimezone($value),
            'cron' => $this->asCron($value),
            'weekdays' => $this->asWeekdays($value),
            'time' => $this->asTime($value),
            'minutes' => $this->asMinutes($value),
            default => $value,
        };
    }

    /**
     * Delegates to Timezone::isValid so the company setting and a device's own
     * override accept exactly the same set of values — see that method for why
     * both the IANA and the UTC-offset form are legal.
     */
    private function asTimezone(mixed $value): string
    {
        $name = trim((string) $value);

        if (! Timezone::isValid($name)) {
            throw new \InvalidArgumentException(Timezone::invalidMessage($name));
        }

        return $name;
    }

    /** "HH:MM", 24-hour, zero-padded so it sorts and compares as text. */
    private function asTime(mixed $value): string
    {
        $time = trim((string) $value);

        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            throw new \InvalidArgumentException("'{$time}' is not a time. Use 24-hour HH:MM, e.g. 07:30.");
        }

        return sprintf('%02d:%02d', $m[1], $m[2]);
    }

    /** A grace or threshold in whole minutes: 0 to 4 hours. */
    private function asMinutes(mixed $value): int
    {
        $minutes = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 240]]);

        if ($minutes === false) {
            throw new \InvalidArgumentException('Minutes must be a whole number from 0 to 240.');
        }

        return $minutes;
    }

    /**
     * A set of days of the week, 0–6, sorted and without repeats. At least one: a
     * company with no work days would make every report empty rather than wrong,
     * which is harder to notice.
     *
     * @return list<int>
     */
    private function asWeekdays(mixed $value): array
    {
        $days = is_array($value) ? $value : [];
        foreach ($days as $day) {
            if (filter_var($day, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]) === false) {
                throw new \InvalidArgumentException('Days of the week are 0 (Sunday) to 6 (Saturday).');
            }
        }

        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);

        if ($days === []) {
            throw new \InvalidArgumentException('At least one day of the week has to be a work day.');
        }

        return $days;
    }

    /**
     * Laravel schedules with dragonmantank/cron-expression, so that is what decides
     * whether an expression is valid — validating with anything else would accept
     * expressions the scheduler then refuses at run time, when nobody is watching.
     */
    private function asCron(mixed $value): string
    {
        $expr = trim((string) $value);

        if (! CronExpression::isValidExpression($expr)) {
            throw new \InvalidArgumentException(
                "'{$expr}' is not a valid cron expression. Five fields, e.g. '0 3 * * *' for 03:00 daily."
            );
        }

        return $expr;
    }
}
