import { useState } from 'react';
import { ApiError } from '../../shared/api';
import { useEscapeKey } from '../../shared/useEscapeKey';
import { FOCUS } from '../../shared/theme';
import { Button, Card, Spinner, TextField } from '../../shared/ui';
import { PROTOCOL_LABELS } from './parts';
import { HealthBadge } from './statusParts';

/*
| Add / edit a group and choose its terminals in one form.
|
| Membership is a checklist rather than a multi-select: the operator needs to see
| each terminal's health while deciding, because grouping an offline device into a
| "sync every night" group is how a site quietly stops reporting.
*/
export default function DeviceGroupFormModal({ group, devices, onSubmit, onClose }) {
    const isEdit = !!group;
    const [form, setForm] = useState({
        name: group?.name ?? '',
        description: group?.description ?? '',
    });
    const [selected, setSelected] = useState(() => new Set((group?.devices ?? []).map((d) => d.id)));
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    useEscapeKey(onClose);

    function toggle(id) {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }

    async function submit(e) {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        try {
            await onSubmit({
                name: form.name,
                description: form.description || null,
                device_ids: [...selected],
            });
        } catch (err) {
            if (err instanceof ApiError) setErrors(err.errors || {});
            else setErrors({ name: ['Something went wrong.'] });
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-slate-900/40 p-0 sm:items-center sm:p-4"
            onClick={onClose}
            role="dialog"
            aria-modal="true"
            aria-labelledby="group-form-title"
        >
            <Card className="w-full max-w-lg rounded-b-none sm:rounded-2xl">
                <form onClick={(e) => e.stopPropagation()} onSubmit={submit} className="p-5">
                    <h2 id="group-form-title" className="mb-4 text-lg font-semibold tracking-[-0.01em] text-slate-900">
                        {isEdit ? `Edit ${group.name}` : 'New device group'}
                    </h2>

                    <div className="space-y-3">
                        <TextField
                            label="Group name"
                            placeholder="Main Gate"
                            value={form.name}
                            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                            error={errors.name?.[0]}
                            required
                        />
                        <TextField
                            label="Description"
                            placeholder="Entrance terminals, synced nightly"
                            value={form.description}
                            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
                            error={errors.description?.[0]}
                        />
                    </div>

                    <fieldset className="mt-5">
                        <legend className="mb-1.5 text-sm font-medium text-slate-700">
                            Terminals in this group
                            <span className="ml-1.5 font-normal text-slate-500">
                                ({selected.size} of {devices.length} selected)
                            </span>
                        </legend>

                        {devices.length === 0 ? (
                            <p className="rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-500">
                                No terminals exist yet. Create the group now and add devices once they are registered.
                            </p>
                        ) : (
                            <ul className="max-h-64 divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200">
                                {devices.map((d) => (
                                    <li key={d.id}>
                                        <label className={`flex cursor-pointer items-center gap-3 px-3.5 py-2.5 transition-colors hover:bg-slate-50 ${FOCUS}`}>
                                            <input
                                                type="checkbox"
                                                checked={selected.has(d.id)}
                                                onChange={() => toggle(d.id)}
                                                className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-400"
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium text-slate-900">{d.name}</span>
                                                <span className="block truncate text-xs text-slate-500">
                                                    {d.ip_address}:{d.port} · {PROTOCOL_LABELS[d.protocol] || d.protocol}
                                                </span>
                                            </span>
                                            <HealthBadge status={d.health_status} online={d.is_online} />
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {errors.device_ids?.[0] && (
                            <span role="alert" className="mt-1 block text-xs font-medium text-rose-600">{errors.device_ids[0]}</span>
                        )}
                    </fieldset>

                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? <Spinner /> : isEdit ? 'Save group' : 'Create group'}
                        </Button>
                    </div>
                </form>
            </Card>
        </div>
    );
}
