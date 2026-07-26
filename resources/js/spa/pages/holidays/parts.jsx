import { Badge } from '../../shared/ui';

// Holiday dates are plain Y-m-d strings (no timezone), so they are parsed as
// local dates explicitly — `new Date('2026-01-01')` would be parsed as UTC and
// can render as the previous day west of Greenwich.
export function parseDate(ymd) {
    const [y, m, d] = ymd.split('-').map(Number);
    return new Date(y, m - 1, d);
}

export function formatDate(ymd) {
    return parseDate(ymd).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function startOfToday() {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), now.getDate());
}

export function dayState(ymd) {
    const date = parseDate(ymd);
    const today = startOfToday();
    if (date.getTime() === today.getTime()) return 'today';
    return date < today ? 'past' : 'upcoming';
}

export function DayBadge({ ymd }) {
    const state = dayState(ymd);
    if (state === 'today') return <Badge tone="violet">Today</Badge>;
    if (state === 'past') return <Badge tone="slate">Past</Badge>;
    return <Badge tone="emerald">Upcoming</Badge>;
}

// Splits the list into upcoming (incl. today) and past, newest-first for past.
export function partition(rows) {
    const upcoming = rows.filter((h) => dayState(h.date) !== 'past');
    const past = rows.filter((h) => dayState(h.date) === 'past').reverse();
    return { upcoming, past };
}
