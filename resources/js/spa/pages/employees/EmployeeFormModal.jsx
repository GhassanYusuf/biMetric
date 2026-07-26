import { useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { Button, Card, Spinner, TextField } from '../../shared/ui';
import { useEscapeKey } from '../../shared/useEscapeKey';

/*
| Create form (modal on desktop, sheet on mobile). Validation errors come back as
| JSON and render inline with no page reload.
|
| The employee code is SUGGESTED, not imposed: it prefills with the lowest free
| code so the common case is one less thing to think about, and stays a plain
| editable field for anyone who numbers staff their own way. Once you type in it,
| the suggestion stops touching your value.
*/
export default function EmployeeFormModal({ onSubmit, onClose }) {
    const [form, setForm] = useState({ name: '', employee_code: '', email: '', department: '' });
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [suggestion, setSuggestion] = useState(null);
    const [loadingSuggestion, setLoadingSuggestion] = useState(true);
    // Tracks whether the operator has taken over the field, so a late-arriving
    // suggestion can never overwrite something they typed.
    const codeTouched = useRef(false);

    useEscapeKey(onClose, !submitting);

    useEffect(() => {
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
    }, []);

    function set(key, value) {
        if (key === 'employee_code') codeTouched.current = true;
        setForm((f) => ({ ...f, [key]: value }));
    }

    async function submit(e) {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        try {
            await onSubmit({
                name: form.name,
                employee_code: form.employee_code,
                email: form.email || null,
                department: form.department || null,
            });
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
                    <h2 className="mb-4 text-lg font-semibold tracking-[-0.01em] text-slate-900">Add employee</h2>
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
                                {loadingSuggestion ? (
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
                    </div>
                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>Cancel</Button>
                        <Button type="submit" loading={submitting}>{submitting ? 'Adding…' : 'Add'}</Button>
                    </div>
                </form>
            </Card>
        </div>
    );
}
