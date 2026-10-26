import { useState } from 'react';
import { ApiError } from './api';
import { Button, Card, DateField, SelectField } from './ui';
import { FOCUS } from './theme';
import { useEscapeKey } from './useEscapeKey';
import EmployeePicker from './EmployeePicker';
import DepartmentChecklist from './DepartmentChecklist';

/*
| Choose the period for a report PDF (2026-10-18): "this month", "last month", the
| last 30 days, or any dates. HR is asked for exactly those — "September for
| everybody", "this person from the 3rd to the 21st".
|
| Used by the Employee Report screen (one person, downloads at once) and the Reports
| screen (everybody, built in the background — that caller passes `departments`).
|
| `allowPeople` (the Monthly Statement Excel, 2026-10-19) turns "Who" into a choice of
| everyone / companies or departments (one or several) / chosen people, and submits
| `department_ids` or `employee_ids`.
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

export default function ReportRangeDialog({ title, intro, confirmLabel, initial, departments = null, initialDepartment = '', allowPeople = false, onSubmit, onClose }) {
    const options = presets();
    const start = initial ?? options[0];
    const [from, setFrom] = useState(start.from);
    const [to, setTo] = useState(start.to);
    const [departmentId, setDepartmentId] = useState(initialDepartment);
    const [who, setWho] = useState(initialDepartment ? 'department' : 'all');
    const [people, setPeople] = useState([]);
    const [departmentIds, setDepartmentIds] = useState(initialDepartment ? [Number(initialDepartment)] : []);
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
        if (allowPeople && who === 'department' && departmentIds.length === 0) found.department_ids = ['Choose at least one company or department.'];
        if (allowPeople && who === 'people' && people.length === 0) found.employee_ids = ['Choose at least one person.'];
        if (Object.keys(found).length) {
            setErrors(found);
            return;
        }
        setSubmitting(true);
        setErrors({});
        try {
            await onSubmit(allowPeople
                ? { from, to, department_ids: who === 'department' ? departmentIds : null, employee_ids: who === 'people' ? people.map((p) => p.id) : null }
                : { from, to, department_id: departmentId || null });
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
                <form onSubmit={submit} className="max-h-[90vh] overflow-y-auto p-5" noValidate>
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
                        <DateField label="From" value={from} max={today} onChange={(e) => setFrom(e.target.value)} error={errors.from?.[0]} required />
                        <DateField label="To" value={to} max={today} onChange={(e) => setTo(e.target.value)} error={errors.to?.[0]} required />
                    </div>

                    {allowPeople && (
                        <div className="mt-4">
                            <p id="report-who" className="text-sm font-medium text-slate-700">Who</p>
                            <div role="radiogroup" aria-labelledby="report-who" className="mt-2 grid grid-cols-3 gap-2">
                                {[['all', 'Everyone'], ['department', 'Departments'], ['people', 'Chosen people']].map(([key, label]) => (
                                    <button
                                        key={key}
                                        type="button"
                                        role="radio"
                                        aria-checked={who === key}
                                        onClick={() => { setWho(key); setErrors({}); }}
                                        className={`min-h-[44px] rounded-xl px-2 text-center text-sm font-medium ring-1 transition-colors ${who === key ? 'bg-violet-50 text-violet-800 ring-violet-300' : 'text-slate-700 ring-slate-200 hover:bg-slate-50'} ${FOCUS}`}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            {who === 'department' && (
                                <div className="mt-3">
                                    <DepartmentChecklist
                                        departments={departments ?? []}
                                        value={departmentIds}
                                        onChange={(next) => { setDepartmentIds(next); setErrors({}); }}
                                        error={errors.department_ids?.[0] ?? errors['department_ids.0']?.[0]}
                                    />
                                </div>
                            )}
                            {who === 'people' && (
                                <div className="mt-3">
                                    <EmployeePicker value={people} onChange={(next) => { setPeople(next); setErrors({}); }} error={errors.employee_ids?.[0] ?? errors['employee_ids.0']?.[0]} />
                                </div>
                            )}
                        </div>
                    )}

                    {departments && !allowPeople && (
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
