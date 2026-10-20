import { useMemo, useState } from 'react';
import { ApiError } from '../../shared/api';
import { Badge, Button, Card, SelectField, TextField } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import { useEscapeKey } from '../../shared/useEscapeKey';
import EmployeePicker from '../../shared/EmployeePicker';

/*
| Pieces shared by the Users screen's desktop and mobile layouts, and its three dialogs
| (2026-10-19). The server enforces every rule shown here — own level, last
| Administrator, no granting above oneself (UserController, RoleController); the
| dialogs only avoid offering what it would refuse.
*/

export const TABS = [
    { key: 'users', label: 'Users' },
    { key: 'levels', label: 'Permission levels' },
];

export function Tabs({ tab, onTab, counts }) {
    function onKeyDown(e) {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        const i = TABS.findIndex((t) => t.key === tab);
        const next = TABS[(i + (e.key === 'ArrowRight' ? 1 : TABS.length - 1)) % TABS.length];
        onTab(next.key);
        document.getElementById(`users-tab-${next.key}`)?.focus();
    }

    return (
        <div role="tablist" aria-label="Users sections" className="flex gap-1 border-b border-slate-200" onKeyDown={onKeyDown}>
            {TABS.map((t) => {
                const active = t.key === tab;
                return (
                    <button
                        key={t.key}
                        id={`users-tab-${t.key}`}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onTab(t.key)}
                        className={`-mb-px min-h-[44px] whitespace-nowrap border-b-2 px-4 text-sm transition-colors ${active ? 'border-violet-600 font-semibold text-violet-800' : 'border-transparent font-medium text-slate-600 hover:text-slate-900'} ${FOCUS}`}
                    >
                        {t.label}
                        {counts?.[t.key] != null && <span className="ml-1.5 text-xs text-slate-500">{counts[t.key]}</span>}
                    </button>
                );
            })}
        </div>
    );
}

export function StatusBadge({ user }) {
    return user.is_active ? <Badge tone="emerald">Active</Badge> : <Badge tone="slate">Deactivated</Badge>;
}

export function lastActive(iso) {
    if (!iso) return 'Never signed in';
    const d = new Date(iso);
    const mins = Math.round((Date.now() - d.getTime()) / 60000);
    if (mins < 2) return 'Just now';
    if (mins < 60) return `${mins} min ago`;
    if (mins < 24 * 60) return `${Math.round(mins / 60)} h ago`;
    return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

/** The menu items for one user row — identical on both layouts (rule 5). */
export function userActions(user, { can, onEdit, onPassword, onToggleActive, onDelete }) {
    return [
        can('users.update') && { key: 'edit', label: 'Edit', onSelect: () => onEdit(user) },
        can('users.update') && { key: 'password', label: 'Set new password', onSelect: () => onPassword(user) },
        can('users.update') && !user.is_self && { key: 'active', label: user.is_active ? 'Deactivate' : 'Reactivate', onSelect: () => onToggleActive(user) },
        can('users.delete') && !user.is_self && { key: 'delete', label: 'Delete', tone: 'danger', onSelect: () => onDelete(user) },
    ].filter(Boolean);
}

/** Can the signed-in user hand out this level? Only if they hold all of it. */
export function canGrant(level, myPermissions) {
    const mine = new Set(myPermissions ?? []);
    return level.permissions.every((p) => mine.has(p));
}

export function permissionCount(level, groups) {
    const total = groups.reduce((n, g) => n + g.permissions.length, 0);
    return `${level.permissions.length} of ${total} permissions`;
}

/** A level as a card: name, who has it, and what it can do in plain words. */
export function LevelCard({ level, groups, canManage, onEdit, onDelete }) {
    const held = new Set(level.permissions);
    const summary = groups
        .map((g) => ({ group: g.group, n: g.permissions.filter((p) => held.has(p.name)).length, of: g.permissions.length }))
        .filter((g) => g.n > 0);

    return (
        <Card className="flex flex-col p-5">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="truncate text-base font-semibold text-slate-900">{level.label}</h3>
                    <p className="mt-0.5 text-sm text-slate-500">
                        {level.users_count === 1 ? '1 user' : `${level.users_count} users`} · {permissionCount(level, groups)}
                    </p>
                </div>
                {level.locked ? <Badge tone="violet">Everything</Badge> : level.built_in ? <Badge>Built in</Badge> : <Badge tone="blue">Custom</Badge>}
            </div>
            <ul className="mt-3 flex flex-wrap gap-1.5">
                {summary.length === 0 && <li className="text-sm text-slate-500">No permissions — can sign in and see nothing.</li>}
                {summary.map((g) => (
                    <li key={g.group} className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                        {g.group}{g.n < g.of ? ` (${g.n}/${g.of})` : ''}
                    </li>
                ))}
            </ul>
            <div className="mt-4 flex gap-2 pt-1">
                <Button variant="secondary" onClick={() => onEdit(level)}>{level.locked || !canManage ? 'View' : 'Edit'}</Button>
                {canManage && !level.built_in && (
                    <Button variant="secondary" className="text-rose-700" onClick={() => onDelete(level)}>Delete</Button>
                )}
            </div>
        </Card>
    );
}

function Dialog({ title, submitting, onClose, children, wide = false, labelledBy = 'users-dialog-title' }) {
    useEscapeKey(onClose, !submitting);
    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 p-0 sm:items-center sm:p-4" onClick={submitting ? undefined : onClose} role="presentation">
            <Card className={`w-full ${wide ? 'max-w-2xl' : 'max-w-md'} rounded-b-none sm:rounded-2xl`} onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-labelledby={labelledBy}>
                <h2 id={labelledBy} className="sr-only">{title}</h2>
                {children}
            </Card>
        </div>
    );
}

function randomPassword() {
    // No look-alikes (0/O, 1/l/I), so it can be read out over the phone.
    const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const bytes = crypto.getRandomValues(new Uint32Array(12));
    return Array.from(bytes, (b) => chars[b % chars.length]).join('');
}

function PasswordField({ value, onChange, error, label = 'Password' }) {
    const [shown, setShown] = useState(false);
    return (
        <div>
            <TextField
                label={label}
                type={shown ? 'text' : 'password'}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                error={error}
                hint="At least 8 characters. You tell the person; nothing is e-mailed."
                autoComplete="new-password"
                required
            />
            <div className="mt-1.5 flex gap-3 text-xs">
                <button type="button" onClick={() => { onChange(randomPassword()); setShown(true); }} className={`font-medium text-violet-700 hover:underline ${FOCUS}`}>Generate one</button>
                <button type="button" onClick={() => setShown((s) => !s)} className={`font-medium text-slate-600 hover:underline ${FOCUS}`}>{shown ? 'Hide' : 'Show'}</button>
            </div>
        </div>
    );
}

/** Add a user, or edit one. */
export function UserFormModal({ user, levels, myPermissions, onSubmit, onClose }) {
    const isEdit = !!user;
    const [name, setName] = useState(user?.name ?? '');
    const [email, setEmail] = useState(user?.email ?? '');
    const [password, setPassword] = useState('');
    const [role, setRole] = useState(user?.role ?? '');
    const [employee, setEmployee] = useState(user?.employee ? [user.employee] : []);
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const ownLevel = user?.is_self;

    async function submit(e) {
        e.preventDefault();
        const found = {};
        if (!name.trim()) found.name = ['Enter their name.'];
        if (!email.trim()) found.email = ['Enter the e-mail they will sign in with.'];
        if (!isEdit && password.length < 8) found.password = ['At least 8 characters.'];
        if (!role) found.role = ['Choose a permission level.'];
        if (Object.keys(found).length) {
            setErrors(found);
            return;
        }
        setSubmitting(true);
        setErrors({});
        const payload = { name: name.trim(), email: email.trim(), employee_id: employee[0]?.id ?? null };
        if (!ownLevel) payload.role = role;
        if (!isEdit) payload.password = password;
        try {
            await onSubmit(payload);
        } catch (err) {
            if (err instanceof ApiError && err.errors) setErrors(err.errors);
        } finally {
            setSubmitting(false);
        }
    }

    const chosenLevel = levels.find((l) => l.name === role);

    return (
        <Dialog title={isEdit ? `Edit ${user.name}` : 'Add user'} submitting={submitting} onClose={onClose}>
            <form onSubmit={submit} className="max-h-[90vh] overflow-y-auto p-5" noValidate>
                <p className="mb-4 text-lg font-semibold text-slate-900" aria-hidden="true">{isEdit ? `Edit ${user.name}` : 'Add user'}</p>
                <div className="space-y-3">
                    <TextField label="Name" value={name} onChange={(e) => setName(e.target.value)} error={errors.name?.[0]} maxLength={255} autoFocus required />
                    <TextField label="E-mail" type="email" value={email} onChange={(e) => setEmail(e.target.value)} error={errors.email?.[0]} hint="What they sign in with." autoComplete="off" required />
                    {!isEdit && <PasswordField value={password} onChange={setPassword} error={errors.password?.[0]} label="Starting password" />}
                    <SelectField
                        label="Permission level"
                        value={role}
                        onChange={(e) => setRole(e.target.value)}
                        error={errors.role?.[0] ?? (ownLevel ? 'You cannot change your own level.' : undefined)}
                        disabled={ownLevel}
                    >
                        <option value="">Choose…</option>
                        {levels.map((l) => (
                            <option key={l.id} value={l.name} disabled={!canGrant(l, myPermissions) && l.name !== user?.role}>
                                {l.label}{!canGrant(l, myPermissions) ? ' — beyond your own rights' : ''}
                            </option>
                        ))}
                    </SelectField>
                    {chosenLevel && <p className="-mt-1 text-xs text-slate-500">{chosenLevel.locked ? 'Can do everything, including managing users.' : `${chosenLevel.permissions.length} permissions — see the Permission levels tab.`}</p>}
                    <EmployeePicker
                        label="Linked employee (optional)"
                        value={employee}
                        onChange={(next) => setEmployee(next.slice(-1))}
                        error={errors.employee_id?.[0]}
                    />
                    <p className="-mt-1 text-xs text-slate-500">Needed for the Employee level: it is whose attendance they see.</p>
                </div>
                <div className="mt-5 flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>Cancel</Button>
                    <Button type="submit" loading={submitting}>{isEdit ? 'Save' : 'Add user'}</Button>
                </div>
            </form>
        </Dialog>
    );
}

/** Set a new password for someone (or oneself). */
export function PasswordModal({ user, onSubmit, onClose }) {
    const [password, setPassword] = useState('');
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    async function submit(e) {
        e.preventDefault();
        if (password.length < 8) {
            setErrors({ password: ['At least 8 characters.'] });
            return;
        }
        setSubmitting(true);
        setErrors({});
        try {
            await onSubmit(password);
        } catch (err) {
            if (err instanceof ApiError && err.errors) setErrors(err.errors);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <Dialog title={`New password for ${user.name}`} submitting={submitting} onClose={onClose}>
            <form onSubmit={submit} className="p-5" noValidate>
                <p className="text-lg font-semibold text-slate-900" aria-hidden="true">New password for {user.name}</p>
                <p className="mb-4 mt-1 text-sm text-slate-600">
                    {user.is_self ? 'Your other sessions will be signed out.' : `${user.name} is signed out everywhere and signs in again with this password.`}
                </p>
                <PasswordField value={password} onChange={setPassword} error={errors.password?.[0]} label="New password" />
                <div className="mt-5 flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>Cancel</Button>
                    <Button type="submit" loading={submitting}>Set password</Button>
                </div>
            </form>
        </Dialog>
    );
}

/**
| Create or edit a permission level: a name and a tick-grid of permissions by group.
| The Administrator is shown read-only. A permission the signed-in user does not hold
| cannot be ticked ON (the server refuses it too), but can be ticked off.
*/
export function LevelModal({ level, groups, canManage, myPermissions, onSubmit, onClose }) {
    const isEdit = !!level;
    const readOnly = !canManage || level?.locked;
    const [name, setName] = useState(level?.label ?? '');
    const [picked, setPicked] = useState(() => new Set(level?.permissions ?? []));
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const mine = useMemo(() => new Set(myPermissions ?? []), [myPermissions]);

    const original = useMemo(() => new Set(level?.permissions ?? []), [level]);
    const grantable = (p) => mine.has(p) || original.has(p);

    function toggle(p) {
        if (readOnly) return;
        setPicked((prev) => {
            const next = new Set(prev);
            if (next.has(p)) next.delete(p);
            else if (grantable(p)) next.add(p);
            return next;
        });
    }

    function toggleGroup(g) {
        if (readOnly) return;
        const names = g.permissions.map((p) => p.name).filter((p) => picked.has(p) || grantable(p));
        const allOn = names.every((p) => picked.has(p));
        setPicked((prev) => {
            const next = new Set(prev);
            names.forEach((p) => (allOn ? next.delete(p) : next.add(p)));
            return next;
        });
    }

    async function submit(e) {
        e.preventDefault();
        if (readOnly) return onClose();
        if (!level?.built_in && !name.trim()) {
            setErrors({ name: ['Give the level a name.'] });
            return undefined;
        }
        setSubmitting(true);
        setErrors({});
        const payload = { permissions: [...picked] };
        if (!level?.built_in) payload.name = name.trim();
        try {
            await onSubmit(payload);
        } catch (err) {
            if (err instanceof ApiError && err.errors) setErrors(err.errors);
        } finally {
            setSubmitting(false);
        }
        return undefined;
    }

    const title = isEdit ? level.label : 'New permission level';

    return (
        <Dialog title={title} submitting={submitting} onClose={onClose} wide>
            <form onSubmit={submit} className="flex max-h-[90vh] flex-col" noValidate>
                <div className="border-b border-slate-100 p-5">
                    <p className="text-lg font-semibold text-slate-900" aria-hidden="true">{title}</p>
                    {level?.locked && <p className="mt-1 text-sm text-slate-600">The Administrator always has every permission, so a company can never lock itself out of this screen.</p>}
                    {level?.built_in && !level.locked && <p className="mt-1 text-sm text-slate-600">A built-in level keeps its name; what it can do is yours to change.</p>}
                    {!level?.built_in && (
                        <TextField className="mt-3" label="Name" value={name} onChange={(e) => setName(e.target.value)} error={errors.name?.[0]} placeholder="e.g. Payroll viewer" maxLength={60} autoFocus={!isEdit} disabled={readOnly} required />
                    )}
                    {errors.permissions?.[0] && <p className="mt-2 text-sm text-rose-700">{errors.permissions[0]}</p>}
                </div>
                <div className="grid flex-1 gap-4 overflow-y-auto p-5 sm:grid-cols-2">
                    {groups.map((g) => {
                        const on = g.permissions.filter((p) => picked.has(p.name)).length;
                        return (
                            <fieldset key={g.group} className="rounded-xl p-3 ring-1 ring-slate-200">
                                <legend className="sr-only">{g.group}</legend>
                                <div className="mb-1 flex items-center justify-between gap-2">
                                    <span className="text-sm font-semibold text-slate-900">{g.group}</span>
                                    {!readOnly && (
                                        <button type="button" onClick={() => toggleGroup(g)} className={`rounded px-1 text-xs font-medium text-violet-700 hover:underline ${FOCUS}`}>
                                            {on === g.permissions.length ? 'None' : 'All'}
                                        </button>
                                    )}
                                </div>
                                {g.permissions.map((p) => {
                                    const checked = picked.has(p.name);
                                    const blocked = !checked && !grantable(p.name);
                                    return (
                                        <label key={p.name} className={`flex min-h-[36px] items-center gap-2.5 rounded-lg px-1 text-sm ${readOnly || blocked ? 'cursor-default' : 'cursor-pointer hover:bg-slate-50'}`} title={blocked ? 'You do not have this permission yourself.' : p.name}>
                                            <input
                                                type="checkbox"
                                                checked={checked}
                                                onChange={() => toggle(p.name)}
                                                disabled={readOnly || blocked}
                                                className={`h-4 w-4 rounded border-slate-400 text-violet-600 ${FOCUS}`}
                                            />
                                            <span className={blocked ? 'text-slate-500' : 'text-slate-800'}>{p.label}</span>
                                        </label>
                                    );
                                })}
                            </fieldset>
                        );
                    })}
                </div>
                <div className="flex items-center justify-between gap-2 border-t border-slate-100 p-4">
                    <span className="text-sm text-slate-600">{picked.size} selected</span>
                    <div className="flex gap-2">
                        <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>{readOnly ? 'Close' : 'Cancel'}</Button>
                        {!readOnly && <Button type="submit" loading={submitting}>{isEdit ? 'Save level' : 'Create level'}</Button>}
                    </div>
                </div>
            </form>
        </Dialog>
    );
}
