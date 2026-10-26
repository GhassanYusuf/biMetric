import { useState } from 'react';
import { ApiError } from '../../shared/api';
import { Button, Card, DateField, Spinner, TextField } from '../../shared/ui';

// Add / edit a holiday. One holiday per date per tenant is enforced server-side,
// so a clash returns 422 on `date` and renders inline under the field.
export default function HolidayFormModal({ holiday, onSubmit, onClose }) {
    const isEdit = !!holiday;
    const [form, setForm] = useState({
        date: holiday?.date ?? '',
        name: holiday?.name ?? '',
    });
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    function set(key, value) {
        setForm((f) => ({ ...f, [key]: value }));
    }

    async function submit(e) {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        try {
            await onSubmit({ date: form.date, name: form.name });
        } catch (err) {
            if (err instanceof ApiError) setErrors(err.errors || {});
            else setErrors({ name: ['Something went wrong.'] });
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 p-0 sm:items-center sm:p-4" onClick={onClose}>
            <Card className="w-full max-w-md rounded-b-none sm:rounded-2xl">
                <form onClick={(e) => e.stopPropagation()} onSubmit={submit} className="p-5">
                    <h2 className="mb-4 text-lg font-semibold text-slate-900">
                        {isEdit ? 'Edit holiday' : 'Add holiday'}
                    </h2>
                    <div className="space-y-3">
                        <DateField label="Date" value={form.date} onChange={(e) => set('date', e.target.value)} error={errors.date?.[0]} required />
                        <TextField label="Name" placeholder="National Day" value={form.name} onChange={(e) => set('name', e.target.value)} error={errors.name?.[0]} required />
                    </div>
                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? <Spinner /> : isEdit ? 'Save' : 'Add holiday'}
                        </Button>
                    </div>
                </form>
            </Card>
        </div>
    );
}
