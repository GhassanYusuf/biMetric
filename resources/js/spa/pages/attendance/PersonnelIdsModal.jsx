import { useCallback, useEffect, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { Button, Card, DateField, InlineAlert, SelectField, Skeleton } from '../../shared/ui';
import { useConfirm } from '../../shared/confirm';
import { useToast } from '../../shared/toast';
import { useEscapeKey } from '../../shared/useEscapeKey';
import { FOCUS } from '../../shared/theme';

/*
| Attendance → "Personnel IDs on punches" (owner, 2026-11-05: "on punches from September onwards
| replace the device user id with the CPR, so it shows in the HR system… build it as a feature
| in the attendance page").
|
| Choose a company (and a department, or its staff with no department) and a first day; the
| server answers a preview per employee first, so nothing changes that the operator did not
| see. Only punches LINKED to someone whose Personnel ID is a CPR change; the number the
| terminal recorded is kept, every change is saved first and can be undone from the list
| below. Nothing is written to any terminal. Rules: PersonnelIdReplacer.
*/
const DEFAULT_FROM = '2026-09-01';

export default function PersonnelIdsModal({ companies, departments, onClose }) {
    const toast = useToast();
    const confirm = useConfirm();
    const [companyId, setCompanyId] = useState('');
    const [departmentId, setDepartmentId] = useState('');
    const [from, setFrom] = useState(DEFAULT_FROM);
    const [preview, setPreview] = useState(null);
    const [loadingPreview, setLoadingPreview] = useState(false);
    const [runs, setRuns] = useState(null);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState({});
    const [reload, setReload] = useState(0);

    useEscapeKey(onClose, !busy);

    const loadRuns = useCallback(() => {
        api.get('/attendance/personnel-ids/runs')
            .then((res) => setRuns(res.data ?? []))
            .catch(() => setRuns([]));
    }, []);
    useEffect(() => { loadRuns(); }, [loadRuns]);

    useEffect(() => {
        setPreview(null);
        setErrors({});
        if (!companyId || !from) return undefined;
        let live = true;
        setLoadingPreview(true);
        const q = new URLSearchParams({ company_id: companyId, from });
        if (departmentId) q.set('department_id', departmentId);
        api.get(`/attendance/personnel-ids/preview?${q}`)
            .then((res) => { if (live) setPreview(res.data); })
            .catch((err) => {
                if (!live) return;
                if (err instanceof ApiError && err.status === 422) setErrors(err.errors || {});
                else toast.error(err instanceof ApiError ? err.message : 'Could not read the punches.');
            })
            .finally(() => { if (live) setLoadingPreview(false); });
        return () => { live = false; };
    }, [companyId, departmentId, from, reload, toast]);

    const company = companies.find((c) => String(c.id) === String(companyId));
    const ownDepartments = departments.filter((d) => String(d.company_id) === String(companyId));
    const scopeName = [company?.name, departmentId === 'none' ? 'no department' : ownDepartments.find((d) => String(d.id) === String(departmentId))?.name].filter(Boolean).join(' › ');
    const shown = (preview?.people ?? []).filter((p) => p.punches > 0 || p.to_change > 0);
    const silent = (preview?.people ?? []).length - shown.length;

    async function apply() {
        const ok = await confirm({
            title: `Put the Personnel ID on ${preview.changes.toLocaleString()} punches?`,
            body: [
                `${scopeName}, punches from ${from} onwards. Each gets the person's CPR as its device user ID; the number the terminal recorded is kept.`,
                'A copy is saved first, and the change can be undone from this window. Nothing changes on any terminal.',
            ],
            confirmLabel: 'Change punches',
        });
        if (!ok) return;
        setBusy(true);
        try {
            const body = { company_id: Number(companyId), from };
            if (departmentId) body.department_id = departmentId;
            const res = await api.post('/attendance/personnel-ids', body);
            toast.success(res.message);
            loadRuns();
            setReload((n) => n + 1);
        } catch (err) {
            if (err instanceof ApiError && err.status === 422) setErrors(err.errors || {});
            else toast.error(err instanceof ApiError ? err.message : 'Could not change the punches.');
        } finally {
            setBusy(false);
        }
    }

    async function undo(run) {
        const ok = await confirm({
            title: `Undo the change to ${run.scope}?`,
            body: `${run.punches.toLocaleString()} punches get back the number the terminal recorded. A punch changed again since is left alone.`,
            confirmLabel: 'Undo change',
            danger: true,
        });
        if (!ok) return;
        setBusy(true);
        try {
            const res = await api.post(`/attendance/personnel-ids/runs/${run.id}/undo`);
            toast.success(res.message);
            loadRuns();
            setReload((n) => n + 1);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not undo that change.');
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
                className="flex max-h-[92vh] w-full max-w-3xl flex-col rounded-b-none sm:rounded-2xl"
                onClick={(e) => e.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-labelledby="personnel-ids-title"
            >
                <div className="space-y-4 overflow-y-auto p-5">
                    <div>
                        <h2 id="personnel-ids-title" className="text-lg font-semibold tracking-[-0.01em] text-slate-900">Personnel IDs on punches</h2>
                        <p className="mt-0.5 text-sm text-slate-500">
                            Puts each person's CPR on their punches as the device user ID, so outside systems see it. The number the terminal recorded is kept. Nothing changes on the terminals.
                        </p>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-3">
                        <SelectField id="pid-company" label="Company" value={companyId} disabled={busy} error={errors.company_id?.[0]}
                            onChange={(e) => { setCompanyId(e.target.value); setDepartmentId(''); }}>
                            <option value="">Choose a company…</option>
                            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </SelectField>
                        <SelectField id="pid-department" label="Department" value={departmentId} disabled={busy || !companyId} error={errors.department_id?.[0]}
                            onChange={(e) => setDepartmentId(e.target.value)}>
                            <option value="">The whole company</option>
                            {ownDepartments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                            <option value="none">No department</option>
                        </SelectField>
                        <DateField label="Punches from" value={from} disabled={busy} error={errors.from?.[0]} onChange={(e) => setFrom(e.target.value)} />
                    </div>

                    {loadingPreview && !preview && <Skeleton className="h-40 w-full rounded-xl" />}

                    {preview && (
                        <div className="space-y-3">
                            {preview.no_cpr.length > 0 && (
                                <InlineAlert tone="error">
                                    {`${preview.no_cpr.length} ${preview.no_cpr.length === 1 ? 'person has' : 'people have'} punches but no CPR as Personnel ID, so theirs stay as they are until it is entered: `}
                                    {preview.no_cpr.map((p) => `${p.name} (${p.employee_code})`).join(', ')}.
                                </InlineAlert>
                            )}
                            {preview.taken > 0 && (
                                <InlineAlert tone="info">{`${preview.taken} punches are left as they are: the same punch is already stored under the CPR.`}</InlineAlert>
                            )}

                            {shown.length === 0 ? (
                                <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-600">Nobody here has punches from {from} onwards.</p>
                            ) : (
                                <div className="overflow-x-auto rounded-xl ring-1 ring-slate-200">
                                    <table className="min-w-full text-sm">
                                        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            <tr>
                                                <th className="px-3 py-2">Employee</th>
                                                <th className="px-3 py-2">Personnel ID</th>
                                                <th className="px-3 py-2 text-right">Punches</th>
                                                <th className="px-3 py-2 text-right">Already</th>
                                                <th className="px-3 py-2 text-right">Will change</th>
                                                <th className="px-3 py-2">Recorded as</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {shown.map((p) => (
                                                <tr key={p.id}>
                                                    <td className="px-3 py-2 font-medium text-slate-900">{p.name}</td>
                                                    <td className={`px-3 py-2 font-mono text-xs ${p.is_cpr ? 'text-slate-600' : 'text-amber-700'}`}>
                                                        {p.employee_code}{!p.is_cpr && ' · not a CPR'}
                                                    </td>
                                                    <td className="px-3 py-2 text-right tabular-nums text-slate-600">{p.punches.toLocaleString()}</td>
                                                    <td className="px-3 py-2 text-right tabular-nums text-slate-600">{p.already.toLocaleString()}</td>
                                                    <td className="px-3 py-2 text-right font-semibold tabular-nums text-slate-900">{p.to_change.toLocaleString()}</td>
                                                    <td className="px-3 py-2 font-mono text-xs text-slate-600">{p.recorded_as.join(', ') || '—'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                            {silent > 0 && <p className="text-xs text-slate-500">{`${silent} more with no punches from ${from} are not listed.`}</p>}
                        </div>
                    )}

                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={onClose} disabled={busy}>Close</Button>
                        <Button onClick={apply} loading={busy} disabled={!preview || preview.changes === 0 || loadingPreview}>
                            {preview && preview.changes > 0 ? `Change ${preview.changes.toLocaleString()} punches` : 'Nothing to change'}
                        </Button>
                    </div>

                    <div className="border-t border-slate-100 pt-4">
                        <h3 className="text-sm font-semibold text-slate-900">Changes made</h3>
                        {runs === null ? (
                            <Skeleton className="mt-2 h-12 w-full rounded-xl" />
                        ) : runs.length === 0 ? (
                            <p className="mt-1 text-sm text-slate-500">None yet.</p>
                        ) : (
                            <ul className="mt-2 divide-y divide-slate-100">
                                {runs.map((r) => (
                                    <li key={r.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                        <span className="min-w-0">
                                            <span className="font-medium text-slate-900">{r.scope}</span>
                                            <span className="block text-xs text-slate-500">
                                                {`${r.punches.toLocaleString()} punches, ${r.employees} ${r.employees === 1 ? 'person' : 'people'}, from ${r.from} · ${r.at ? new Date(r.at).toLocaleString() : ''}${r.by ? ` · ${r.by}` : ''}`}
                                            </span>
                                        </span>
                                        {r.undone ? (
                                            <span className="shrink-0 text-xs font-medium text-slate-500">Undone</span>
                                        ) : (
                                            <button type="button" onClick={() => undo(r)} disabled={busy}
                                                className={`shrink-0 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-45 ${FOCUS}`}>
                                                Undo
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </Card>
        </div>
    );
}
