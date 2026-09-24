import { useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { Button, Card, TextField } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import { useConfirm } from '../../shared/confirm';
import { useToast } from '../../shared/toast';
import { useEscapeKey } from '../../shared/useEscapeKey';

/*
| "Manage levels", opened from the employee form's Access levels tab.
|
| DATABASE ONLY (2026-09-23): these rows reach no C3 panel. `time_zone` is a free
| label ("24-Hour Accessible"), not a panel time-zone index — see
| 2026_09_23_100000_create_access_levels_tables.php.
|
| Rendered OUTSIDE the employee <form> (a nested form is invalid HTML), and it
| stops its own backdrop click so closing this does not close the form behind it.
*/
export default function ManageAccessLevelsModal({ levels, onChange, onClose }) {
    const confirm = useConfirm();
    const toast = useToast();
    const [draft, setDraft] = useState({ name: '', time_zone: '' });
    const [editing, setEditing] = useState(null); // { id, name, time_zone }
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    useEscapeKey(onClose, !busy);

    async function add(e) {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        try {
            const res = await api.post('/access-levels', { name: draft.name, time_zone: draft.time_zone || null });
            onChange([...levels, res.data].sort((a, b) => a.name.localeCompare(b.name)));
            setDraft({ name: '', time_zone: '' });
            toast.success(res.message);
        } catch (err) {
            if (err instanceof ApiError && err.status === 422) setErrors(err.errors || {});
            else toast.error(err instanceof ApiError ? err.message : 'Could not add the access level.');
        } finally {
            setBusy(false);
        }
    }

    async function saveEdit() {
        setBusy(true);
        setErrors({});
        try {
            const res = await api.put(`/access-levels/${editing.id}`, { name: editing.name, time_zone: editing.time_zone || null });
            onChange(levels.map((l) => (l.id === editing.id ? res.data : l)));
            setEditing(null);
            toast.success(res.message);
        } catch (err) {
            if (err instanceof ApiError && err.status === 422) setErrors({ edit: err.errors?.name ?? [err.message] });
            else toast.error(err instanceof ApiError ? err.message : 'Could not save the access level.');
        } finally {
            setBusy(false);
        }
    }

    async function remove(level) {
        const ok = await confirm({
            title: `Delete ${level.name}?`,
            body: 'Everyone authorised for it loses it in this app. Nothing changes on any door panel — access levels are not written to them yet.',
            confirmLabel: 'Delete level',
            danger: true,
        });
        if (!ok) return;

        setBusy(true);
        try {
            const res = await api.delete(`/access-levels/${level.id}`);
            onChange(levels.filter((l) => l.id !== level.id));
            toast.success(res.message);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not delete the access level.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <div
            className="fixed inset-0 z-[60] flex items-end justify-center bg-slate-900/40 sm:items-center sm:p-4"
            onClick={(e) => { e.stopPropagation(); if (!busy) onClose(); }}
            role="presentation"
        >
            <Card
                className="flex max-h-[85vh] w-full max-w-lg flex-col rounded-b-none sm:rounded-2xl"
                onClick={(e) => e.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-labelledby="manage-levels-title"
            >
                <div className="px-5 pt-5">
                    <h2 id="manage-levels-title" className="text-lg font-semibold tracking-[-0.01em] text-slate-900">Access levels</h2>
                    <p className="mt-1 text-xs text-slate-500">Kept in this app only. Nothing here is written to a door panel yet.</p>
                </div>

                <ul className="mt-3 min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto px-5">
                    {levels.length === 0 && <li className="py-4 text-sm text-slate-500">No access levels yet. Add the first one below.</li>}
                    {levels.map((l) => (
                        <li key={l.id} className="py-2.5">
                            {editing?.id === l.id ? (
                                <div className="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-start">
                                    <TextField
                                        id={`level-name-${l.id}`}
                                        aria-label="Name"
                                        value={editing.name}
                                        onChange={(e) => setEditing({ ...editing, name: e.target.value })}
                                        error={errors.edit?.[0]}
                                        autoFocus
                                    />
                                    <TextField
                                        id={`level-zone-${l.id}`}
                                        aria-label="Time zone"
                                        placeholder="Time zone"
                                        value={editing.time_zone ?? ''}
                                        onChange={(e) => setEditing({ ...editing, time_zone: e.target.value })}
                                    />
                                    <div className="flex gap-1.5">
                                        <Button type="button" className="min-h-[40px] px-3 text-xs" onClick={saveEdit} loading={busy}>Save</Button>
                                        <Button type="button" variant="secondary" className="min-h-[40px] px-3 text-xs" onClick={() => { setEditing(null); setErrors({}); }} disabled={busy}>Cancel</Button>
                                    </div>
                                </div>
                            ) : (
                                <div className="flex items-center justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="truncate text-sm font-medium text-slate-800">{l.name}</div>
                                        <div className="text-xs text-slate-500">{l.time_zone || 'No time zone'}</div>
                                    </div>
                                    <div className="flex shrink-0 gap-1">
                                        <button type="button" onClick={() => { setEditing({ ...l }); setErrors({}); }} disabled={busy} className={`rounded-lg px-2 py-1 text-xs font-medium text-violet-700 hover:bg-violet-50 ${FOCUS}`}>Rename</button>
                                        <button type="button" onClick={() => remove(l)} disabled={busy} className={`rounded-lg px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50 ${FOCUS}`}>Delete</button>
                                    </div>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>

                <form onSubmit={add} className="border-t border-slate-100 px-5 py-4">
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                        <TextField id="new-level-name" label="New level" placeholder="Main door" value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} error={errors.name?.[0]} required />
                        <TextField id="new-level-zone" label="Time zone" placeholder="24-Hour Accessible" value={draft.time_zone} onChange={(e) => setDraft({ ...draft, time_zone: e.target.value })} error={errors.time_zone?.[0]} />
                        <Button type="submit" className="min-h-[42px]" loading={busy}>Add</Button>
                    </div>
                    <div className="mt-3 flex justify-end">
                        <Button type="button" variant="secondary" onClick={onClose} disabled={busy}>Close</Button>
                    </div>
                </form>
            </Card>
        </div>
    );
}
