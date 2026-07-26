import { Badge } from '../../shared/ui';

/*
| The shared vocabulary for every device screen.
|
| One status word must look the same on the detail page, the sync history, the
| command queue and the reconciliation report — an operator comparing two screens
| should never have to work out whether "error" and "failed" mean the same thing.
| So every badge, label and formatter lives here rather than being re-invented per
| page.
|
| The colour rules that matter:
|   - amber is "waiting or unproven", never "broken"
|   - rose is reserved for something that actually failed
|   - slate means "no information yet", which is NOT a failure and must not read
|     as one (a device that has never synced has not gone wrong)
*/

/** Health from /devices — derived from last_seen_at, so it can go stale honestly. */
export function HealthBadge({ status, online }) {
    if (status === 'online' && online) return <Badge tone="emerald">Online</Badge>;
    // Recorded online but past the freshness window: the device stopped answering
    // without ever getting the chance to say so.
    if (status === 'online' && !online) return <Badge tone="amber">Stale</Badge>;
    if (status === 'degraded') return <Badge tone="amber">Degraded</Badge>;
    if (status === 'offline') return <Badge tone="rose">Offline</Badge>;
    return <Badge tone="slate">Unknown</Badge>;
}

/* The five DeviceCommand statuses, exactly as the model defines them. */
const COMMAND_TONES = {
    queued: 'slate',
    running: 'blue',
    succeeded: 'emerald',
    failed: 'rose',
    cancelled: 'slate',
};

export function CommandStatusBadge({ status }) {
    return <Badge tone={COMMAND_TONES[status] ?? 'slate'}>{(status || 'unknown').replace(/_/g, ' ')}</Badge>;
}

/*
| AttendanceSyncRun statuses. `partial` is amber, not rose: it means some records
| imported and some could not be decoded — data arrived, and the run is worth looking
| at rather than repeating.
*/
export const RUN_STATUSES = ['running', 'succeeded', 'partial', 'failed'];

const RUN_TONES = {
    running: 'blue',
    succeeded: 'emerald',
    partial: 'amber',
    failed: 'rose',
};

export function RunStatusBadge({ status }) {
    return <Badge tone={RUN_TONES[status] ?? 'slate'}>{(status || 'unknown').replace(/_/g, ' ')}</Badge>;
}

/*
| EnrollmentSession statuses. `pending` and `success` — not "queued" and "enrolled".
|
| `timeout` is amber because it means nobody presented a finger or card in time. That
| is a person not turning up, not a device fault, and colouring it rose would send
| operators chasing hardware that is working perfectly.
*/
export const SESSION_STATUSES = ['pending', 'sent', 'waiting', 'success', 'timeout', 'failed', 'cancelled'];

const SESSION_TONES = {
    pending: 'slate',
    sent: 'blue',
    waiting: 'amber',
    success: 'emerald',
    timeout: 'amber',
    failed: 'rose',
    cancelled: 'slate',
};

/** `success` reads as "Enrolled" — the state means the capture landed on the device. */
const SESSION_LABELS = { success: 'enrolled', timeout: 'timed out', pending: 'queued' };

export function SessionStatusBadge({ status }) {
    return (
        <Badge tone={SESSION_TONES[status] ?? 'slate'}>
            {SESSION_LABELS[status] ?? (status || 'unknown').replace(/_/g, ' ')}
        </Badge>
    );
}

/*
| Reconciliation states, worded as what an operator must DO rather than as a
| database value. "missing_on_device" tells you nothing; "not on device" tells you
| the person cannot clock in.
*/
export const RECON_LABELS = {
    in_sync: 'In sync',
    missing_on_device: 'Not on device',
    missing_in_system: 'Unknown to system',
    mismatched: 'Details differ',
};

const RECON_TONES = {
    in_sync: 'emerald',
    missing_on_device: 'rose',
    missing_in_system: 'amber',
    mismatched: 'amber',
};

export function ReconStatusBadge({ status }) {
    return <Badge tone={RECON_TONES[status] ?? 'slate'}>{RECON_LABELS[status] ?? status ?? 'unknown'}</Badge>;
}

/*
| Capability levels, shown as four states rather than a checkbox.
|
| The distinction is the point (CLAUDE.md rules 9 and 10): "implemented" means the
| code exists but no hardware has ever confirmed it works, and an operator deserves
| to know that before they trust it with payroll. Collapsing it to a tick would be
| claiming more than the device ever told us.
*/
export const CAPABILITY_LEVELS = {
    confirmed: { tone: 'emerald', label: 'Confirmed', hint: 'Verified working on this hardware.' },
    implemented: { tone: 'amber', label: 'Unproven', hint: 'Code exists; never confirmed on a real device.' },
    not_implemented: { tone: 'slate', label: 'Not built', hint: 'This library does not do it.' },
    unknown: { tone: 'slate', label: 'Unknown', hint: 'Never probed — we claim nothing either way.' },
};

export function CapabilityBadge({ level }) {
    const meta = CAPABILITY_LEVELS[level] ?? CAPABILITY_LEVELS.unknown;
    return <Badge tone={meta.tone}>{meta.label}</Badge>;
}

/** Operator-facing names for queued commands. */
export const COMMAND_LABELS = {
    sync_attendance: 'Sync punches',
    sync_enrollment: 'Sync enrollment',
    import_employees: 'Import staff',
    reconcile_users: 'Reconcile users',
    health_check: 'Health check',
    read_info: 'Read device info',
    probe_capabilities: 'Probe capabilities',
    sync_time: 'Set device clock',
    push_user: 'Push employee',
    remove_user: 'Remove employee',
    enroll_fingerprint: 'Capture fingerprint',
    enroll_card: 'Capture card',
    delete_fingerprint: 'Delete fingerprint',
    clear_card: 'Clear card',
};

export function commandLabel(command) {
    return COMMAND_LABELS[command] ?? (command || '').replace(/_/g, ' ');
}

/** Group-safe subset: commands that make sense fired at many devices at once. */
export const GROUP_COMMANDS = [
    'sync_attendance',
    'sync_enrollment',
    'health_check',
    'read_info',
    'reconcile_users',
    'probe_capabilities',
    'sync_time',
];

export function formatDuration(ms) {
    if (ms == null) return '—';
    if (ms < 1000) return `${ms}ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
    const mins = Math.floor(ms / 60000);
    return `${mins}m ${Math.round((ms % 60000) / 1000)}s`;
}

/** Full local timestamp. Device timing questions need the seconds. */
export function absoluteTime(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
    });
}

export function shortTime(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit',
    });
}

/** A labelled read-only fact. The building block of the detail page. */
export function Fact({ label, children, mono = false, tone = 'default' }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
            <dd className={`mt-0.5 truncate text-sm ${mono ? 'font-mono text-[13px]' : ''} ${
                tone === 'muted' ? 'text-slate-500' : 'font-medium text-slate-900'
            }`}>
                {children ?? <span className="font-normal text-slate-500">Not read yet</span>}
            </dd>
        </div>
    );
}

/*
| The counts of one sync run, shown as five separate numbers.
|
| "Imported 3" hides whether the device offered 3 records or 9,000 of which 8,997
| were already known — and that difference is the entire story when a sync looks
| wrong. `undecodable` is the one to watch: non-zero means the device offered
| punches this decoder could not read. They are archived for reparsing, not lost.
*/
export function CountChips({ counts }) {
    if (!counts) return null;
    const items = [
        { key: 'read', label: 'read', tone: 'text-slate-700' },
        { key: 'imported', label: 'new', tone: counts.imported > 0 ? 'text-emerald-700' : 'text-slate-500' },
        { key: 'duplicate', label: 'already known', tone: 'text-slate-500' },
        { key: 'unmatched', label: 'unattributed', tone: counts.unmatched > 0 ? 'text-amber-700' : 'text-slate-500' },
        { key: 'undecodable', label: 'undecodable', tone: counts.undecodable > 0 ? 'text-rose-700' : 'text-slate-500' },
    ];

    return (
        <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-xs">
            {items.map((i) => (
                <span key={i.key} className={i.tone}>
                    <span className="font-semibold tabular-nums">{(counts[i.key] ?? 0).toLocaleString()}</span>{' '}
                    {i.label}
                </span>
            ))}
        </div>
    );
}
