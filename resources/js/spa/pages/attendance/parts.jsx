import { Button } from '../../shared/ui';

/*
| Verify-method decoding, matching the devices_table schema comment and the
| legacy Inertia page exactly: 0 password, 1 fingerprint, 2 card, 15 face.
|
| CAUTION: this database also contains methods 3 and 4, which are NOT in that
| documented set — some ZKTeco firmware numbers verify modes differently. Those
| render as the raw number rather than a guessed label, because mislabelling a
| punch's verification method is worse than showing it undecoded. Resolve the
| real mapping against the hardware before adding entries here.
*/
const VERIFY_METHODS = {
    0: 'Password',
    1: 'Fingerprint',
    2: 'Card',
    15: 'Face',
};

export function verifyLabel(method) {
    if (method == null) return '—';
    return VERIFY_METHODS[method] ?? String(method);
}

export function formatPunch(iso) {
    if (!iso) return { date: '—', time: '' };
    const d = new Date(iso);
    return {
        date: d.toLocaleDateString(),
        time: d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
    };
}

// Groups punches by calendar day for the mobile list's day headers.
export function groupByDay(rows) {
    const groups = [];
    let current = null;
    for (const row of rows) {
        const day = formatPunch(row.punched_at).date;
        if (!current || current.day !== day) {
            current = { day, rows: [] };
            groups.push(current);
        }
        current.rows.push(row);
    }
    return groups;
}

// Shared pager driven by the API's meta.pagination block.
export function Pager({ pagination, onPage, loading }) {
    if (!pagination || (pagination.last_page ?? 1) <= 1) return null;

    const { current_page: current, last_page: last, total } = pagination;

    return (
        <div className="flex items-center justify-between gap-3">
            <span className="text-xs text-slate-500">
                Page {current} of {last} · {(total ?? 0).toLocaleString()} punches
            </span>
            <div className="flex gap-2">
                <Button variant="secondary" className="min-h-[36px] px-3 text-xs" disabled={loading || current <= 1} onClick={() => onPage(current - 1)}>
                    Previous
                </Button>
                <Button variant="secondary" className="min-h-[36px] px-3 text-xs" disabled={loading || current >= last} onClick={() => onPage(current + 1)}>
                    Next
                </Button>
            </div>
        </div>
    );
}
