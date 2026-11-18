import { useState } from 'react';
import { Badge, DateField, SelectField, TextField } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import { formatDateTime } from '../../shared/dates';

/*
| Pieces shared by the Audit log's desktop and mobile layouts (owner, 2026-11-09). The data
| is audit_logs as AuditLogController returns it; nothing here writes.
*/

/** "employee.fingerprints_pushed" → "Employee · fingerprints pushed". */
export function actionLabel(action) {
    const [subject, ...rest] = String(action ?? '').split('.');
    const what = rest.join(' ').replace(/_/g, ' ');
    return what ? `${subject.charAt(0).toUpperCase()}${subject.slice(1)} · ${what}` : action;
}

export function actionTone(action) {
    if (/deleted|wiped|removed|undone/.test(action)) return 'rose';
    if (/created|applied/.test(action)) return 'emerald';
    if (/password|level|access|role/.test(action)) return 'violet';
    return 'slate';
}

export function subjectLabel(log) {
    if (!log.auditable_type) return '—';
    return log.subject_name ? `${log.auditable_type}: ${log.subject_name}` : `${log.auditable_type} #${log.auditable_id}`;
}

export const when = (iso) => (iso ? formatDateTime(iso) : '—');

/** The filter bar, identical on both layouts. */
export function AuditFilters({ filters, onFilter, users, actions, stacked = false }) {
    const set = (k, v) => onFilter({ ...filters, [k]: v });
    const prefixes = [...new Set(actions.map((a) => `${a.split('.')[0]}.`))];
    return (
        <div className={`grid gap-3 ${stacked ? 'grid-cols-1' : 'grid-cols-2 lg:grid-cols-6'}`}>
            <SelectField label="Who" value={filters.who} onChange={(e) => set('who', e.target.value)}>
                <option value="people">People</option>
                <option value="all">People and automatic</option>
                <option value="system">Automatic only</option>
                {users.map((u) => <option key={u.id} value={String(u.id)}>{u.name}</option>)}
            </SelectField>
            <SelectField label="Action" value={filters.action} onChange={(e) => set('action', e.target.value)}>
                <option value="">Any action</option>
                {prefixes.map((p) => <option key={p} value={p}>{`All ${p.slice(0, -1)} actions`}</option>)}
                {actions.map((a) => <option key={a} value={a}>{actionLabel(a)}</option>)}
            </SelectField>
            <DateField label="From" value={filters.from} onChange={(e) => set('from', e.target.value)} />
            <DateField label="To" value={filters.to} onChange={(e) => set('to', e.target.value)} />
            <div className={stacked ? '' : 'lg:col-span-2'}>
                <TextField label="Search" placeholder="A name, a number, an IP…" value={filters.search} onChange={(e) => set('search', e.target.value)} />
            </div>
        </div>
    );
}

function show(value) {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'yes' : 'no';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

/** Column names as people say them; anything not here is shown with its underscores as spaces. */
const FIELD_LABELS = {
    employee_code: 'Personnel ID', external_id: 'External ID', name: 'First name', last_name: 'Last name',
    company_id: 'Company', department_id: 'Department', device_id: 'Terminal', employee_id: 'Employee',
    manager_id: 'Manager', user_id: 'User', card_number: 'Card number', is_active: 'Active',
    employment_status: 'Employment status', resigned_at: 'Resigned on', joined_at: 'Joined on',
    fingerprint_count: 'Fingerprints', fingerprint_slots: 'Fingers (slots)', enrollment_synced_at: 'Read from terminals at',
    photo_path: 'Photo', phone: 'Phone', email: 'E-mail', gender: 'Gender', birthday: 'Birthday',
    device_user_id: 'Terminal user ID', ip_address: 'IP address', last_seen_at: 'Last seen',
};
const fieldLabel = (f) => FIELD_LABELS[f] ?? f.replace(/_/g, ' ');

function Value({ value, name }) {
    if (name) return <>{name} <span className="text-slate-400">#{show(value)}</span></>;
    return show(value);
}

/**
 * What changed, field by field (owner, 2026-11-09: "show what the record was before updating").
 * `diff` comes from AuditLogController: before, after, and how the before is known —
 * recorded with the change, inferred from the record's earlier entries ("from history"),
 * unknown, or none for a new record. The two kinds are kept visibly apart (rule 3).
 */
export function ChangesView({ log }) {
    const diff = log?.diff ?? [];
    if (diff.length === 0) {
        return <p className="text-sm text-slate-500">{/deleted$/.test(log?.action ?? '') ? 'Deleted before records were kept in full (2026-11-09): what it held was not recorded.' : 'No details were recorded.'}</p>;
    }
    const plain = diff.every((r) => r.before_known === 'none');
    const isNew = diff.every((r) => r.before_known === 'new');
    const deleted = /deleted$/.test(log?.action ?? '');
    const inferred = diff.some((r) => r.before_known === 'from_history');

    if (plain || isNew) {
        return (
            <dl className="divide-y divide-slate-100 text-sm">
                {diff.map((r) => (
                    <div key={r.field} className="grid grid-cols-1 gap-1 py-1.5 sm:grid-cols-[12rem_minmax(0,1fr)]">
                        <dt className="font-medium text-slate-600">{fieldLabel(r.field)}</dt>
                        <dd className="min-w-0 break-words font-mono text-xs text-slate-800"><Value value={r.after} name={r.after_name} /></dd>
                    </div>
                ))}
            </dl>
        );
    }

    return (
        <div className="space-y-2">
            <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                    <thead className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="py-1.5 pr-4">Field</th>
                            <th className="py-1.5 pr-4">{deleted ? 'It held' : 'Before'}</th>
                            {!deleted && <th className="py-1.5">After</th>}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 align-top">
                        {diff.map((r) => (
                            <tr key={r.field}>
                                <td className="py-1.5 pr-4 font-medium text-slate-600">{fieldLabel(r.field)}</td>
                                <td className="max-w-xs break-words py-1.5 pr-4 font-mono text-xs">
                                    {r.before_known === 'unknown'
                                        ? <span className="font-sans italic text-slate-500">not recorded</span>
                                        : <span className={deleted ? 'text-slate-800' : 'text-rose-700'}><Value value={r.before} name={r.before_name} /></span>}
                                    {r.before_known === 'from_history' && <span className="ml-1.5 font-sans text-[11px] text-amber-700">from history</span>}
                                </td>
                                {!deleted && <td className="max-w-xs break-words py-1.5 font-mono text-xs text-emerald-800"><Value value={r.after} name={r.after_name} /></td>}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {inferred && (
                <p className="text-xs text-slate-500">
                    “From history”: this change was saved before the audit log kept old values (2026-11-09), so the value before is taken from this record’s previous entry. It is right unless something changed it outside the system.
                </p>
            )}
        </div>
    );
}

/** A row that opens to show what changed. Enter/Space toggle it (rule 7). */
export function useOpenRows() {
    const [open, setOpen] = useState(() => new Set());
    const toggle = (id) => setOpen((s) => {
        const next = new Set(s);
        next.has(id) ? next.delete(id) : next.add(id);
        return next;
    });
    const keyToggle = (id) => (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            toggle(id);
        }
    };
    return { open, toggle, keyToggle };
}

export function ActionBadge({ action }) {
    return <Badge tone={actionTone(action)} className="!normal-case">{actionLabel(action)}</Badge>;
}

export const ROW_FOCUS = FOCUS;
