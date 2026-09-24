import { useState } from 'react';
import { FOCUS } from '../../shared/theme';

/*
| Two tables with >> > < << between them, as on the ZKAccess Personnel dialog.
| Generic: the employee form uses it for the terminals a person is on (2026-09-24).
|
| items   [{ id, name, detail }] — everything that can appear on either side
| locked  ids that may not leave the right-hand side (e.g. a terminal this app
|         cannot write to: moving it left would promise a removal we cannot do)
*/
export default function TransferPicker({
    items, selected, onChange, disabled = false, locked = [],
    titles = { left: 'Available', right: 'Selected' },
    columns = { name: 'Name', detail: 'Detail' },
    empty = { left: 'Nothing here.', right: 'None selected.' },
}) {
    // Highlighted rows in each list, waiting for › or ‹.
    const [marked, setMarked] = useState({ available: [], authorized: [] });

    const available = items.filter((l) => !selected.includes(l.id));
    const authorized = items.filter((l) => selected.includes(l.id));

    function toggle(side, id) {
        setMarked((m) => ({
            ...m,
            [side]: m[side].includes(id) ? m[side].filter((x) => x !== id) : [...m[side], id],
        }));
    }

    function markAll(side, ids) {
        setMarked((m) => ({ ...m, [side]: ids }));
    }

    function move(ids, toAuthorized) {
        onChange(toAuthorized ? [...new Set([...selected, ...ids])] : selected.filter((id) => !ids.includes(id) || locked.includes(id)));
        setMarked({ available: [], authorized: [] });
    }

    const arrows = [
        { label: '>>', title: 'Add all', onClick: () => move(available.map((l) => l.id), true), off: available.length === 0 },
        { label: '>', title: 'Add selected', onClick: () => move(marked.available, true), off: marked.available.length === 0 },
        { label: '<', title: 'Remove selected', onClick: () => move(marked.authorized, false), off: marked.authorized.length === 0 },
        { label: '<<', title: 'Remove all', onClick: () => move(authorized.map((l) => l.id), false), off: authorized.every((l) => locked.includes(l.id)) },
    ];

    return (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_auto_1fr] sm:items-stretch">
            <LevelList
                title={titles.left}
                columns={columns}
                items={available}
                marked={marked.available}
                onToggle={(id) => toggle('available', id)}
                onMarkAll={(ids) => markAll('available', ids)}
                onActivate={(id) => move([id], true)}
                empty={empty.left}
                disabled={disabled}
            />
            <div className="flex flex-row justify-center gap-1.5 sm:flex-col">
                {arrows.map((a) => (
                    <button
                        key={a.label}
                        type="button"
                        title={a.title}
                        aria-label={a.title}
                        onClick={a.onClick}
                        disabled={disabled || a.off}
                        className={`h-9 w-11 rounded-lg border border-slate-200 bg-white text-base font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 ${FOCUS}`}
                    >
                        {a.label}
                    </button>
                ))}
            </div>
            <LevelList
                title={titles.right}
                columns={columns}
                locked={locked}
                items={authorized}
                marked={marked.authorized}
                onToggle={(id) => toggle('authorized', id)}
                onMarkAll={(ids) => markAll('authorized', ids)}
                onActivate={(id) => move([id], false)}
                empty={empty.right}
                disabled={disabled}
            />
        </div>
    );
}

/*
| One side as a table. The checkbox is the keyboard path (Space toggles it); clicking
| anywhere on the row toggles too, and a double-click moves the row across.
*/
function LevelList({ title, columns, items, marked, onToggle, onMarkAll, onActivate, empty, disabled, locked = [] }) {
    const movable = items.filter((l) => !locked.includes(l.id));
    const allMarked = movable.length > 0 && movable.every((l) => marked.includes(l.id));

    return (
        <div className="flex min-w-0 flex-col overflow-hidden rounded-xl border border-slate-200">
            <div className="flex items-baseline justify-between border-b border-slate-100 px-3 py-2">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</span>
                <span className="text-xs tabular-nums text-slate-500">{items.length}</span>
            </div>
            <div className="h-56 overflow-y-auto">
                <table className="w-full table-fixed text-sm" aria-label={title}>
                    <thead className="sticky top-0 bg-slate-50 text-left text-xs font-medium text-slate-500">
                        <tr>
                            <th className="w-10 px-3 py-2">
                                <input
                                    type="checkbox"
                                    checked={allMarked}
                                    onChange={() => onMarkAll(allMarked ? [] : movable.map((l) => l.id))}
                                    disabled={disabled || movable.length === 0}
                                    aria-label={`Select all ${title.toLowerCase()}`}
                                    className={`h-4 w-4 rounded border-slate-300 text-violet-600 ${FOCUS}`}
                                />
                            </th>
                            <th className="px-2 py-2">{columns.name}</th>
                            <th className="px-2 py-2">{columns.detail}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.length === 0 ? (
                            <tr>
                                <td colSpan={3} className="px-3 py-3 text-xs text-slate-500">{empty}</td>
                            </tr>
                        ) : (
                            items.map((l) => {
                                const on = marked.includes(l.id);
                                const fixed = locked.includes(l.id);
                                const off = disabled || fixed;
                                return (
                                    <tr
                                        key={l.id}
                                        onClick={off ? undefined : () => onToggle(l.id)}
                                        onDoubleClick={off ? undefined : () => onActivate(l.id)}
                                        className={`${off ? '' : 'cursor-pointer'} border-t border-slate-100 select-none ${on ? 'bg-violet-50 text-violet-900' : 'text-slate-700 hover:bg-slate-50'}`}
                                    >
                                        <td className="px-3 py-1.5">
                                            <input
                                                type="checkbox"
                                                checked={on}
                                                onChange={() => onToggle(l.id)}
                                                onClick={(e) => e.stopPropagation()}
                                                disabled={off}
                                                aria-label={l.name}
                                                className={`h-4 w-4 rounded border-slate-300 text-violet-600 ${FOCUS}`}
                                            />
                                        </td>
                                        <td className="truncate px-2 py-1.5">{l.name}</td>
                                        <td className="truncate px-2 py-1.5 text-xs text-slate-500" title={l.detail || undefined}>{l.detail || '—'}</td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
