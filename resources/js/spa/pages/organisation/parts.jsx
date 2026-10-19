import { Badge, SectionCard } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';

/*
| The Company › Department tree as the Companies & departments page shows it, shared by
| the desktop and mobile layouts so they cannot drift (rule 5). The tree is the one
| `departments` table: a company is a node with no parent (or whose parent is gone),
| its departments sit beneath it — see the add_parent_id_to_departments migration.
*/

/** Companies, each with its departments (deeper levels labelled by path) and head count. */
export function buildTree(rows) {
    const list = rows.map((d) => ({
        ...d,
        id: Number(d.id),
        parent_id: d.parent_id == null ? null : Number(d.parent_id),
        employees_count: Number(d.employees_count ?? 0),
    }));
    const ids = new Set(list.map((d) => d.id));
    const byParent = new Map();
    list.forEach((d) => {
        const key = d.parent_id != null && ids.has(d.parent_id) ? d.parent_id : null;
        byParent.set(key, [...(byParent.get(key) ?? []), d]);
    });
    const byName = (a, b) => a.name.localeCompare(b.name);

    return (byParent.get(null) ?? []).sort(byName).map((company) => {
        const departments = [];
        const seen = new Set([company.id]);
        const walk = (parentId, prefix) => {
            (byParent.get(parentId) ?? []).slice().sort(byName).forEach((d) => {
                if (seen.has(d.id)) return; // a cycle in the data must not hang the page
                seen.add(d.id);
                const path = prefix ? `${prefix} › ${d.name}` : d.name;
                departments.push({ ...d, path });
                walk(d.id, path);
            });
        };
        walk(company.id, '');
        return {
            ...company,
            departments,
            total: company.employees_count + departments.reduce((n, d) => n + d.employees_count, 0),
        };
    });
}

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
            subtitle={`${company.departments.length} department${company.departments.length === 1 ? '' : 's'} · ${people(company.total)}`}
            bodyClass="px-3 pb-3 pt-2"
            action={canManage && (
                <div className="flex shrink-0 items-center gap-1">
                    <LinkButton onClick={() => onEdit(company)} aria-label={`Rename ${company.name}`}>Rename</LinkButton>
                    <LinkButton tone="rose" onClick={() => onDelete(company)} aria-label={`Delete ${company.name}`}>Delete</LinkButton>
                </div>
            )}
        >
            {company.departments.length === 0 ? (
                <p className="px-2 py-3 text-sm text-slate-500">No departments yet.</p>
            ) : (
                <ul className="divide-y divide-slate-100">
                    {company.departments.map((d) => (
                        <li key={d.id} className="flex items-center gap-3 px-2 py-2.5">
                            <span className="min-w-0 flex-1 truncate text-sm text-slate-800">{d.path}</span>
                            <Badge tone="slate">{people(d.employees_count)}</Badge>
                            {canManage && (
                                <div className="flex shrink-0 items-center">
                                    <LinkButton onClick={() => onEdit(d)} aria-label={`Edit ${d.name}`}>Edit</LinkButton>
                                    <LinkButton tone="rose" onClick={() => onDelete(d)} aria-label={`Delete ${d.name}`}>Delete</LinkButton>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {company.employees_count > 0 && (
                <p className="px-2 pt-2 text-xs text-slate-500">
                    {people(company.employees_count)} directly under {company.name}, in no department yet.
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
