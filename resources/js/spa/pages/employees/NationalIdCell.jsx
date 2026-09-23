import { useEffect, useRef, useState } from 'react';
import { Badge, Spinner } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';

/*
| The national ID, and the fastest honest way to enter three hundred of them.
|
| WHY INLINE AND NOT A MODAL: this is a backlog, not an occasional edit. Every
| imported employee starts without a national id, and until they have one their
| attendance cannot reach payroll. A dialog per person means open, type, save, close,
| find the next gap — five interactions for one field. Here it is: click, type, Enter,
| and focus lands on the next person still missing one.
|
| WHY THE VALUE IS NEVER SHOWN IN FULL: it is government id. The API returns only
| whether one exists and a masked tail, which is enough to confirm the right document
| went in, and not enough to harvest the roster. Editing replaces, it does not reveal —
| the input starts EMPTY even when a value is already stored, because prefilling it
| would mean sending the plaintext to the browser.
*/
export default function NationalIdCell({ employee, canEdit, onSave }) {
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const inputRef = useRef(null);

    useEffect(() => {
        if (editing) inputRef.current?.focus();
    }, [editing]);

    const has = Boolean(employee.has_national_id);

    function start() {
        if (!canEdit || saving) return;
        setValue('');
        setError(null);
        setEditing(true);
    }

    function cancel() {
        setEditing(false);
        setValue('');
        setError(null);
    }

    async function commit(advance) {
        const trimmed = value.trim();

        if (trimmed === '') {
            cancel();
            return;
        }

        setSaving(true);
        setError(null);
        try {
            await onSave(employee, trimmed);
            setEditing(false);
            setValue('');
            // Hand focus to the next person still missing one, so a run of entries is
            // one continuous action rather than a hunt between each.
            if (advance) focusNextEmpty(employee.id);
        } catch (err) {
            // Kept on the field, not toasted: a 422 belongs where it can be corrected,
            // and the half-typed value stays put (rule 2).
            setError(err?.message || 'Could not save.');
        } finally {
            setSaving(false);
        }
    }

    if (!editing) {
        if (!canEdit) {
            return has
                ? <span className="font-mono text-xs text-slate-600">{employee.national_id_masked ?? '••••'}</span>
                : <Badge tone="amber">Missing</Badge>;
        }

        return (
            <button
                type="button"
                data-national-id-trigger={employee.id}
                data-national-id-empty={has ? undefined : 'true'}
                onClick={start}
                aria-label={has ? `Replace national ID for ${employee.name}` : `Add national ID for ${employee.name}`}
                className={`rounded-lg px-1.5 py-1 text-left transition-colors hover:bg-violet-50 ${FOCUS}`}
            >
                {has ? (
                    <span className="font-mono text-xs text-slate-700">{employee.national_id_masked ?? '••••'}</span>
                ) : (
                    <Badge tone="amber">+ Add</Badge>
                )}
            </button>
        );
    }

    return (
        <div className="min-w-[10rem]">
            <div className="flex items-center gap-1.5">
                <input
                    ref={inputRef}
                    value={value}
                    disabled={saving}
                    inputMode="numeric"
                    autoComplete="off"
                    placeholder={has ? 'New national ID' : 'National ID'}
                    aria-label={`National ID for ${employee.name}`}
                    aria-invalid={error ? 'true' : undefined}
                    onChange={(e) => setValue(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') { e.preventDefault(); commit(true); }
                        if (e.key === 'Escape') { e.preventDefault(); cancel(); }
                    }}
                    // Leaving the field commits too. Typing a number and clicking away
                    // should not silently discard it.
                    onBlur={() => { if (!saving) commit(false); }}
                    className={`w-36 rounded-lg border bg-white px-2 py-1 font-mono text-xs text-slate-900 focus:outline-none focus:ring-2 ${
                        error
                            ? 'border-rose-300 focus:border-rose-400 focus:ring-rose-100'
                            : 'border-violet-300 focus:border-violet-400 focus:ring-violet-100'
                    }`}
                />
                {saving && <Spinner className="h-4 w-4 shrink-0" />}
            </div>
            {error ? (
                <span role="alert" className="mt-1 block text-xs font-medium text-rose-600">{error}</span>
            ) : (
                <span className="mt-1 block text-[11px] text-slate-500">Enter saves · Esc cancels</span>
            )}
        </div>
    );
}

/**
 * Moves to the next employee on screen who still has no national id.
 *
 * Reads the DOM rather than the row array on purpose: it must follow what the operator
 * is actually looking at, which is the filtered and sorted list, not the unfiltered
 * data behind it.
 */
function focusNextEmpty(afterId) {
    requestAnimationFrame(() => {
        const all = Array.from(document.querySelectorAll('[data-national-id-trigger]'));
        const index = all.findIndex((el) => el.dataset.nationalIdTrigger === String(afterId));
        const next = all.slice(index + 1).find((el) => el.dataset.nationalIdEmpty === 'true');
        next?.click();
    });
}
