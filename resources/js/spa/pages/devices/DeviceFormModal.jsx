import { useState } from 'react';
import { ApiError } from '../../shared/api';
import { Button, Card, SelectField, Spinner, TextField } from '../../shared/ui';

// Add / edit a device. Secrets (comm key, web password) are never sent back by
// the API, so on edit they render empty and an empty submission means "leave
// unchanged" — DeviceController::update strips blank secrets for exactly this.
export default function DeviceFormModal({ device, onSubmit, onClose }) {
    const isEdit = !!device;
    const [form, setForm] = useState({
        name: device?.name ?? '',
        ip_address: device?.ip_address ?? '',
        port: device?.port ?? 4370,
        protocol: device?.protocol ?? 'binary',
        location: device?.location ?? '',
        comm_key: '',
        web_username: device?.web_username ?? '',
        web_password: '',
        is_active: device?.is_active ?? true,
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
            const payload = {
                name: form.name,
                ip_address: form.ip_address,
                port: Number(form.port) || 4370,
                protocol: form.protocol,
                location: form.location || null,
                comm_key: form.comm_key,
                web_username: form.web_username || null,
                web_password: form.web_password,
            };
            // is_active is only accepted by the update endpoint.
            if (isEdit) payload.is_active = form.is_active;

            await onSubmit(payload);
        } catch (err) {
            if (err instanceof ApiError) setErrors(err.errors || {});
            else setErrors({ name: ['Something went wrong.'] });
        } finally {
            setSubmitting(false);
        }
    }

    const needsWebCreds = form.protocol === 'csl_http';

    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-slate-900/40 p-0 sm:items-center sm:p-4" onClick={onClose}>
            <Card className="w-full max-w-md rounded-b-none sm:rounded-2xl">
                <form onClick={(e) => e.stopPropagation()} onSubmit={submit} className="p-5">
                    <h2 className="mb-4 text-lg font-semibold text-slate-900">
                        {isEdit ? `Edit ${device.name}` : 'Add device'}
                    </h2>

                    <div className="space-y-3">
                        <TextField label="Name" value={form.name} onChange={(e) => set('name', e.target.value)} error={errors.name?.[0]} required />

                        <div className="grid grid-cols-3 gap-3">
                            <div className="col-span-2">
                                <TextField label="IP address" placeholder="192.168.1.50" value={form.ip_address} onChange={(e) => set('ip_address', e.target.value)} error={errors.ip_address?.[0]} required />
                            </div>
                            <TextField label="Port" type="number" value={form.port} onChange={(e) => set('port', e.target.value)} error={errors.port?.[0]} />
                        </div>

                        <SelectField label="Protocol" value={form.protocol} onChange={(e) => set('protocol', e.target.value)} error={errors.protocol?.[0]}>
                            <option value="binary">Binary (standard ZKTeco, UDP/TCP 4370)</option>
                            <option value="csl_http">CSL HTTP (reseller-branded firmware)</option>
                        </SelectField>

                        <TextField label="Location (optional)" value={form.location} onChange={(e) => set('location', e.target.value)} error={errors.location?.[0]} />

                        <TextField
                            label={`Comm key${isEdit ? ' — blank keeps current' : ' (optional)'}`}
                            value={form.comm_key}
                            onChange={(e) => set('comm_key', e.target.value)}
                            error={errors.comm_key?.[0]}
                            placeholder={isEdit && device.has_comm_key ? '•••••• (set)' : ''}
                        />

                        {needsWebCreds && (
                            <div className="grid grid-cols-2 gap-3">
                                <TextField label="Web username" value={form.web_username} onChange={(e) => set('web_username', e.target.value)} error={errors.web_username?.[0]} />
                                <TextField
                                    label="Web password"
                                    type="password"
                                    value={form.web_password}
                                    onChange={(e) => set('web_password', e.target.value)}
                                    error={errors.web_password?.[0]}
                                    placeholder={isEdit && device.has_web_credentials ? '•••••• (set)' : ''}
                                />
                            </div>
                        )}

                        {isEdit && (
                            <label className="flex items-center gap-2.5 pt-1">
                                <input
                                    type="checkbox"
                                    checked={form.is_active}
                                    onChange={(e) => set('is_active', e.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-200"
                                />
                                <span className="text-sm text-slate-700">Active — include in scheduled syncs</span>
                            </label>
                        )}
                    </div>

                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? <Spinner /> : isEdit ? 'Save' : 'Add device'}
                        </Button>
                    </div>
                </form>
            </Card>
        </div>
    );
}
