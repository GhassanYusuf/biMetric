import { useState } from 'react';
import { ApiError } from '../../shared/api';
import { FOCUS } from '../../shared/theme';
import { Button, Card, DateField, Spinner, TextField } from '../../shared/ui';
import { useEscapeKey } from '../../shared/useEscapeKey';

/*
| Add / edit a holiday, for every company or for the ticked ones (2026-11-22). The server refuses a day
| that would give one company two holidays, as a 422 on `date`, shown under the field.
|
| companies  the companies this user can see ({id, name})
| allowAll   false for a user limited to some companies: they set holidays for their own only
*/
export default function HolidayFormModal({ holiday, companies, allowAll, onSubmit, onClose }) {
    const isEdit = !!holiday;
    const initialIds = (holiday?.companies ?? []).map((c) => c.id);
    const [form, setForm] = useState({ date: holiday?.date ?? '', name: holiday?.name ?? '' });
    const [scope, setScope] = useState(initialIds.length > 0 || !allowAll ? 'chosen' : 'all');
    const [ids, setIds] = useState(initialIds);
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    useEscapeKey(onClose, !submitting);

    function set(key, value) {
        setForm((f) => ({ ...f, [key]: value }));
    }

    function toggle(id) {
        setIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    }

    async function submit(e) {
        e.preventDefault();
        if (scope === 'chosen' && ids.length === 0) {
            setErrors({ company_ids: ['Tick at least one company, or choose All companies.'] });
            return;
        }
        setSubmitting(true);
        setErrors({});
        try {
            await onSubmit({ date: form.date, name: form.name, company_ids: scope === 'all' ? [] : ids });
        } catch (err) {
            if (err instanceof ApiError) setErrors(err.errors || { name: [err.message] });
            else setErrors({ name: ['Something went wrong.'] });
        } finally {
            setSubmitting(false);
        }
    }

    const radio = (value, label, hint) => (
        <label className="flex cursor-pointer items-start gap-2.5 text-sm text-slate-700">
            <input type="radio" name="holiday-scope" value={value} checked={scope === value} onChange={() => setScope(value)} disabled={submitting} className={`mt-0.5 h-4 w-4 border-slate-300 text-violet-600 ${FOCUS}`} />
            <span>
                {label}
                <span className="block text-xs text-slate-500">{hint}</span>
            </span>
        </label>
    );

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 p-0 sm:items-center sm:p-4" onClick={submitting ? undefined : onClose} role="presentation">
            <Card className="w-full max-w-md rounded-b-none sm:rounded-2xl" onClick={(e) => e.stopPropagation()}>
                <form onSubmit={submit} className="p-5">
                    <h2 className="mb-4 text-lg font-semibold text-slate-900">{isEdit ? 'Edit holiday' : 'Add holiday'}</h2>
                    <div className="space-y-3">
                        <DateField label="Date" value={form.date} onChange={(e) => set('date', e.target.value)} error={errors.date?.[0]} required />
                        <TextField label="Name" placeholder="National Day" value={form.name} onChange={(e) => set('name', e.target.value)} error={errors.name?.[0]} required />

                        <fieldset className="space-y-2">
                            <legend className="mb-1.5 text-sm font-medium text-slate-700">Applies to</legend>
                            {allowAll && radio('all', 'All companies', 'Everyone has this day off.')}
                            {radio('chosen', 'Chosen companies', 'Only people in the ticked companies have this day off.')}
                            {scope === 'chosen' && (
                                <div className="max-h-52 space-y-1 overflow-y-auto rounded-xl p-2 ring-1 ring-slate-200">
                                    {companies.length === 0 && <p className="px-1 text-sm text-slate-500">No companies yet.</p>}
                                    {companies.map((c) => (
                                        <label key={c.id} className="flex cursor-pointer items-center gap-2.5 rounded-lg px-1.5 py-1 text-sm text-slate-700 hover:bg-slate-50">
                                            <input type="checkbox" checked={ids.includes(c.id)} onChange={() => toggle(c.id)} disabled={submitting} className={`h-4 w-4 rounded border-slate-300 text-violet-600 ${FOCUS}`} />
                                            {c.name}
                                        </label>
                                    ))}
                                </div>
                            )}
                            {(errors.company_ids?.[0] || errors['company_ids.0']?.[0]) && (
                                <p className="text-sm text-rose-700">{errors.company_ids?.[0] ?? errors['company_ids.0'][0]}</p>
                            )}
                        </fieldset>
                    </div>
                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>Cancel</Button>
                        <Button type="submit" disabled={submitting}>{submitting ? <Spinner /> : isEdit ? 'Save' : 'Add holiday'}</Button>
                    </div>
                </form>
            </Card>
        </div>
    );
}
