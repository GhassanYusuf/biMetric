import { useMemo, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { Button, Card, SelectField } from '../../shared/ui';
import { useToast } from '../../shared/toast';
import { useEscapeKey } from '../../shared/useEscapeKey';
import { buildOrg } from '../../shared/orgUnits';
import { orgOf } from './employeeLabels';

/*
| Move the ticked employees (owner, 2026-11-16: "select employees and move either from company to
| department by multi select or from company to company with the ability to select the
| department"). One dialog for both: keep the company and pick a department to move between
| departments; pick another company to move between companies, into one of its departments or
| directly under it. POST /employees/bulk-move (EmployeeController::bulkMove), which checks the
| company is one this user can see and the department is in it, and saves each person on their own
| so the audit log records every move. Terminals are not touched: this is the system's grouping.
*/
export default function BulkMoveEmployeesModal({ employees, companies, departments, onDone, onClose }) {
    const toast = useToast();
    const org = useMemo(() => buildOrg(companies, departments), [companies, departments]);
    const count = employees.length;
    const people = `${count.toLocaleString()} ${count === 1 ? 'employee' : 'employees'}`;

    // Everyone ticked in one company: start there, so a department move is one choice.
    const theirCompanies = [...new Set(employees.map((e) => (e.company_id != null ? Number(e.company_id) : null)))];
    const [companyId, setCompanyId] = useState(theirCompanies.length === 1 && theirCompanies[0] != null ? String(theirCompanies[0]) : '');
    const [departmentId, setDepartmentId] = useState('');
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    useEscapeKey(onClose, !busy);

    const company = org.find((c) => String(c.id) === companyId) ?? null;

    // Where they are now, grouped, so the operator sees what is about to change.
    const from = useMemo(() => {
        const counts = new Map();
        employees.forEach((e) => {
            const o = orgOf(e);
            const key = o.company ? (o.department ? `${o.company} › ${o.department}` : o.company) : 'No company';
            counts.set(key, (counts.get(key) ?? 0) + 1);
        });
        return [...counts.entries()].sort((a, b) => b[1] - a[1]);
    }, [employees]);

    async function go() {
        setBusy(true);
        setErrors({});
        try {
            const res = await api.post('/employees/bulk-move', {
                ids: employees.map((e) => e.id),
                company_id: Number(companyId),
                department_id: departmentId ? Number(departmentId) : null,
            });
            if ((res.data?.refused?.length ?? 0) > 0) toast.error(res.message);
            else toast.success(res.message);
            onDone(res.data ?? {});
        } catch (err) {
            // Field errors go on the field (rule 2); anything else is a toast.
            if (err instanceof ApiError && err.errors && Object.keys(err.errors).length > 0) {
                setErrors(Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v])));
            } else {
                toast.error(err instanceof ApiError ? err.message : 'Could not move the selected employees.');
            }
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
                className="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-b-none sm:rounded-2xl"
                onClick={(e) => e.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-labelledby="bulk-move-title"
            >
                <div className="space-y-4 p-5">
                    <div>
                        <h2 id="bulk-move-title" className="text-lg font-semibold tracking-[-0.01em] text-slate-900">Move {people}</h2>
                        <p className="mt-0.5 text-sm text-slate-500">To another department, or to another company. Their terminals, fingerprints and punches are not touched.</p>
                        <ul className="mt-2 max-h-24 space-y-0.5 overflow-y-auto rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-700">
                            {from.map(([where, n]) => (
                                <li key={where}><span className="font-medium">{n}</span> from {where}</li>
                            ))}
                        </ul>
                    </div>

                    <SelectField
                        label="Company"
                        value={companyId}
                        onChange={(e) => { setCompanyId(e.target.value); setDepartmentId(''); setErrors({}); }}
                        error={errors.company_id}
                        disabled={busy}
                    >
                        <option value="">Choose a company…</option>
                        {org.map((c) => <option key={c.id} value={String(c.id)}>{c.name}</option>)}
                    </SelectField>

                    <SelectField
                        label="Department"
                        value={departmentId}
                        onChange={(e) => { setDepartmentId(e.target.value); setErrors({}); }}
                        error={errors.department_id}
                        disabled={busy || !company}
                    >
                        <option value="">{company ? `No department (directly under ${company.name})` : 'Choose a company first'}</option>
                        {(company?.departments ?? []).map((d) => <option key={d.id} value={String(d.id)}>{d.name}</option>)}
                    </SelectField>

                    <div className="flex flex-wrap justify-end gap-2 pt-1">
                        <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
                        <Button onClick={go} loading={busy} disabled={!company}>
                            {`Move ${people}${company ? ` to ${company.name}${departmentId ? ` › ${company.departments.find((d) => String(d.id) === departmentId)?.name ?? ''}` : ''}` : ''}`}
                        </Button>
                    </div>
                </div>
            </Card>
        </div>
    );
}
