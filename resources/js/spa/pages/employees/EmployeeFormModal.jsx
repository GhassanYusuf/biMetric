import { useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { Button, Card, Spinner, TextField } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import DeviceTargets, { enrolledTargets, writableTargets } from './DeviceTargets';
import { useEscapeKey } from '../../shared/useEscapeKey';

/*
| Add / edit form (modal on desktop, sheet on mobile). Validation errors come back
| as JSON and render inline with no page reload.
|
| The employee code is SUGGESTED, not imposed: it prefills with the lowest free
| code so the common case is one less thing to think about, and stays a plain
| editable field for anyone who numbers staff their own way. Once you type in it,
| the suggestion stops touching your value. On an EDIT there is no suggestion — the
| code is theirs already, and the terminals address them by a PIN derived from it.
|
| THE DEVICE SECTION IS THE POINT OF THE EDIT FORM. Saving writes the database and
| nothing else; a name corrected here would otherwise stay wrong on every terminal
| until somebody remembered to open the enrollment dialog and push, device by
| device, with nothing on screen saying so. The checkboxes are what turn one save
| into one save plus N queued writes, and they are shown CHECKED on an edit —
| updating a person you have just corrected is the expected outcome — and UNCHECKED
| on a create, where which terminals a new joiner belongs on is a real decision
| nobody else can make.
*/
export default function EmployeeFormModal({ employee = null, devices = [], onSubmit, onClose }) {
    const isEdit = !!employee;

    const [form, setForm] = useState({
        name: employee?.name ?? '',
        employee_code: employee?.employee_code ?? '',
        email: employee?.email ?? '',
        department: employee?.department ?? '',
        is_active: employee?.is_active ?? true,
    });
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [suggestion, setSuggestion] = useState(null);
    const [loadingSuggestion, setLoadingSuggestion] = useState(!isEdit);

    /*
     * WHICH TERMINALS ARE OFFERED, and why the two modes differ.
     *
     * Editing: the ones they are enrolled on RIGHT NOW — `enrolledTargets` drops
     * closed mappings, which are PINs they used to hold and which somebody else may
     * hold today. Creating: every terminal that can take a write, since they are on
     * none yet. The list itself is one shared component, so this form and the Push
     * dialog cannot drift about what they are offering.
     */
    const targets = isEdit ? enrolledTargets(employee) : writableTargets(devices);

    const [pushTo, setPushTo] = useState(() => (isEdit ? targets.map((t) => t.id) : []));
    // Tracks whether the operator has taken over the field, so a late-arriving
    // suggestion can never overwrite something they typed.
    const codeTouched = useRef(false);

    useEscapeKey(onClose, !submitting);

    useEffect(() => {
        // An existing employee already has a code, and changing it changes the PIN
        // the terminals know them by. Never suggest over it.
        if (isEdit) return undefined;

        let cancelled = false;

        api.get('/employees/next-code')
            .then((res) => {
                if (cancelled) return;
                setSuggestion(res.data.code);
                if (!codeTouched.current) {
                    setForm((f) => (f.employee_code === '' ? { ...f, employee_code: res.data.code } : f));
                }
            })
            // A failed suggestion is not an error worth showing — the field is
            // still perfectly usable by hand.
            .catch(() => {})
            .finally(() => {
                if (!cancelled) setLoadingSuggestion(false);
            });

        return () => { cancelled = true; };
    }, [isEdit]);

    function set(key, value) {
        if (key === 'employee_code') codeTouched.current = true;
        setForm((f) => ({ ...f, [key]: value }));
    }

    async function submit(e) {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        try {
            await onSubmit(
                {
                    name: form.name,
                    employee_code: form.employee_code,
                    email: form.email || null,
                    department: form.department || null,
                    // Only the update endpoint accepts it; sending it on create
                    // would be a silently ignored field.
                    ...(isEdit ? { is_active: form.is_active } : {}),
                },
                // The terminals to write to AFTER the save succeeds. The caller
                // owns that second step, because it is a different operation with
                // its own outcome — the save can succeed while a terminal is
                // read-only, and both facts have to reach the operator.
                pushTo,
            );
        } catch (err) {
            if (err instanceof ApiError) setErrors(err.errors || {});
            else setErrors({ name: ['Something went wrong.'] });
        } finally {
            setSubmitting(false);
        }
    }

    const usingSuggestion = suggestion != null && form.employee_code === suggestion;

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-slate-900/40 p-0 sm:items-center sm:p-4"
            onClick={submitting ? undefined : onClose}
            role="presentation"
        >
            <Card className="w-full max-w-md rounded-b-none sm:rounded-2xl" onClick={(e) => e.stopPropagation()}>
                <form onSubmit={submit} className="p-5">
                    <h2 className="mb-4 text-lg font-semibold tracking-[-0.01em] text-slate-900">
                        {isEdit ? `Edit ${employee.name}` : 'Add employee'}
                    </h2>
                    <div className="space-y-3">
                        <TextField
                            label="Name"
                            value={form.name}
                            onChange={(e) => set('name', e.target.value)}
                            error={errors.name?.[0]}
                            required
                            autoFocus
                        />

                        <div>
                            <TextField
                                label="Employee code"
                                value={form.employee_code}
                                onChange={(e) => set('employee_code', e.target.value)}
                                error={errors.employee_code?.[0]}
                                inputMode="numeric"
                                required
                            />
                            <div className="mt-1 flex min-h-[18px] items-center gap-2 text-xs">
                                {isEdit ? (
                                    /*
                                      | Page STATE, not an event, so it is content and
                                      | not a toast (rule 2) — and it is the warning
                                      | that matters most on this form: the code is how
                                      | a terminal is told who this is.
                                    */
                                    <span className="text-slate-500">
                                        Changing this changes the code new terminals will use for them.
                                    </span>
                                ) : loadingSuggestion ? (
                                    <span className="inline-flex items-center gap-1.5 text-slate-500">
                                        <Spinner className="h-3 w-3" /> finding the next free code…
                                    </span>
                                ) : suggestion == null ? (
                                    <span className="text-slate-500">Enter any code the terminal should use.</span>
                                ) : usingSuggestion ? (
                                    <span className="text-slate-500">
                                        Lowest free code. Type over it to choose your own.
                                    </span>
                                ) : (
                                    <>
                                        <span className="text-slate-500">Lowest free is {suggestion}.</span>
                                        <button
                                            type="button"
                                            onClick={() => setForm((f) => ({ ...f, employee_code: suggestion }))}
                                            className="font-medium text-violet-700 underline decoration-violet-300 underline-offset-2 hover:text-violet-800"
                                        >
                                            Use it
                                        </button>
                                    </>
                                )}
                            </div>
                        </div>

                        <TextField label="Email (optional)" type="email" value={form.email} onChange={(e) => set('email', e.target.value)} error={errors.email?.[0]} />
                        <TextField label="Department (optional)" value={form.department} onChange={(e) => set('department', e.target.value)} error={errors.department?.[0]} />

                        {isEdit && (
                            <label className="flex items-center gap-2.5 pt-1">
                                <input
                                    type="checkbox"
                                    checked={form.is_active}
                                    onChange={(e) => set('is_active', e.target.checked)}
                                    className={`h-4 w-4 rounded border-slate-300 text-violet-600 ${FOCUS}`}
                                />
                                <span className="text-sm text-slate-700">Active</span>
                            </label>
                        )}
                    </div>

                    {/*
                      | THE DEVICE SECTION. Without it a save changes this app and
                      | leaves every terminal carrying the old name, silently.
                    */}
                    <div className="mt-4 border-t border-slate-100 pt-4">
                        <div className="flex items-baseline justify-between gap-3">
                            <span className="text-sm font-medium text-slate-700">
                                {isEdit ? 'Update them on' : 'Add them to'}
                            </span>
                            {targets.length > 1 && (
                                <button
                                    type="button"
                                    onClick={() => setPushTo(pushTo.length === targets.length ? [] : targets.map((t) => t.id))}
                                    className={`rounded text-xs font-medium text-violet-700 hover:underline ${FOCUS}`}
                                >
                                    {pushTo.length === targets.length ? 'None' : 'All'}
                                </button>
                            )}
                        </div>

                        <DeviceTargets
                            targets={targets}
                            selected={pushTo}
                            onChange={setPushTo}
                            compareName={form.name}
                            emptyHint={
                                isEdit
                                    ? 'They are not enrolled on any terminal yet. Use Push to put them on one.'
                                    : 'No terminal here can be written to. Add one, or read its roster first.'
                            }
                        />

                        {pushTo.length > 0 && (
                            <p className="mt-2 text-xs text-slate-500">
                                Saved first, then written to {pushTo.length} terminal{pushTo.length === 1 ? '' : 's'} —
                                this waits for each one, so it may take a few seconds.
                            </p>
                        )}
                    </div>
                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>Cancel</Button>
                        <Button type="submit" loading={submitting}>
                            {submitting ? (isEdit ? 'Saving…' : 'Adding…') : isEdit ? 'Save' : 'Add'}
                        </Button>
                    </div>
                </form>
            </Card>
        </div>
    );
}
