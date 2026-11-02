import { Badge, SectionCard } from '../../shared/ui';
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
export function CompanyCard({ company, canManage, onAddDepartment, onEdit, onDelete }) {
    return (
        <SectionCard
            title={company.name}
            subtitle={`${company.departments.length} department${company.departments.length === 1 ? '' : 's'} · ${people(company.employees_count)}`}
            bodyClass="px-3 pb-3 pt-2"
            action={canManage && (
                <div className="flex shrink-0 items-center gap-1">
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
