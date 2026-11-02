import { useMemo } from 'react';
import { FOCUS } from './theme';
import { buildOrg, companyKey, departmentKey } from './orgUnits';

/*
| Tick one or more companies / departments (owner, 2026-10-19: "give me the option to
| select multiple department"). Shown as Company › Department, indented.
|
| Two tables since 2026-11-03, each department flat under one company. Ticking a
| company includes everyone in it — the server reads `company_ids[]` as the whole
| company, departments included — so its departments show as ticked and locked,
| labelled "included", rather than inviting a second tick that changes nothing.
| Ticking a company drops its now-redundant departments from `value`.
|
| companies    GET /companies' list
| departments  GET /departments' list ({ id, company_id, name })
| value        ['c:4', 'd:13', …] — keys, because a company and a department can share
|              an id (shared/orgUnits.jsx). The caller splits them into company_ids[]
|              and department_ids[].
*/
export default function DepartmentChecklist({ companies, departments, value, onChange, error }) {
    const rows = useMemo(() => buildOrg(companies ?? [], departments ?? []).flatMap((c) => [
        { key: companyKey(c.id), name: c.name, depth: 0, company: null },
        ...c.departments.map((d) => ({ key: departmentKey(d.id), name: d.name, depth: 1, company: companyKey(c.id) })),
    ]), [companies, departments]);

    const chosen = new Set(value);

    function toggle(row) {
        if (chosen.has(row.key)) {
            onChange(value.filter((key) => key !== row.key));
            return;
        }
        const redundant = new Set(row.depth === 0 ? rows.filter((r) => r.company === row.key).map((r) => r.key) : []);
        onChange([...value.filter((key) => !redundant.has(key)), row.key]);
    }

    return (
        <div>
            <div className="flex items-baseline justify-between gap-2">
                <p id="department-checklist" className="text-sm font-medium text-slate-700">Companies and departments</p>
                {value.length > 0 && (
                    <button type="button" onClick={() => onChange([])} className={`rounded px-1 text-xs font-medium text-slate-600 hover:text-slate-900 ${FOCUS}`}>
                        Clear ({value.length})
                    </button>
                )}
            </div>
            <ul
                role="group"
                aria-labelledby="department-checklist"
                aria-describedby={error ? 'department-checklist-error' : undefined}
                className={`mt-2 max-h-60 overflow-y-auto rounded-xl ring-1 ${error ? 'ring-rose-300' : 'ring-slate-200'}`}
            >
                {rows.map((row) => {
                    const implied = row.company !== null && chosen.has(row.company);
                    const on = implied || chosen.has(row.key);
                    return (
                        <li key={row.key}>
                            <button
                                type="button"
                                role="checkbox"
                                aria-checked={on}
                                aria-disabled={implied || undefined}
                                onClick={implied ? undefined : () => toggle(row)}
                                style={{ paddingLeft: `${0.875 + row.depth * 1.25}rem` }}
                                className={`flex min-h-[44px] w-full items-center gap-3 py-2 pr-3.5 text-left text-sm ${implied ? 'cursor-default' : 'hover:bg-slate-50'} ${on && !implied ? 'bg-violet-50/60' : ''} ${FOCUS}`}
                            >
                                <span className={`grid h-4 w-4 shrink-0 place-items-center rounded border ${on ? (implied ? 'border-violet-300 bg-violet-300 text-white' : 'border-violet-600 bg-violet-600 text-white') : 'border-slate-400'}`} aria-hidden="true">
                                    {on && <svg viewBox="0 0 20 20" fill="currentColor" className="h-3 w-3"><path fillRule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.58l7.3-7.3a1 1 0 0 1 1.4 0Z" clipRule="evenodd" /></svg>}
                                </span>
                                <span className={`min-w-0 flex-1 truncate ${row.depth === 0 ? 'font-medium text-slate-900' : 'text-slate-700'}`}>{row.name}</span>
                                {implied && <span className="shrink-0 text-xs text-slate-500">included</span>}
                            </button>
                        </li>
                    );
                })}
                {rows.length === 0 && <li className="px-3.5 py-3 text-sm text-slate-500">No companies yet.</li>}
            </ul>
            {error
                ? <p id="department-checklist-error" className="mt-1 text-xs text-rose-700">{error}</p>
                : <p className="mt-1 text-xs text-slate-500">A company includes all its departments.</p>}
        </div>
    );
}
