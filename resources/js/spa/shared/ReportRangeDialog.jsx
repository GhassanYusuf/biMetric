import { useState } from 'react';
import { ApiError } from './api';
import { Button, Card, SelectField, TextField } from './ui';
import { FOCUS } from './theme';
import { useEscapeKey } from './useEscapeKey';

/*
| Choose the period for a report PDF (2026-10-18): "this month", "last month", the
| last 30 days, or any dates. HR is asked for exactly those — "September for
| everybody", "this person from the 3rd to the 21st".
|
| Used by the Employee Report screen (one person, downloads at once) and the Reports
| screen (everybody, built in the background — that caller passes `departments`).
|
| Dates are office-local calendar days, sent as typed. No date after today: an
| unhappened work day would be counted as an absence, and the server refuses it too.
*/
const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

function presets() {
    const today = new Date();
    const firstThis = new Date(today.getFullYear(), today.getMonth(), 1);
    const lastPrev = new Date(firstThis.getTime() - 864e5);
    const firstPrev = new Date(lastPrev.getFullYear(), lastPrev.getMonth(), 1);
    const monthName = (d) => d.toLocaleString(undefined, { month: 'long' });
    return [
        { key: 'last-month', label: `Last month (${monthName(firstPrev)})`, from: ymd(firstPrev), to: ymd(lastPrev) },
        { key: 'this-month', label: `This month so far (${monthName(firstThis)})`, from: ymd(firstThis), to: ymd(today) },
        { key: 'last-30', label: 'Last 30 days', from: ymd(new Date(today.getTime() - 29 * 864e5)), to: ymd(today) },
    ];
}

export default function ReportRangeDialog({ title, intro, confirmLabel, initial, departments = null, initialDepartment = '', onSubmit, onClose }) {
    const options = presets();
    const start = initial ?? options[0];
    const [from, setFrom] = useState(start.from);
    const [to, setTo] = useState(start.to);
    const [departmentId, setDepartmentId] = useState(initialDepartment);
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const today = ymd(new Date());

    useEscapeKey(onClose, !submitting);

    const chosen = options.find((p) => p.from === from && p.to === to)?.key ?? 'custom';

    async function submit(e) {
        e.preventDefault();
        const found = {};
        if (!from) found.from = ['Choose the first day.'];
        if (!to) found.to = ['Choose the last day.'];
        if (from && to && from > to) found.to = ['The last day is before the first.'];
        if (Object.keys(found).length) {
            setErrors(found);
            return;
        }
        setSubmitting(true);
        setErrors({});
        try {
            await onSubmit({ from, to, department_id: departmentId || null });
        } catch (err) {
            // 422 → on the fields; anything else the caller has already toasted.
            if (err instanceof ApiError && err.errors) setErrors(err.errors);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 p-0 sm:items-center sm:p-4" onClick={submitting ? undefined : onClose} role="presentation">
            <Card className="w-full max-w-md rounded-b-none sm:rounded-2xl" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-labelledby="report-range-title">
                <form onSubmit={submit} className="p-5" noValidate>
                    <h2 id="report-range-title" className="text-lg font-semibold text-slate-900">{title}</h2>
                    {intro && <p className="mt-1 text-sm text-slate-600">{intro}</p>}

                    <div role="group" aria-label="Period" className="mt-4 grid gap-2">
                        {options.map((p) => (
                            <button
                                key={p.key}
                                type="button"
                                aria-pressed={chosen === p.key}
                                onClick={() => { setFrom(p.from); setTo(p.to); setErrors({}); }}
                                className={`min-h-[44px] rounded-xl px-3.5 text-left text-sm font-medium ring-1 transition-colors ${chosen === p.key ? 'bg-violet-50 text-violet-800 ring-violet-300' : 'text-slate-700 ring-slate-200 hover:bg-slate-50'} ${FOCUS}`}
                            >
                                {p.label}
                            </button>
                        ))}
                    </div>

                    <div className="mt-4 grid grid-cols-2 gap-3">
                        <TextField label="From" type="date" value={from} max={today} onChange={(e) => setFrom(e.target.value)} error={errors.from?.[0]} required />
                        <TextField label="To" type="date" value={to} max={today} onChange={(e) => setTo(e.target.value)} error={errors.to?.[0]} required />
                    </div>

                    {departments && (
                        <SelectField className="mt-3" label="Who" value={departmentId} onChange={(e) => setDepartmentId(e.target.value)} error={errors.department_id?.[0]}>
                            <option value="">Every active employee</option>
                            {departments.map((d) => (
                                <option key={d.id} value={d.id}>{d.name} and below</option>
                            ))}
                        </SelectField>
                    )}

                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>Cancel</Button>
                        <Button type="submit" loading={submitting}>{confirmLabel}</Button>
                    </div>
                </form>
            </Card>
        </div>
    );
}
