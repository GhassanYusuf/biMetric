// Shared between the desktop and mobile report, so a number is formatted the
// same on both and neither can quietly round differently.

/** "1 Sep – 14 Sep 2026". The server always sends a resolved range. */
export function rangeLabel(report) {
    if (!report?.from || !report?.to) return 'Last 30 days';

    const from = new Date(`${report.from}T00:00:00`);
    const to = new Date(`${report.to}T00:00:00`);
    const opts = { day: 'numeric', month: 'short' };

    return `${from.toLocaleDateString([], opts)} – ${to.toLocaleDateString([], { ...opts, year: 'numeric' })}`;
}

export function hours(value) {
    return `${Number(value ?? 0).toFixed(1)} h`;
}
