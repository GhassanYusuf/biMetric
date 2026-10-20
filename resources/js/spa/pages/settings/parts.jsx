// Helpers for the settings screen's two technical fields: time zones and cron.
//
// Both lists are deliberately short. A select of all ~400 IANA zones is a worse
// control than a short one plus a free-text box, because the operator is choosing
// THEIR company's zone, not browsing a database.
import { useMemo } from 'react';

/**
 * Zones offered by default. `Intl.supportedValuesOf` gives the browser's full list,
 * which is authoritative but far too long to scroll. These are the ones a company in
 * this region plausibly operates in, and the free-text field covers anything else.
 */
export const COMMON_ZONES = [
    'Asia/Bahrain',
    'Asia/Riyadh',
    'Asia/Kuwait',
    'Asia/Dubai',
    'Asia/Qatar',
    'Asia/Oman',
    'Asia/Karachi',
    'Asia/Kolkata',
    'Africa/Cairo',
    'Europe/London',
    'Europe/Istanbul',
    'Europe/Moscow',
    'UTC',
];

/** Every zone the browser knows, for the datalist behind the free-text box. */
export function useAllZones() {
    return useMemo(() => {
        try {
            // Not available in older Safari; the shortlist plus free text still works.
            if (typeof Intl !== 'undefined' && typeof Intl.supportedValuesOf === 'function') {
                return Intl.supportedValuesOf('timeZone');
            }
        } catch {
            // Fall through to the empty list.
        }
        return [];
    }, []);
}

/** How a zone reads at a moment in time, e.g. "GMT+3". */
export function zoneAbbrev(zone) {
    try {
        return new Intl.DateTimeFormat('en-GB', {
            timeZone: zone,
            timeZoneName: 'short',
        })
            .formatToParts(new Date())
            .find((p) => p.type === 'timeZoneName')?.value;
    } catch {
        return null;
    }
}

/** Current UTC offset of a zone, e.g. "+03:00". Null if it cannot be resolved. */
export function zoneOffset(zone) {
    try {
        // en-GB gives "+03:00" / "GMT+03:00" formatting that is stable across locales.
        const parts = new Intl.DateTimeFormat('en-GB', {
            timeZone: zone,
            timeZoneName: 'longOffset',
        }).formatToParts(new Date());
        const value = parts.find((p) => p.type === 'timeZoneName')?.value;
        return value === 'GMT' ? '+00:00' : (value ?? null);
    } catch {
        return null;
    }
}

/** "Asia/Bahrain (+03:00)" — the label used in the device table. */
export function zoneLabel(zone) {
    if (!zone) return '—';
    const offset = zoneOffset(zone);
    return offset ? `${zone} (${offset})` : zone;
}

/** "3 minutes ago" / "in 2 hours" — for the last/next run columns. */
export function relativeFromNow(iso, now = Date.now()) {
    if (!iso) return null;
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return null;

    // The server sends a zone offset, not a trailing Z, and the browser would read
    // "+03:00" as local time. Parse it as an explicit offset instead.
    const parsed = new Date(iso.replace(' ', 'T'));
    const base = Number.isNaN(parsed.getTime()) ? then : parsed.getTime();
    const diffSec = Math.round((base - now) / 1000);
    const past = diffSec < 0;
    const s = Math.abs(diffSec);

    let text;
    if (s < 60) text = `${s} second${s === 1 ? '' : 's'}`;
    else if (s < 3600) {
        const m = Math.round(s / 60);
        text = `${m} minute${m === 1 ? '' : 's'}`;
    } else if (s < 86400) {
        const h = Math.round(s / 3600);
        text = `${h} hour${h === 1 ? '' : 's'}`;
    } else {
        const d = Math.round(s / 86400);
        text = `${d} day${d === 1 ? '' : 's'}`;
    }

    return past ? `${text} ago` : `in ${text}`;
}

/** Drift as a short phrase; anything under a minute is noise, not a finding. */
export function driftLabel(seconds) {
    if (seconds === null || seconds === undefined) return null;
    const abs = Math.abs(seconds);
    if (abs < 60) return 'under a minute';
    const mins = Math.round(abs / 60);
    if (mins < 60) return `${mins} min`;
    const hours = Math.floor(mins / 60);
    const rem = mins % 60;
    return rem ? `${hours}h ${rem}m` : `${hours}h`;
}
