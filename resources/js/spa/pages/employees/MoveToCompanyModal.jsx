import { useEffect, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { Button, Card, InlineAlert, SelectField, Skeleton } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import { useToast } from '../../shared/toast';
import { useEscapeKey } from '../../shared/useEscapeKey';

/*
| Employees → "Move terminal to company" (owner, 2026-11-02): everyone a terminal holds,
| into one company, in one go. Asked for after JJs's 157 imported people arrived in no
| company.
|
| The server answers a preview first (GET /employees/move-to-company/preview) and the
| button says how many will move, so nothing happens that the operator did not see. Only
| people in NO company move unless the box is ticked: a terminal shared by two companies
| must not pull anyone out of the right one. Nothing is written to any terminal.
*/
export default function MoveToCompanyModal({ devices, companies, onMoved, onClose }) {
    const toast = useToast();
    const [deviceId, setDeviceId] = useState('');
    const [companyId, setCompanyId] = useState('');
    const [includeAssigned, setIncludeAssigned] = useState(false);
    const [preview, setPreview] = useState(null);
    const [loadingPreview, setLoadingPreview] = useState(false);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState({});

    useEscapeKey(onClose, !busy);

    useEffect(() => {
        setPreview(null);
        setErrors({});
        if (!deviceId || !companyId) return undefined;
        let live = true;
        setLoadingPreview(true);
        api.get(`/employees/move-to-company/preview?device_id=${deviceId}&company_id=${companyId}`)
            .then((res) => { if (live) setPreview(res.data); })
            .catch((err) => {
                if (!live) return;
                if (err instanceof ApiError && err.status === 422) setErrors(err.errors || {});
                else toast.error(err instanceof ApiError ? err.message : 'Could not read who is on that terminal.');
            })
            .finally(() => { if (live) setLoadingPreview(false); });
        return () => { live = false; };
    }, [deviceId, companyId, toast]);

    const elsewhere = (preview?.elsewhere ?? []).reduce((n, g) => n + g.count, 0);
    const willMove = preview ? preview.unassigned + (includeAssigned ? elsewhere : 0) : 0;
    const company = companies.find((c) => String(c.id) === String(companyId));
    const device = devices.find((d) => String(d.id) === String(deviceId));

    async function move() {
        setBusy(true);
        try {
            const res = await api.post('/employees/move-to-company', {
                device_id: Number(deviceId),
                company_id: Number(companyId),
                include_assigned: includeAssigned,
            });
            toast.success(res.message);
            onMoved();
        } catch (err) {
            if (err instanceof ApiError && err.status === 422) setErrors(err.errors || {});
            else toast.error(err instanceof ApiError ? err.message : 'Could not move them.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <div
            className="fixed inset-0 z-[60] flex items-end justify-center bg-slate-900/40 sm:items-center sm:p-4"
            onClick={() => { if (!busy) onClose(); }}
            role="presentation"
        >
            <Card
                className="w-full max-w-lg rounded-b-none sm:rounded-2xl"
                onClick={(e) => e.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-labelledby="move-terminal-title"
            >
                <div className="space-y-4 p-5">
                    <div>
                        <h2 id="move-terminal-title" className="text-lg font-semibold tracking-[-0.01em] text-slate-900">Move terminal to company</h2>
                        <p className="mt-0.5 text-sm text-slate-500">Everyone on a terminal goes into one company. Nothing changes on the terminal itself.</p>
                    </div>

                    <SelectField id="move-terminal" label="Terminal" value={deviceId} onChange={(e) => setDeviceId(e.target.value)} error={errors.device_id?.[0]} disabled={busy}>
                        <option value="">Choose a terminal…</option>
                        {devices.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                    </SelectField>

                    <SelectField id="move-company" label="Company" value={companyId} onChange={(e) => setCompanyId(e.target.value)} error={errors.company_id?.[0]} disabled={busy}>
                        <option value="">Choose a company…</option>
                        {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </SelectField>

                    {loadingPreview && <Skeleton className="h-16 w-full rounded-xl" />}

                    {preview && !loadingPreview && (
                        <div className="space-y-3">
                            <InlineAlert tone="info">
                                {preview.total === 0
                                    ? `Nobody is on ${device?.name} right now.`
                                    : (
                                        <>
                                            {`${preview.total} ${preview.total === 1 ? 'person is' : 'people are'} on ${device?.name}: `}
                                            {`${preview.unassigned} in no company`}
                                            {preview.already > 0 && `, ${preview.already} already in ${company?.name}`}
                                            {preview.elsewhere.length > 0 && `, ${preview.elsewhere.map((g) => `${g.count} in ${g.company}`).join(', ')}`}
                                            .
                                        </>
                                    )}
                            </InlineAlert>

                            {elsewhere > 0 && (
                                <label className="flex cursor-pointer items-start gap-2.5 text-sm text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={includeAssigned}
                                        onChange={(e) => setIncludeAssigned(e.target.checked)}
                                        disabled={busy}
                                        className={`mt-0.5 h-4 w-4 rounded border-slate-300 text-violet-600 ${FOCUS}`}
                                    />
                                    <span>
                                        Also move the {elsewhere} already in another company
                                        <span className="block text-xs text-slate-500">Their department is cleared, since it belongs to the other company.</span>
                                    </span>
                                </label>
                            )}
                        </div>
                    )}

                    <div className="flex justify-end gap-2 pt-1">
                        <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
                        <Button onClick={move} loading={busy} disabled={!preview || willMove === 0 || loadingPreview}>
                            {willMove > 0 ? `Move ${willMove} ${willMove === 1 ? 'person' : 'people'}` : 'Move'}
                        </Button>
                    </div>
                </div>
            </Card>
        </div>
    );
}
