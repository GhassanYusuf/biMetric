import { Badge, DateField, SelectField, TextField } from '../../shared/ui';
import { formatDateTime } from '../../shared/dates';

/*
| Pieces shared by the Error logs' desktop and mobile layouts (owner, 2026-11-09). Entries come
| from the log files through ErrorLogController; nothing here writes. Card and personal data
| never reach these logs by design (ClientErrorController caps fields; device logs carry
| addresses and command names, not templates).
*/
export const SOURCES = {
    server: { label: 'Server', hint: 'The system and its background jobs' },
    device: { label: 'Terminals', hint: 'Talking to the terminals' },
    browser: { label: 'Browsers', hint: 'Screens that crashed in someone’s browser' },
};

export const when = (iso) => (iso ? formatDateTime(iso) : '—');

export function LevelBadge({ level }) {
    const tone = ['emergency', 'alert', 'critical', 'error'].includes(level) ? 'rose' : level === 'warning' ? 'amber' : 'slate';
    return <Badge tone={tone}>{level}</Badge>;
}

export function SourceBadge({ source }) {
    return <Badge tone={source === 'device' ? 'blue' : source === 'browser' ? 'violet' : 'slate'} className="!normal-case">{SOURCES[source]?.label ?? source}</Badge>;
}

/** The terminal or page an entry is about, when its details say so. */
export function about(entry) {
    const c = entry?.context ?? {};
    if (c.device_name) return `${c.device_name}${c.device_ip || c.endpoint ? ` (${c.device_ip ?? c.endpoint})` : ''}`;
    if (c.url) return String(c.url).replace(/^https?:\/\/[^/]+/, '');
    return null;
}

export function ErrorFilters({ filters, onFilter, stacked = false }) {
    const set = (k, v) => onFilter({ ...filters, [k]: v });
    return (
        <div className={`grid gap-3 ${stacked ? 'grid-cols-1' : 'grid-cols-2 lg:grid-cols-6'}`}>
            <SelectField label="Where" value={filters.source} onChange={(e) => set('source', e.target.value)}>
                <option value="">Everywhere</option>
                {Object.entries(SOURCES).map(([k, s]) => <option key={k} value={k}>{s.label}</option>)}
            </SelectField>
            <SelectField label="How bad" value={filters.level} onChange={(e) => set('level', e.target.value)}>
                <option value="error">Errors</option>
                <option value="warning">Errors and warnings</option>
                <option value="info">Everything</option>
            </SelectField>
            <DateField label="From" value={filters.from} onChange={(e) => set('from', e.target.value)} />
            <DateField label="To" value={filters.to} onChange={(e) => set('to', e.target.value)} />
            <TextField label="Search" placeholder="A terminal, a message…" value={filters.search} onChange={(e) => set('search', e.target.value)} />
            <SelectField label="Repeats" value={filters.group ? '1' : '0'} onChange={(e) => set('group', e.target.value === '1')}>
                <option value="1">Grouped</option>
                <option value="0">Every entry</option>
            </SelectField>
        </div>
    );
}

/** The full entry: what the log holds beyond the message. */
export function EntryDetail({ entry }) {
    const context = entry?.context;
    return (
        <div className="space-y-2">
            {context && (
                <pre className="max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-900 p-3 font-mono text-xs text-slate-100">{JSON.stringify(context, null, 2)}</pre>
            )}
            {entry?.detail && (
                <pre className="max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-100 p-3 font-mono text-xs text-slate-800">{entry.detail}</pre>
            )}
            {!context && !entry?.detail && <p className="text-sm text-slate-500">No more detail was logged.</p>}
        </div>
    );
}

export function Summary({ counts, entries }) {
    if (!counts) return null;
    return (
        <p className="text-sm text-slate-600">
            {`${entries.toLocaleString()} entr${entries === 1 ? 'y' : 'ies'}: `}
            <span className="font-medium text-rose-700">{counts.error.toLocaleString()} errors</span>
            {`, ${counts.warning.toLocaleString()} warnings · server ${counts.server.toLocaleString()}, terminals ${counts.device.toLocaleString()}, browsers ${counts.browser.toLocaleString()}`}
        </p>
    );
}
