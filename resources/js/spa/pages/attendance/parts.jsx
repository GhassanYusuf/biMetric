import { useState } from 'react';
import { FOCUS } from '../../shared/theme';
import { Button, TextField } from '../../shared/ui';

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

/*
| National ID -> this person's punches.
|
| Lives here, not in either presentation file, so desktop and mobile cannot drift
| apart (rule 5): one field, one keyboard behaviour, one set of rules about what
| is kept.
|
| THE TYPED NUMBER IS HELD HERE AND NOWHERE ELSE. On a match it is wiped
| immediately and the screen shows a name instead — a terminal left unattended in
| an HR office then displays "Ali Jaafar", not somebody's government id. On a miss
| it is deliberately LEFT in place: the likeliest cause is a mistyped digit, and
| making somebody retype sixteen of them to fix one is its own failure.
|
| autoComplete="off" is not decoration. A browser that remembers this field offers
| one employee's national id while an operator is looking up another's.
*/
export function NationalIdLookup({ onLookup, busy }) {
    const [value, setValue] = useState('');

    async function submit(event) {
        event.preventDefault();
        if (await onLookup(value)) setValue('');
    }

    return (
        <div className="space-y-1.5">
            <form onSubmit={submit} className="flex items-end gap-3">
                <div className="min-w-[11rem] flex-1">
                    <TextField
                        label="National ID"
                        className="font-mono"
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                        placeholder="Whole number"
                        autoComplete="off"
                        spellCheck={false}
                        disabled={busy}
                    />
                </div>
                <Button type="submit" loading={busy} disabled={busy || !value.trim()}>
                    Find
                </Button>
            </form>
            {/*
              | Says the one thing that surprises people: this is not the search box
              | from the Employees screen. It matches the whole document or nothing,
              | because the column it searches is a hash — there is no prefix to
              | match on. Spacing and dashes are normalised away, so those are safe.
            */}
            <p className="text-xs text-slate-500">
                Matches the whole number. Spaces and dashes do not matter; a partial number will not match.
            </p>
        </div>
    );
}

/*
| Persistent page STATE, not an event (rule 2): while this is on screen the punch
| list below is one person's, and that stays true until it is dismissed. The toast
| that announced the match is long gone by then.
|
| Rendered in the same place on both presentations — directly under the header,
| never inside a collapsible filter panel. On mobile the filters collapse, and a
| list silently narrowed to one employee with nothing on screen saying so is how
| somebody concludes the terminal stopped recording punches.
*/
export function MatchedEmployee({ employee, onClear }) {
    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl bg-violet-50 px-4 py-3 ring-1 ring-violet-200">
            <span className="text-sm text-violet-900">
                Showing punches for <span className="font-semibold">{employee.name}</span>
                {employee.employee_code && (
                    <span className="font-mono text-xs text-violet-800"> · {employee.employee_code}</span>
                )}
            </span>
            <button
                type="button"
                onClick={onClear}
                className={`ml-auto rounded-lg px-2.5 py-1 text-xs font-semibold text-violet-800 hover:bg-violet-100 ${FOCUS}`}
            >
                Show everyone
            </button>
        </div>
    );
}
