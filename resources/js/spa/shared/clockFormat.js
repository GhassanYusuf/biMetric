import { useSyncExternalStore } from 'react';

/*
| 12- or 24-hour clock times (owner's request, 2026-10-11): 12-hour unless the viewer
| switches to 24 on the page. A per-viewer display preference, so it lives in this
| browser's storage — wrapped, because storage can be blocked or empty, and the
| page must then simply show the 12-hour default.
|
| One module-level value with subscribers rather than React state, because the
| formatter (`formatClock`) is called from plain functions — the report's model
| and chart labels — that have no hook of their own.
*/

const KEY = 'clock-format';
const listeners = new Set();

function readStored() {
    try {
        return localStorage.getItem(KEY) === '24' ? '24' : '12';
    } catch {
        return '12';
    }
}

let current = readStored();

export function getClockFormat() {
    return current;
}

export function setClockFormat(next) {
    current = next === '24' ? '24' : '12';
    try {
        localStorage.setItem(KEY, current);
    } catch {
        // Not remembered across visits; still applies for this one.
    }
    listeners.forEach((fn) => fn());
}

function subscribe(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

/** [format, setFormat] — re-renders the caller when the format changes. */
export function useClockFormat() {
    const format = useSyncExternalStore(subscribe, getClockFormat, getClockFormat);
    return [format, setClockFormat];
}

const pad = (n) => String(n).padStart(2, '0');

/** Minutes after local midnight as "8:05 AM" or "08:05". */
export function formatClock(minutes, format = current) {
    if (minutes == null) return '—';
    const m = Math.round(minutes);
    const h = Math.floor(m / 60) % 24;
    const min = pad(((m % 60) + 60) % 60);
    if (format === '24') return `${pad(h)}:${min}`;
    return `${h % 12 === 0 ? 12 : h % 12}:${min} ${h < 12 ? 'AM' : 'PM'}`;
}

/**
 * Compact form for chart axis ticks, where a full "10:30 AM" does not fit the
 * margin: in 12-hour, the hour reads "7 AM" and a half-hour between two of those
 * reads "7:30" — its neighbours carry the AM/PM. 24-hour is unchanged.
 */
export function formatClockTick(minutes, format = current) {
    if (minutes == null || format === '24') return formatClock(minutes, format);
    const m = Math.round(minutes);
    const h = Math.floor(m / 60) % 24;
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return m % 60 === 0 ? `${h12} ${h < 12 ? 'AM' : 'PM'}` : `${h12}:${pad(m % 60)}`;
}
