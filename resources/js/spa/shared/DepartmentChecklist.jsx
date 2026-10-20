import { useMemo } from 'react';
import { FOCUS } from './theme';

/*
| Tick one or more companies / departments (owner, 2026-10-19: "give me the option to
| select multiple department"). Shown as the Company › Department tree, indented.
|
| Ticking a company or department includes everything below it — the server takes each
| one's subtree (Department::subtreeIds) — so the rows below a ticked one show as ticked
| and locked, labelled "included", rather than inviting a second tick that changes
| nothing. Ticking a parent drops its now-redundant children from `value`.
|
| departments  [{ id, parent_id, name }] — the tenant's list from GET /departments
| value        [id, …] numbers
*/
export default function DepartmentChecklist({ departments, value, onChange, error }) {
    const { rows, below } = useMemo(() => {
        // Ids can arrive as strings (SQL Server); compare as numbers throughout.
        const list = departments.map((d) => ({ ...d, id: Number(d.id), parent_id: d.parent_id == null ? null : Number(d.parent_id) }));
        const byParent = new Map();
        const ids = new Set(list.map((d) => d.id));
        list.forEach((d) => {
            // A parent outside the list (or none) makes it a root.
            const key = d.parent_id && ids.has(d.parent_id) ? d.parent_id : null;
            if (!byParent.has(key)) byParent.set(key, []);
            byParent.get(key).push(d);
        });
        byParent.forEach((list) => list.sort((a, b) => a.name.localeCompare(b.name)));

        const out = [];
        const descendants = new Map();
        const seen = new Set();
        const walk = (parent, depth, ancestors) => {
            (byParent.get(parent) ?? []).forEach((d) => {
                if (seen.has(d.id)) return; // a cycle in the data
                seen.add(d.id);
                out.push({ ...d, depth, ancestors });
                descendants.set(d.id, []);
                ancestors.forEach((a) => descendants.get(a).push(d.id));
                walk(d.id, depth + 1, [...ancestors, d.id]);
            });
        };
        walk(null, 0, []);
        return { rows: out, below: descendants };
    }, [departments]);

    const chosen = new Set(value);

    function toggle(row) {
        if (chosen.has(row.id)) {
            onChange(value.filter((id) => id !== row.id));
            return;
        }
        const redundant = new Set(below.get(row.id) ?? []);
        onChange([...value.filter((id) => !redundant.has(id)), row.id]);
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
                    const implied = row.ancestors.some((a) => chosen.has(a));
                    const on = implied || chosen.has(row.id);
                    return (
                        <li key={row.id}>
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
                {rows.length === 0 && <li className="px-3.5 py-3 text-sm text-slate-500">No departments yet.</li>}
            </ul>
            {error
                ? <p id="department-checklist-error" className="mt-1 text-xs text-rose-700">{error}</p>
                : <p className="mt-1 text-xs text-slate-500">Each one includes the departments below it.</p>}
        </div>
    );
}
