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
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

/**
 * What changed. The observer writes { field: [before, after] } or { before, after }; explicit
 * rows write any shape. Each field on its own line, before → after when both are known.
 */
export function ChangesView({ changes }) {
    if (!changes || (typeof changes === 'object' && Object.keys(changes).length === 0)) {
        return <p className="text-sm text-slate-500">No details were recorded.</p>;
    }
    let rows = [];
    if (changes.before || changes.after) {
        const keys = [...new Set([...Object.keys(changes.before ?? {}), ...Object.keys(changes.after ?? {})])];
        rows = keys.map((k) => [k, changes.before?.[k], changes.after?.[k], true]);
    } else {
        rows = Object.entries(changes).map(([k, v]) => (Array.isArray(v) && v.length === 2 ? [k, v[0], v[1], true] : [k, null, v, false]));
    }
    return (
        <dl className="divide-y divide-slate-100 text-sm">
            {rows.map(([k, before, after, pair]) => (
                <div key={k} className="grid grid-cols-1 gap-1 py-1.5 sm:grid-cols-[12rem_minmax(0,1fr)]">
                    <dt className="font-medium text-slate-600">{k.replace(/_/g, ' ')}</dt>
                    <dd className="min-w-0 break-words font-mono text-xs text-slate-800">
                        {pair ? <><span className="text-rose-700 line-through decoration-rose-300">{show(before)}</span>{' → '}<span className="text-emerald-800">{show(after)}</span></> : show(after)}
                    </dd>
                </div>
            ))}
        </dl>
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
