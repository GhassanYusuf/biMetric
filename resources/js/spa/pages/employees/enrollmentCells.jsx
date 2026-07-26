import MiniHands from './MiniHands';

/*
| Roster cells for enrollment read off the terminal.
|
| Three states have to stay distinguishable, and collapsing any two would be a
| lie about the hardware:
|
|   never read   enrollment_synced_at is null — we have not asked the device
|   unknown      read succeeded but the firmware's template table would not
|                decode, so the count is genuinely not known (see
|                ZktecoDevice::decodeTemplateRecords, which fails safe to [])
|   a real value what the device's own tables say
*/

/** Both hands with enrolled fingers filled, plus the count. */
export function FingerprintCell({ employee }) {
    if (employee.enrollment_synced_at == null) {
        return <span className="text-sm text-slate-500" title="Not read from a terminal yet">—</span>;
    }

    const slots = employee.fingerprint_slots;

    return (
        <span className="inline-flex items-center gap-2">
            <MiniHands slots={slots} />
            <span
                className={`text-sm font-semibold tabular-nums ${
                    slots == null ? 'text-amber-700' : slots.length > 0 ? 'text-slate-800' : 'text-slate-500'
                }`}
            >
                {slots == null ? '?' : slots.length}
            </span>
        </span>
    );
}

/** Contactless-card glyph. Sized to sit on a text baseline beside the number. */
export function CardIcon({ className = 'h-4 w-4' }) {
    return (
        <svg
            className={`shrink-0 ${className}`}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            strokeLinecap="round"
            aria-hidden="true"
        >
            <rect x="2.5" y="5.5" width="19" height="13" rx="2.5" />
            <path d="M2.5 10h19M6 14.5h4" />
        </svg>
    );
}

/** The card number itself — the identifier is what an operator needs to match. */
export function CardCell({ employee }) {
    if (employee.enrollment_synced_at == null) {
        return <span className="text-sm text-slate-500" title="Not read from a terminal yet">—</span>;
    }

    const number = employee.card_number;

    // The glyph shows even with no card, so the column stays aligned and reads as
    // an empty card slot rather than a missing field.
    if (!number) {
        return (
            <span className="inline-flex items-center gap-1.5 align-middle text-sm text-slate-500" title="No card registered on the terminal">
                <CardIcon className="h-4 w-4 text-slate-400" />
                None
            </span>
        );
    }

    // 0xFFFFFFFF is all bits set: on many firmwares that is the "no card"
    // marker rather than a real card, so it is flagged rather than trusted.
    const sentinel = number === 4294967295;

    return (
        <span
            className={`inline-flex items-center gap-1.5 align-middle ${sentinel ? 'text-amber-700' : 'text-slate-800'}`}
            title={
                sentinel
                    ? `${number} is 0xFFFFFFFF (all bits set) — on many firmwares this means no card rather than a real number`
                    : `Card #${number} registered on the terminal`
            }
        >
            <CardIcon className={`h-4 w-4 ${sentinel ? 'text-amber-600' : 'text-slate-400'}`} />
            <span className="font-mono text-xs font-semibold tabular-nums">
                {String(number)}
            </span>
            {sentinel && <span aria-hidden="true">⚠</span>}
        </span>
    );
}

/** Newest enrollment read across the roster, as a short relative string. */
export function syncedAgo(rows) {
    const stamps = rows
        .map((r) => r.enrollment_synced_at)
        .filter(Boolean)
        .map((iso) => new Date(iso).getTime());

    if (stamps.length === 0) return null;

    const at = new Date(Math.max(...stamps));
    const mins = Math.round((Date.now() - at.getTime()) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    return at.toLocaleString();
}
