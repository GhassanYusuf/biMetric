import { useState } from 'react';
import { Badge, Button, Card, EmptyState, PageHeader, SectionCard } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import { PROTOCOL_LABELS, SyncBadge, relativeTime } from './parts';
import {
    CAPABILITY_LEVELS, CapabilityBadge, CommandStatusBadge, CountChips, DeviceError, Fact,
    HealthBadge, RunStatusBadge, commandLabel, formatDuration, shortTime,
} from './statusParts';
import { SyncInFlightCard } from './DeviceDetailDesktop';

/*
| Mobile device detail: tabs instead of columns.
|
| The desktop page shows history and state side by side. On a phone that becomes one
| very long scroll, so the same content is split into tabs and the operations sit
| pinned at the top — the reason somebody opens this page on a phone is almost always
| to stand next to the terminal and fire something at it.
*/

const TABS = [
    { key: 'runs', label: 'Syncs' },
    { key: 'queue', label: 'Queue' },
    { key: 'info', label: 'Device' },
    { key: 'caps', label: 'Can do' },
];

export default function DeviceDetailMobile({
    device, runs, commands, history, dispatching,
    syncRunning, syncProgress, syncStalled,
    canSync, onRefresh, onDispatch, onFullResync, onRetryCommand, onCancelCommand, onBack,
}) {
    const [tab, setTab] = useState('runs');
    const caps = device.capabilities ?? {};
    const busy = !!dispatching;

    return (
        <div className="space-y-4">
            <div>
                <button onClick={onBack} className="mb-2 text-xs font-semibold text-slate-500 hover:text-violet-700">
                    ← Devices
                </button>
                <PageHeader
                    compact
                    title={device.name}
                    subtitle={`${device.ip_address}:${device.port} · ${PROTOCOL_LABELS[device.protocol] || device.protocol}`}
                />
                <div className="mt-2 flex flex-wrap items-center gap-1.5">
                    <HealthBadge status={device.health_status} online={device.is_online} />
                    <SyncBadge status={device.last_sync_status} />
                    {!device.is_active && <Badge tone="amber">Disabled</Badge>}
                </div>
            </div>

            {/* Same banner and the same words as the desktop screen — rule 5. */}
            {syncRunning && <SyncInFlightCard progress={syncProgress} stalled={syncStalled} />}

            <div className="grid grid-cols-2 gap-3">
                <Card className="p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-slate-500">Punches</div>
                    <div className="mt-1 text-xl font-bold tabular-nums text-slate-900">
                        {(device.attendance_imported_total ?? 0).toLocaleString()}
                    </div>
                </Card>
                <Card className="p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-slate-500">Last seen</div>
                    <div className="mt-1 text-xl font-bold text-slate-900">{relativeTime(device.last_seen_at)}</div>
                </Card>
            </div>

            {canSync && (
                <SectionCard title="Operations" subtitle="Queued — safe to close this screen." bodyClass="p-4">
                    <div className="grid grid-cols-2 gap-2">
                        <Button variant="soft" loading={dispatching === 'sync_attendance'} disabled={busy} onClick={() => onDispatch('sync_attendance')}>
                            Sync punches
                        </Button>
                        <Button variant="secondary" loading={dispatching === 'health_check'} disabled={busy} onClick={() => onDispatch('health_check')}>
                            Health check
                        </Button>
                        <Button variant="secondary" loading={dispatching === 'read_info'} disabled={busy} onClick={() => onDispatch('read_info')}>
                            Read info
                        </Button>
                        <Button variant="secondary" loading={dispatching === 'reconcile_users'} disabled={busy} onClick={() => onDispatch('reconcile_users')}>
                            Reconcile
                        </Button>
                        <Button variant="secondary" className="col-span-2" disabled={busy} onClick={onFullResync}>
                            Full re-read of attendance
                        </Button>
                    </div>
                </SectionCard>
            )}

            {/* Tabs. Scrollable row so the labels never wrap or shrink below 44px. */}
            <div role="tablist" aria-label="Device sections" className="flex gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1">
                {TABS.map((t) => (
                    <button
                        key={t.key}
                        role="tab"
                        aria-selected={tab === t.key}
                        onClick={() => setTab(t.key)}
                        className={`min-h-[38px] flex-1 whitespace-nowrap rounded-lg px-3 text-sm font-semibold transition-colors ${FOCUS} ${
                            tab === t.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'
                        }`}
                    >
                        {t.label}
                    </button>
                ))}
            </div>

            {tab === 'runs' && (
                runs.length === 0 ? (
                    <Card><EmptyState title="No sync runs yet" hint="Sync punches and the run appears here." /></Card>
                ) : (
                    <div className="space-y-3">
                        {runs.map((run) => (
                            <Card key={run.id} className="p-4">
                                <div className="flex items-center justify-between gap-2">
                                    <RunStatusBadge status={run.status} />
                                    <span className="text-xs text-slate-500">{shortTime(run.started_at)}</span>
                                </div>
                                <div className="mt-2">
                                    <CountChips counts={run.counts} />
                                </div>
                                <div className="mt-2 text-xs text-slate-500">
                                    {run.mode} · {formatDuration(run.duration_ms)}
                                </div>
                                <DeviceError error={run.error} className="mt-1.5 text-xs text-rose-600" />
                            </Card>
                        ))}
                    </div>
                )
            )}

            {tab === 'queue' && (
                commands.length === 0 ? (
                    <Card><EmptyState title="Nothing queued" hint="Commands you fire show up here." /></Card>
                ) : (
                    <div className="space-y-3">
                        {commands.map((c) => (
                            <Card key={c.id} className="p-4">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <div className="font-medium text-slate-900">{commandLabel(c.command)}</div>
                                        <div className="mt-0.5 text-xs text-slate-500">
                                            attempt {c.attempts} of {c.max_attempts} · {shortTime(c.finished_at ?? c.created_at)}
                                        </div>
                                    </div>
                                    <CommandStatusBadge status={c.status} />
                                </div>
                                {c.message && <p className="mt-2 text-xs text-slate-600">{c.message}</p>}
                                <DeviceError error={c.error} className="mt-1 text-xs text-rose-600" />
                                {canSync && (c.can_retry || !c.is_finished) && (
                                    <div className="mt-3 flex gap-2">
                                        {c.can_retry && (
                                            <Button variant="soft" className="min-h-[36px] flex-1 text-xs" onClick={() => onRetryCommand(c)}>Retry</Button>
                                        )}
                                        {!c.is_finished && (
                                            <Button variant="secondary" className="min-h-[36px] flex-1 text-xs" onClick={() => onCancelCommand(c)}>Cancel</Button>
                                        )}
                                    </div>
                                )}
                            </Card>
                        ))}
                    </div>
                )
            )}

            {tab === 'info' && (
                <div className="space-y-4">
                    <SectionCard
                        title="Identity"
                        subtitle={device.info_read_at ? `Read ${relativeTime(device.info_read_at)}` : 'Run "Read info" to fill this in'}
                    >
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
                            <Fact label="Model">{device.model}</Fact>
                            <Fact label="Firmware">{device.firmware_version}</Fact>
                            <Fact label="Serial" mono>{device.serial_number}</Fact>
                            <Fact label="Platform">{device.platform}</Fact>
                            <Fact label="Device timezone">{device.timezone}</Fact>
                            <Fact label="Sync position">{shortTime(device.attendance_watermark)}</Fact>
                        </dl>
                    </SectionCard>

                    <SectionCard title="Health history" subtitle="Transitions only." bodyClass="p-0">
                        {history.length === 0 ? (
                            <p className="px-4 py-5 text-sm text-slate-500">No status changes recorded yet.</p>
                        ) : (
                            <ol className="divide-y divide-slate-100">
                                {history.map((h) => (
                                    <li key={h.id} className="flex items-center justify-between gap-3 px-4 py-2.5 text-xs">
                                        <span className="font-semibold capitalize text-slate-800">
                                            {h.previous_status ? `${h.previous_status} → ` : ''}{h.status}
                                        </span>
                                        <span className="shrink-0 text-slate-500">{shortTime(h.observed_at)}</span>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </SectionCard>
                </div>
            )}

            {tab === 'caps' && (
                <SectionCard
                    title="What this firmware can do"
                    subtitle={device.capabilities_probed_at ? `Probed ${relativeTime(device.capabilities_probed_at)}` : 'Protocol baseline — not yet probed'}
                    bodyClass="p-0"
                >
                    <ul className="divide-y divide-slate-100">
                        {Object.entries(caps).map(([key, cap]) => (
                            <li key={key} className="px-4 py-2.5">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="truncate font-mono text-[12px] text-slate-600">{key}</span>
                                    <CapabilityBadge level={cap.level} />
                                </div>
                                <p className="mt-0.5 text-xs text-slate-500">{CAPABILITY_LEVELS[cap.level]?.hint}</p>
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            )}

            <div className="pt-1">
                <Button variant="secondary" className="w-full" onClick={onRefresh}>Refresh</Button>
            </div>
        </div>
    );
}
