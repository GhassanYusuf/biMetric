import { useRef } from 'react';
import { Avatar, Badge, SectionCard } from '../../shared/ui';
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

/** "3 terminals · 2 active · 1 inactive" (2026-11-11), by each terminal's Active switch. */
export function terminals(company) {
    const on = Number(company.devices_active_count ?? 0);
    const off = Number(company.devices_inactive_count ?? 0);
    const all = on + off;
    if (all === 0) return 'no terminals';
    return `${all} terminal${all === 1 ? '' : 's'} · ${on} active${off ? ` · ${off} inactive` : ''}`;
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
            subtitle={`${company.departments.length} department${company.departments.length === 1 ? '' : 's'} · ${people(company.employees_count)} · ${terminals(company)}`}
            bodyClass="px-3 pb-3 pt-2"
            action={canManage && (
                <div className="flex shrink-0 items-center gap-1">
                    <input
                        ref={file}
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        className="hidden"
                        onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) onLogo?.(company, f); }}
                    />
                    <LinkButton onClick={() => file.current?.click()} aria-label={`${company.logo_url ? 'Change' : 'Add'} ${company.name}'s logo`}>
                        {company.logo_url ? 'Change logo' : 'Add logo'}
                    </LinkButton>
                    {company.logo_url && (
                        <LinkButton tone="rose" onClick={() => onRemoveLogo?.(company)} aria-label={`Remove ${company.name}'s logo`}>Remove logo</LinkButton>
                    )}
                    <LinkButton onClick={() => onEdit(company, 'company')} aria-label={`Rename ${company.name}`}>Rename</LinkButton>
                    <LinkButton tone="rose" onClick={() => onDelete(company, 'company')} aria-label={`Delete ${company.name}`}>Delete</LinkButton>
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
