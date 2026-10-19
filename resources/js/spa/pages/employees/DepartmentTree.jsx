import { useMemo, useState } from 'react';
import { FOCUS } from '../../shared/theme';
import { Skeleton } from '../../shared/ui';

/*
| The Company › Department tree on the Employees page (2026-10-11, owner's request).
|
| Departments carry a parent_id: a company is a department with no parent, and the
| staff import's `company` column builds that top level. Selecting a node filters the
| roster to it AND everything beneath it — the server walks the subtree
| (Department::subtreeIds) — so the count shown beside a node is its own employees
| plus its descendants', which is exactly what clicking it will list.
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

function buildTree(departments) {
    const byId = new Map(departments.map((d) => [String(d.id), { ...d, children: [] }]));
    const roots = [];
    for (const node of byId.values()) {
        // A parent that is gone (soft-deleted, or another tenant's) makes this a root
        // rather than hiding it and the people in it.
        const parent = node.parent_id != null ? byId.get(String(node.parent_id)) : null;
        if (parent && parent !== node) parent.children.push(node);
        else roots.push(node);
    }
    const byName = (a, b) => a.name.localeCompare(b.name);

    // Roll counts up so a company shows everyone it will list. `seen` guards against
    // a parent cycle, which the data does not prevent.
    const seen = new Set();
    function rollUp(node) {
        if (seen.has(node)) return 0;
        seen.add(node);
        node.children.sort(byName);
        node.total = (node.employees_count ?? 0) + node.children.reduce((sum, c) => sum + rollUp(c), 0);
        return node.total;
    }
    roots.sort(byName).forEach(rollUp);

    return { roots, byId };
}

// The ids from the root down to `id`, so a selected node's branch starts open.
function pathTo(byId, id) {
    const path = [];
    let node = id ? byId.get(String(id)) : null;
    while (node && !path.includes(String(node.id))) {
        path.unshift(String(node.id));
        node = node.parent_id != null ? byId.get(String(node.parent_id)) : null;
    }
    return path;
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

function TreeNode({ node, depth, value, expanded, onToggle, onChange, counts }) {
    const id = String(node.id);
    const open = expanded.has(id);
    const hasChildren = node.children.length > 0;

    return (
        <li>
            <div className="flex items-center gap-0.5">
                {hasChildren ? (
                    <button
                        type="button"
                        onClick={() => onToggle(id)}
                        aria-expanded={open}
                        aria-label={`${open ? 'Collapse' : 'Expand'} ${node.name}`}
                        className={`grid h-7 w-7 shrink-0 place-items-center rounded-md hover:bg-slate-100 ${FOCUS}`}
                    >
                        <Chevron open={open} />
                    </button>
                ) : (
                    <span className="h-7 w-7 shrink-0" aria-hidden="true" />
                )}
                <NodeButton
                    label={node.name}
                    count={counts ? node.total : null}
                    selected={value === id}
                    emphasis={depth === 0}
                    onSelect={() => onChange(id)}
                />
            </div>
            {hasChildren && open && (
                <ul className="ml-3.5 border-l border-slate-100 pl-1">
                    {node.children.map((child) => (
                        <TreeNode key={child.id} node={child} depth={depth + 1} value={value}
                            expanded={expanded} onToggle={onToggle} onChange={onChange} counts={counts} />
                    ))}
                </ul>
            )}
        </li>
    );
}

export default function DepartmentTree({
    departments, unassignedCount = 0, loaded = true,
    value = '', onChange, variant = 'panel', counts = true, allLabel = 'All employees',
}) {
    const { roots, byId } = useMemo(() => buildTree(departments ?? []), [departments]);
    // What the operator opened or closed by hand: id -> true/false.
    const [toggled, setToggled] = useState(() => ({}));
    const [mobileOpen, setMobileOpen] = useState(false);

    // Open = the selected node's branch unless closed by hand, plus anything opened
    // by hand. Derived rather than stored so a live refresh of the department list
    // (rule 18) cannot collapse the tree under the operator.
    const expanded = useMemo(() => {
        const set = new Set(pathTo(byId, value).slice(0, -1));
        for (const [id, open] of Object.entries(toggled)) {
            if (open) set.add(id); else set.delete(id);
        }
        return set;
    }, [toggled, byId, value]);

    function toggle(id) {
        setToggled((prev) => ({ ...prev, [id]: !expanded.has(id) }));
    }

    function select(id) {
        onChange(id);
        setMobileOpen(false);
    }

    const total = roots.reduce((sum, r) => sum + r.total, 0) + unassignedCount;
    const selectedLabel = value === 'none'
        ? 'Unassigned'
        : value
            ? pathTo(byId, value).map((id) => byId.get(id)?.name).filter(Boolean).join(' › ')
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
                {roots.map((node) => (
                    <TreeNode key={node.id} node={node} depth={0} value={value}
                        expanded={expanded} onToggle={toggle} onChange={select} counts={counts} />
                ))}
                {unassignedCount > 0 && (
                    <li className="mt-1 flex border-t border-slate-100 pt-1">
                        <NodeButton label="Unassigned" count={counts ? unassignedCount : null} selected={value === 'none'} onSelect={() => select('none')} />
                    </li>
                )}
            </ul>
            {roots.length === 0 && (
                <p className="px-2 pt-2 text-xs text-slate-500">
                    No departments yet. Add <code>company</code> and <code>department</code> columns to the staff import.
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
