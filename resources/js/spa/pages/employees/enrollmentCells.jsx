import MiniHands from './MiniHands';
import { Badge } from '../../shared/ui';

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
|
| AND A FOURTH THING, which is not a state but an attribution: enrollment belongs
| to ONE TERMINAL. `employees.fingerprint_slots` / `card_number` are a flat
| snapshot of whichever device was read last, so with two terminals they answer
| "how many" but not "where". `enrollments` (one row per device, from
| employee_device_maps) is the attributable truth; these cells name the terminal a
| number came from rather than letting it read as global.
*/

/**
 * The per-device enrollment rows, or null when the API did not send them.
 *
 * null and [] are different: null means the relation was not loaded and the flat
 * columns are all we have, [] means this employee is on no terminal at all.
 */
export function enrollmentsOf(employee) {
    return Array.isArray(employee.enrollments) ? employee.enrollments : null;
}

/**
 * The terminal the flat snapshot came from — the most recently read one, since
 * that read is what overwrote the employee's columns.
 */
export function snapshotSource(employee) {
    const rows = enrollmentsOf(employee) ?? [];
    const dated = rows.filter((r) => r.synced_at);
    if (dated.length === 0) return null;

    return dated.reduce((newest, r) =>
        new Date(r.synced_at).getTime() > new Date(newest.synced_at).getTime() ? r : newest);
}

/*
| What a roster cell should SHOW, and which terminal it came from.
|
| Reads the most recently synced PER-DEVICE row in preference to the flat
| employee columns, because the flat columns are not written by every read path.
| An employee imported from a second terminal has a real `employee_device_maps`
| row — fingerprint slots and all — while `employees.enrollment_synced_at` stays
| null, so checking the flat snapshot first rendered "—" for people who are
| demonstrably enrolled. OBSERVED: every Good Wheels-only employee showed no
| fingerprints and no card while their map row held slots [6, 7].
|
| It also keeps the glyph and its terminal label sourced from the SAME row.
| Drawing the flat slots while labelling them with the newest device named one
| terminal's data after another's.
|
| `read: false` is the "never read" state and must stay distinct from zero
| fingerprints (rule 10).
*/
export function rosterEnrollment(employee) {
    const source = snapshotSource(employee);

    if (source) {
        return {
            slots: source.fingerprint_slots,
            card: source.card_number,
            deviceName: source.device_name ?? `Device ${source.device_id}`,
            read: true,
        };
    }

    // No per-device row that has ever been read: the flat snapshot is all we have.
    return {
        slots: employee.fingerprint_slots,
        card: employee.card_number,
        deviceName: null,
        read: employee.enrollment_synced_at != null,
    };
}

/** Fingers + card for one terminal, as one short phrase. */
function describeRow(row) {
    const fingers = row.fingerprint_slots == null
        ? 'fingerprints unknown'
        : `${row.fingerprint_slots.length} fingerprint${row.fingerprint_slots.length === 1 ? '' : 's'}`;

    return `${fingers}, ${row.card_number ? `card #${row.card_number}` : 'no card'}`;
}

/*
| Which terminals hold this person, one badge each.
|
| This is the column that answers "from which device". It stays compact
| deliberately: badges wrap, where a stacked hand diagram per terminal would
| double the height of every roster row. The full per-finger detail is one click
| away in the detail dialog.
|
| Tones follow the rule that amber is "waiting or unproven", never "broken":
| a terminal that holds the person but no biometrics is amber (they cannot clock
| in yet), a terminal whose template table would not decode is amber too, and
| slate is "no information", which is not a failure.
*/
export function TerminalsCell({ employee }) {
    const rows = enrollmentsOf(employee);

    if (rows === null) {
        return <span className="text-sm text-slate-500" title="Per-terminal enrollment was not loaded">—</span>;
    }

    if (rows.length === 0) {
        return (
            <span className="text-sm text-slate-500" title="This employee is on no terminal, so they cannot clock in">
                No terminal
            </span>
        );
    }

    return (
        <span className="inline-flex flex-wrap items-center gap-1.5">
            {rows.map((row) => {
                const unknown = row.fingerprint_slots == null;
                const fingers = unknown ? null : row.fingerprint_slots.length;
                const bare = !unknown && fingers === 0 && !row.card_number;

                return (
                    <Badge
                        key={row.device_id}
                        tone={unknown || bare ? 'amber' : 'emerald'}
                        title={`${row.device_name ?? `Device ${row.device_id}`} — ${describeRow(row)}`}
                    >
                        <span>{row.device_name ?? `Device ${row.device_id}`}</span>
                        <span className="font-semibold tabular-nums">{unknown ? '?' : fingers}</span>
                        {row.card_number ? <CardIcon className="h-3 w-3" /> : null}
                    </Badge>
                );
            })}
        </span>
    );
}

/** Both hands with enrolled fingers filled, plus the count. */
export function FingerprintCell({ employee }) {
    const { slots, deviceName, read } = rosterEnrollment(employee);

    if (! read) {
        return <span className="text-sm text-slate-500" title="Not read from a terminal yet">—</span>;
    }

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
            {/*
              * The terminal these fingers came from — the same row the glyph was
              * drawn from. Without it the number reads as "on every device",
              * which it is not: another terminal can hold different fingers.
              */}
            {deviceName && (
                <span className="truncate text-xs text-slate-500" title={`Read from ${deviceName}`}>
                    {deviceName}
                </span>
            )}
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

/** Names the terminal in a card tooltip, so the number is never unattributed. */
function cardTitle(deviceName, phrase) {
    return deviceName ? `${phrase} on ${deviceName}` : `${phrase} on the terminal`;
}

/** The card number itself — the identifier is what an operator needs to match. */
export function CardCell({ employee }) {
    const { card: number, deviceName, read } = rosterEnrollment(employee);

    if (! read) {
        return <span className="text-sm text-slate-500" title="Not read from a terminal yet">—</span>;
    }

    // The glyph shows even with no card, so the column stays aligned and reads as
    // an empty card slot rather than a missing field.
    if (!number) {
        return (
            <span className="inline-flex items-center gap-1.5 align-middle text-sm text-slate-500" title={cardTitle(deviceName, 'No card registered')}>
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
                    : cardTitle(deviceName, `Card #${number} registered`)
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

/*
| Newest enrollment read across the roster, as a short relative string.
|
| Considers per-device reads as well as the flat column. Reading only
| `enrollment_synced_at` reported the roster as last read in July while a
| terminal had in fact been read that morning — the flat column is not written
| by every read path.
*/
export function syncedAgo(rows) {
    const stamps = rows
        .flatMap((r) => [
            r.enrollment_synced_at,
            ...(Array.isArray(r.enrollments) ? r.enrollments.map((e) => e.synced_at) : []),
        ])
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
