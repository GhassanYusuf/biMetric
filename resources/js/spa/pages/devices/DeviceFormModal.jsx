import { useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { useToast } from '../../shared/toast';
import { Button, Card, SelectField, Spinner, TextField } from '../../shared/ui';

// The models this project has met, grouped by the protocol family they speak and
// tagged with how far each has actually been verified HERE — not what a datasheet
// claims. Which protocol a device needs is decided by its family: standalone
// terminals speak the binary protocol, inBIO/C3 panels speak C3/PullSDK.
//
// status, honestly:
//   'tested'   verified against real hardware in this project
//   'family'   same protocol as a tested model, but we have no such device yet
//   'partial'  talks, but not every operation works (see note)
//   'untested' same family as a partial model, never connected here
//
// Keep this in step with docs/device-capabilities.md as devices arrive. A model
// stays in the list once added, so the choice exists the day the hardware does.
const STATUS_LABEL = {
    tested: 'tested here',
    family: 'same protocol, untested',
    partial: 'reads only — no full driver yet',
    untested: 'untested',
};

// What each protocol family can actually do today, so the operator reads it at the
// point of choosing rather than discovering it after a failed action (rule 9).
const PROTOCOL_NOTE = {
    binary: null,
    csl_http: 'Reads attendance and staff through the web UI. No fingerprint access.',
    c3: 'Reads attendance and staff. Enrollment and door control are not built for panels yet.',
};

const DEVICE_MODELS = [
    {
        family: 'Standalone terminals (binary protocol)',
        models: [
            { name: 'F18', status: 'tested' },
            { name: 'F22', status: 'tested' },
            { name: 'F19', status: 'family' },
            { name: 'F16', status: 'family' },
            { name: 'K40', status: 'family' },
            // TF1700 IS NOT HERE. It is sold as a standalone fingerprint terminal
            // and speaks C3/PullSDK — it sits in the panel group below. It was
            // listed here until 2026-09-14 and that was wrong: the unit on
            // 192.168.7.60 looked dead for weeks purely because we spoke the
            // standalone protocol at it. A model name does not tell you the family.
        ],
    },
    {
        family: 'Access-control panels (C3 / PullSDK)',
        models: [
            { name: 'inBIO460', status: 'tested' },
            { name: 'TF1700', status: 'tested' },
            { name: 'inBIO260', status: 'untested' },
            { name: 'inBIO160', status: 'untested' },
            { name: 'C3-400', status: 'untested' },
            { name: 'C3-200', status: 'untested' },
            { name: 'C3-100', status: 'untested' },
        ],
    },
];

const ALL_MODEL_NAMES = DEVICE_MODELS.flatMap((g) => g.models.map((m) => m.name));

// The hardware reports a fuller string than the dropdown value — "F18/ID" for the
// F18, "F22/ID" for the F22. Map a reported name onto a known model by longest
// matching prefix, so auto-detect lands on the right option.
function matchModel(reported) {
    if (!reported) return '';
    const exact = ALL_MODEL_NAMES.find((m) => m.toLowerCase() === reported.toLowerCase());
    if (exact) return exact;
    const prefix = ALL_MODEL_NAMES
        .filter((m) => reported.toLowerCase().startsWith(m.toLowerCase()))
        .sort((a, b) => b.length - a.length)[0];
    return prefix || '';
}

// Add / edit a device. Secrets (comm key, web password) are never sent back by
// the API, so on edit they render empty and an empty submission means "leave
// unchanged" — DeviceController::update strips blank secrets for exactly this.
export default function DeviceFormModal({ device, onSubmit, onClose }) {
    const isEdit = !!device;
    const toast = useToast();
    const [detecting, setDetecting] = useState(false);
    const [form, setForm] = useState({
        name: device?.name ?? '',
        ip_address: device?.ip_address ?? '',
        port: device?.port ?? 4370,
        protocol: device?.protocol ?? 'binary',
        model: device?.model ?? '',
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

    // Reach out to the device and let it name itself, then select the matching
    // model. Only works for a protocol the app drives (binary today); an inBIO/C3
    // panel times out, and the API says so plainly rather than failing silently.
    async function detect() {
        if (!form.ip_address) {
            setErrors((e) => ({ ...e, ip_address: ['Enter the IP address first.'] }));
            return;
        }
        setDetecting(true);
        setErrors({});
        try {
            const res = await api.post('/devices/identify', {
                ip_address: form.ip_address,
                port: Number(form.port) || 4370,
                protocol: form.protocol,
                comm_key: form.comm_key,
            });
            const reported = res.data?.device_name || '';
            const matched = matchModel(reported);
            set('model', matched || reported);
            toast.success(
                matched
                    ? `Detected ${reported} — selected ${matched}.`
                    : res.message,
            );
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not reach the device.');
        } finally {
            setDetecting(false);
        }
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
                model: form.model || null,
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
                            <option value="c3">C3 / PullSDK (inBIO and C3 access panels, TCP 4370)</option>
                        </SelectField>

                        {/*
                          | What this family can actually do, said before the operator
                          | commits rather than after an action fails (rule 9). It is
                          | page STATE and not an event, so it is content and not a
                          | toast (rule 2).
                        */}
                        {PROTOCOL_NOTE[form.protocol] && (
                            <p className="-mt-2 text-xs text-slate-500">{PROTOCOL_NOTE[form.protocol]}</p>
                        )}

                        <div>
                            <div className="mb-1 flex items-end justify-between gap-2">
                                <span className="block text-sm font-medium text-slate-700">Model</span>
                                <button
                                    type="button"
                                    onClick={detect}
                                    disabled={detecting || !form.ip_address}
                                    className="inline-flex items-center gap-1.5 text-sm font-medium text-violet-600 hover:text-violet-700 disabled:cursor-not-allowed disabled:text-slate-400"
                                >
                                    {detecting ? <Spinner /> : null}
                                    {detecting ? 'Reading…' : 'Read from device'}
                                </button>
                            </div>
                            <SelectField value={form.model} onChange={(e) => set('model', e.target.value)} error={errors.model?.[0]}>
                                <option value="">Unknown / not sure</option>
                                {DEVICE_MODELS.map((group) => (
                                    <optgroup key={group.family} label={group.family}>
                                        {group.models.map((m) => (
                                            <option key={m.name} value={m.name}>
                                                {m.name} — {STATUS_LABEL[m.status]}
                                            </option>
                                        ))}
                                    </optgroup>
                                ))}
                                {/* A model read off the hardware (e.g. "F18/ID") may not match the
                                    curated list — keep it selectable so editing never silently drops it. */}
                                {form.model && !ALL_MODEL_NAMES.includes(form.model) && (
                                    <option value={form.model}>{form.model} (detected)</option>
                                )}
                            </SelectField>
                        </div>

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
