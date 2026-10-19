<?php

namespace App\Support;

use App\Models\Device;
use App\Services\Settings\TenantSettings;

/**
 * The timezone a terminal's clock is read and written in (owner, 2026-10-05).
 *
 * ONE PLACE, because this was four: ZktecoDriver built its protocol config from
 * `$device->timezone ?: config('attendance.device_timezone')` in two places,
 * C3Driver once, and AttendanceSyncService once. A company setting added to that
 * pattern would have been read by none of them, so "set the system timezone" would
 * have looked like it worked while every device stayed on the env var.
 *
 * PRECEDENCE, most specific first:
 *   1. devices.timezone          — this machine is somewhere else (owner's ask,
 *                                  2026-10-05: a terminal in another country)
 *   2. system.timezone           — the company default, from TenantSettings
 *   3. config('attendance.device_timezone') — last resort, so a queue worker or
 *                                  console command with no tenant still behaves
 *
 * The third step is why nothing here throws when tenancy is unset: a scheduled job
 * or an artisan command can reach this with no tenant bound, and a timezone lookup
 * must not be the thing that fails.
 */
final class Timezone
{
    /** The company's timezone, or the env fallback when there is no tenant bound. */
    public static function system(): string
    {
        try {
            if (Tenancy::check()) {
                return (string) app(TenantSettings::class)->get('system.timezone');
            }
        } catch (\Throwable) {
            // No tenant, or no settings row yet. The env default is the right answer
            // in both cases, and better than failing a punch read over a setting.
        }

        return (string) config('attendance.device_timezone', '+03:00');
    }

    /** What this terminal uses: its own override if it has one, else the company default. */
    public static function forDevice(Device $device): string
    {
        $own = $device->timezone;

        return is_string($own) && trim($own) !== ''
            ? trim($own)
            : self::system();
    }

    /** True when this terminal is deliberately somewhere else. */
    public static function deviceOverrides(Device $device): bool
    {
        $own = $device->timezone;

        return is_string($own) && trim($own) !== '';
    }

    /**
     * The wall-clock time a terminal on $tz should be showing right now.
     *
     * Returned rather than applied so callers can show it before writing it — setting
     * a device clock is a real hardware write and the operator should see the number
     * they are about to send.
     */
    public static function nowFor(Device $device, ?\DateTimeInterface $at = null): \DateTimeImmutable
    {
        $tz = new \DateTimeZone(self::forDevice($device));
        $timestamp = $at === null ? time() : $at->getTimestamp();

        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone($tz);
    }

    /**
     * Is this a time zone we can actually use? ONE validator, because the company
     * setting and a device's own override are the same decision made in two places,
     * and two validators eventually disagree about what they accept.
     *
     * IANA name  — 'Asia/Bahrain', 'Europe/London'. Carries DST rules, so a terminal
     *              in a DST country stays correct through the changeover.
     * UTC offset — '+03:00'. What this project already stores (tenants.timezone is
     *              '+03:00' and config('attendance.device_timezone') has always
     *              produced that), but frozen: '+00:00' in London is wrong for half
     *              the year. Still accepted so nothing already written stops working.
     *
     * New values should be the IANA form.
     */
    public static function isValid(mixed $value): bool
    {
        $name = trim((string) $value);

        if (in_array($name, timezone_identifiers_list(), true)) {
            return true;
        }

        if (! preg_match('/^(?:UTC|GMT)?[+-]\d{1,2}(?::?\d{2})?$/i', $name)) {
            return false;
        }

        try {
            new \DateTimeZone($name);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /** The message both the setting and the device field show when it is refused. */
    public static function invalidMessage(string $value): string
    {
        return sprintf(
            "'%s' is not a time zone. Use an IANA name like 'Asia/Bahrain' (preferred, "
                .'handles daylight saving) or a UTC offset like \'+03:00\'.',
            $value
        );
    }
}
