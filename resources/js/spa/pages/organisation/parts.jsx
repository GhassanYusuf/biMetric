import { useRef } from 'react';
import { ActionMenu, Avatar, Badge, SectionCard } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';

/*
| The Company › Department list as the Companies & departments page shows it, shared by
| the desktop and mobile layouts so they cannot drift (rule 5). Companies and
| departments are two tables since 2026-11-03, each department flat under one company;
| the page builds the list with shared/orgUnits.js `buildOrg`.
*/

export function people(n) {
    return `${n.toLocaleString()} ${n === 1 ? 'person' : 'people'}`;
}

const ICONS = {
    departments: 'M4 21V5a1 1 0 011-1h9a1 1 0 011 1v16M15 10h4a1 1 0 011 1v10M3 21h18M8 8h3M8 12h3M8 16h3',
    people: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z',
    terminals: 'M7 3h10a1 1 0 011 1v16a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zM9 7h6M9 11h6M10 17h4',
};

/** One stat as an icon and its number, labelled for hover and screen readers (rule 7). */
function Stat({ icon, value, label, tone = 'slate' }) {
    const tones = {
        slate: 'bg-slate-100 text-slate-700',
        emerald: 'bg-emerald-50 text-emerald-700',
        rose: 'bg-rose-50 text-rose-700',
    };
    return (
        <span title={label} aria-label={label} role="img" className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums ${tones[tone]}`}>
            {icon ? (
                <svg className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={ICONS[icon]} /></svg>
            ) : (
                <span className={`h-2 w-2 rounded-full ${tone === 'emerald' ? 'bg-emerald-500' : 'bg-rose-500'}`} aria-hidden="true" />
            )}
            {value.toLocaleString()}
        </span>
    );
}

/**
 * The card's line as badges, not words (owner, 2026-11-11: "don't put numbers next to
 * department, people, terminal, active — put logos and badges"): departments, people, terminals,
 * then green active and red inactive terminals (each terminal's Active switch).
 */
export function CompanyStats({ company }) {
    const on = Number(company.devices_active_count ?? 0);
    const off = Number(company.devices_inactive_count ?? 0);
    const depts = company.departments.length;
    const ppl = Number(company.employees_count ?? 0);
    return (
        <span className="mt-1.5 flex flex-wrap items-center gap-1.5">
            <Stat icon="departments" value={depts} label={`${depts} department${depts === 1 ? '' : 's'}`} />
            <Stat icon="people" value={ppl} label={people(ppl)} />
            <Stat icon="terminals" value={on + off} label={`${on + off} terminal${on + off === 1 ? '' : 's'}`} />
            {on > 0 && <Stat value={on} tone="emerald" label={`${on} active terminal${on === 1 ? '' : 's'}`} />}
            {off > 0 && <Stat value={off} tone="rose" label={`${off} inactive terminal${off === 1 ? '' : 's'}`} />}
        </span>
    );
}

function LinkButton({ children, tone = 'slate', ...props }) {
    const tones = {
        slate: 'text-slate-600 hover:text-slate-900',
        violet: 'text-violet-700 hover:text-violet-900',
        rose: 'text-rose-600 hover:text-rose-800',
    };
    return (
        <button type="button" className={`min-h-[32px] rounded-lg px-1.5 text-sm font-medium ${tones[tone]} ${FOCUS}`} {...props}>
            {children}
        </button>
    );
}

/** One company and its departments, with the actions a manager has. */
export function CompanyCard({ company, canManage, onAddDepartment, onEdit, onDelete, onLogo, onRemoveLogo }) {
    // A hidden file input behind the Logo button (2026-11-11).
    const file = useRef(null);
    return (
        <SectionCard
            title={(
                <span className="flex items-center gap-3">
                    {/* The company's logo, or its initials (shared Avatar, as on Employees). */}
                    <Avatar src={company.logo_url} name={company.name} size="h-10 w-10" className="!rounded-lg !object-contain" />
                    <span className="min-w-0 truncate">{company.name}</span>
                </span>
            )}
            subtitle={<CompanyStats company={company} />}
            bodyClass="px-3 pb-3 pt-2"
            action={canManage && (
                <div className="flex shrink-0 items-center">
                    <input
                        ref={file}
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        className="hidden"
                        onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) onLogo?.(company, f); }}
                    />
                    {/* Logo, rename and delete under one ⋯ menu (owner, 2026-11-11). */}
                    <ActionMenu
                        dots
                        label={`Actions for ${company.name}`}
                        items={[
                            { key: 'logo', label: company.logo_url ? 'Change logo' : 'Add logo', onSelect: () => file.current?.click() },
                            company.logo_url && { key: 'nologo', label: 'Remove logo', onSelect: () => onRemoveLogo?.(company) },
                            { key: 'rename', label: 'Rename', onSelect: () => onEdit(company, 'company') },
                            { separator: true, key: 'sep' },
                            { key: 'delete', label: 'Delete company', tone: 'danger', onSelect: () => onDelete(company, 'company') },
                        ]}
                    />
                </div>
            )}
        >
            {company.departments.length === 0 ? (
                <p className="px-2 py-3 text-sm text-slate-500">No departments yet.</p>
            ) : (
                <ul className="divide-y divide-slate-100">
                    {company.departments.map((d) => (
                        <li key={d.id} className="flex items-center gap-3 px-2 py-2.5">
                            <span className="min-w-0 flex-1 truncate text-sm text-slate-800">{d.name}</span>
                            <Badge tone="slate">{people(d.employees_count)}</Badge>
                            {canManage && (
                                <div className="flex shrink-0 items-center">
                                    <LinkButton onClick={() => onEdit(d, 'department')} aria-label={`Edit ${d.name}`}>Edit</LinkButton>
                                    <LinkButton tone="rose" onClick={() => onDelete(d, 'department')} aria-label={`Delete ${d.name}`}>Delete</LinkButton>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {company.direct > 0 && (
                <p className="px-2 pt-2 text-xs text-slate-500">
                    {people(company.direct)} directly under {company.name}, in no department yet.
                </p>
            )}
            {canManage && (
                <div className="px-1 pt-2">
                    <LinkButton tone="violet" onClick={() => onAddDepartment(company)}>+ Add department</LinkButton>
                </div>
            )}
        </SectionCard>
    );
}
