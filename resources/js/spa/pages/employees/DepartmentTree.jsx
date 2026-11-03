import { useMemo, useState } from 'react';
import { FOCUS } from '../../shared/theme';
import { Skeleton } from '../../shared/ui';
import { buildOrg, companyKey, departmentKey } from '../../shared/orgUnits';

/*
| The Company › Department tree on the Employees page (2026-10-11, owner's request).
|
| Two levels since the companies table (2026-11-03): companies, and the departments
| flat beneath each — no sub-departments. Selecting a company filters by `company_id`,
| which the server reads as everyone in it, its departments included; selecting a
| department filters by `department_id`. The count beside a company is therefore its
| whole head count, which is exactly what clicking it will list.
|
| Nodes are keys, not ids — 'c:4', 'd:13', 'none' (shared/orgUnits.js) — because a
| company and a department can share a number.
|
| Shared by desktop (a panel on the left) and mobile (a collapsible card) so the two
| behave identically (rule 5); only `variant` changes the frame around it.
|
| Also on the Attendance page (2026-10-11), with `counts={false}`: the numbers here
| are employees, and beside a punch log they would read as punch counts.
|
| "Unassigned" is a node of its own because nobody in it shows under any company,
| and a group that no grouping shows is a group that gets forgotten.
*/

// The company a key sits in: itself, or the department's company.
function companyOf(org, key) {
    const match = /^([cd]):(\d+)$/.exec(key ?? '');
    if (!match) return null;
    const id = Number(match[2]);
    if (match[1] === 'c') return org.find((c) => c.id === id) ?? null;
    return org.find((c) => c.departments.some((d) => d.id === id)) ?? null;
}

function Chevron({ open }) {
    return (
        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"
            className={`h-4 w-4 text-slate-400 transition-transform duration-150 ${open ? 'rotate-90' : ''}`}>
            <path fillRule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clipRule="evenodd" />
        </svg>
    );
}

function NodeButton({ label, count, selected, depth = 0, onSelect, emphasis = false }) {
    // `count` null = counts are hidden on this page, not zero.
    return (
        <button
            type="button"
            onClick={onSelect}
            aria-current={selected ? 'true' : undefined}
            className={`flex min-h-[36px] min-w-0 flex-1 items-center justify-between gap-2 rounded-lg px-2 text-left text-sm transition-colors ${
                selected
                    ? 'bg-violet-50 font-semibold text-violet-800'
                    : `${emphasis ? 'font-medium text-slate-900' : 'text-slate-700'} hover:bg-slate-50`
            } ${FOCUS}`}
            style={depth ? { paddingLeft: `${0.5 + depth * 0.75}rem` } : undefined}
        >
            <span className="truncate">{label}</span>
            {count != null && (
                <span className={`shrink-0 tabular-nums text-xs ${selected ? 'text-violet-700' : 'text-slate-500'}`}>
                    {count.toLocaleString()}
                </span>
            )}
        </button>
    );
}

function CompanyNode({ company, value, open, onToggle, onChange, counts, countOf }) {
    const key = companyKey(company.id);
    const hasChildren = company.departments.length > 0;

    return (
        <li>
            <div className="flex items-center gap-0.5">
                {hasChildren ? (
                    <button
                        type="button"
                        onClick={() => onToggle(key)}
                        aria-expanded={open}
                        aria-label={`${open ? 'Collapse' : 'Expand'} ${company.name}`}
                        className={`grid h-7 w-7 shrink-0 place-items-center rounded-md hover:bg-slate-100 ${FOCUS}`}
                    >
                        <Chevron open={open} />
                    </button>
                ) : (
                    <span className="h-7 w-7 shrink-0" aria-hidden="true" />
                )}
                <NodeButton
                    label={company.name}
                    count={counts ? countOf(company) : null}
                    selected={value === key}
                    emphasis
                    onSelect={() => onChange(key)}
                />
            </div>
            {hasChildren && open && (
                <ul className="ml-3.5 border-l border-slate-100 pl-1">
                    {company.departments.map((d) => (
                        <li key={d.id}>
                            <div className="flex items-center gap-0.5">
                                <span className="h-7 w-7 shrink-0" aria-hidden="true" />
                                <NodeButton
                                    label={d.name}
                                    count={counts ? countOf(d) : null}
                                    selected={value === departmentKey(d.id)}
                                    onSelect={() => onChange(departmentKey(d.id))}
                                />
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}

export default function DepartmentTree({
    companies, departments, unassignedCount = 0, loaded = true,
    value = '', onChange, variant = 'panel', counts = true, allLabel = 'All employees',
    // Which people the numbers count, matching the list's Status filter (2026-11-03: the
    // tree said 117 beside PROMOSEVEN while the list, which hides the resigned, said 111):
    // 'all', 'current' (everyone but the resigned) or 'resigned'. `unassignedCount` is
    // the caller's, already counted the same way.
    countKind = 'all',
}) {
    const countOf = (node) => (countKind === 'current' ? node.current_count
        : countKind === 'resigned' ? node.employees_count - node.current_count
            : node.employees_count);
    const org = useMemo(() => buildOrg(companies ?? [], departments ?? []), [companies, departments]);
    // What the operator opened or closed by hand: company key -> true/false.
    const [toggled, setToggled] = useState(() => ({}));
    const [mobileOpen, setMobileOpen] = useState(false);

    // Open = the selected department's company unless closed by hand, plus anything
    // opened by hand. Derived rather than stored so a live refresh of the lists
    // (rule 18) cannot collapse the tree under the operator.
    const selectedCompany = companyOf(org, value);
    const expanded = useMemo(() => {
        const set = new Set(selectedCompany && value.startsWith('d:') ? [companyKey(selectedCompany.id)] : []);
        for (const [key, open] of Object.entries(toggled)) {
            if (open) set.add(key); else set.delete(key);
        }
        return set;
    }, [toggled, selectedCompany, value]);

    function toggle(key) {
        setToggled((prev) => ({ ...prev, [key]: !expanded.has(key) }));
    }

    function select(key) {
        onChange(key);
        setMobileOpen(false);
    }

    const total = org.reduce((sum, c) => sum + countOf(c), 0) + unassignedCount;
    const selectedDepartment = value.startsWith('d:')
        ? selectedCompany?.departments.find((d) => departmentKey(d.id) === value)
        : null;
    const selectedLabel = value === 'none'
        ? 'Unassigned'
        : value
            ? [selectedCompany?.name, selectedDepartment?.name].filter(Boolean).join(' › ')
            : allLabel;

    const body = !loaded ? (
        <div className="space-y-2 p-1" aria-hidden="true">
            {[0, 1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-7 w-full" />)}
        </div>
    ) : (
        <nav aria-label="Companies and departments">
            <ul className="space-y-0.5">
                <li className="flex">
                    <NodeButton label={allLabel} count={counts ? total : null} selected={!value} emphasis onSelect={() => select('')} />
                </li>
                {org.map((company) => (
                    <CompanyNode key={company.id} company={company} value={value}
                        open={expanded.has(companyKey(company.id))} onToggle={toggle} onChange={select} counts={counts} countOf={countOf} />
                ))}
                {unassignedCount > 0 && (
                    <li className="mt-1 flex border-t border-slate-100 pt-1">
                        <NodeButton label="Unassigned" count={counts ? unassignedCount : null} selected={value === 'none'} onSelect={() => select('none')} />
                    </li>
                )}
            </ul>
            {org.length === 0 && (
                <p className="px-2 pt-2 text-xs text-slate-500">
                    No companies yet. Add <code>company</code> and <code>department</code> columns to the staff import.
                </p>
            )}
        </nav>
    );

    if (variant === 'collapsible') {
        return (
            <div className="rounded-2xl bg-white ring-1 ring-slate-900/5">
                <button
                    type="button"
                    onClick={() => setMobileOpen((o) => !o)}
                    aria-expanded={mobileOpen}
                    className={`flex min-h-[44px] w-full items-center justify-between gap-2 rounded-2xl px-4 text-left ${FOCUS}`}
                >
                    <span className="min-w-0">
                        <span className="block text-xs font-medium text-slate-500">Company › Department</span>
                        <span className="block truncate text-sm font-semibold text-slate-900">{selectedLabel}</span>
                    </span>
                    <Chevron open={mobileOpen} />
                </button>
                {mobileOpen && <div className="border-t border-slate-100 p-2">{body}</div>}
            </div>
        );
    }

    return (
        <aside className="rounded-2xl bg-white p-2 ring-1 ring-slate-900/5">
            <h2 className="px-2 pb-2 pt-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                Company › Department
            </h2>
            {body}
        </aside>
    );
}
