import { Badge, Button, Card, EmptyState, PageHeader, SectionCard, TableShell, Thead } from '../../shared/ui';
import { PROTOCOL_LABELS, SyncBadge, relativeTime } from './parts';
import {
    CAPABILITY_LEVELS, CapabilityBadge, CommandStatusBadge, CountChips, Fact,
    HealthBadge, RunStatusBadge, absoluteTime, commandLabel, formatDuration, shortTime,
} from './statusParts';

/*
| Desktop device detail: a two-column body under a full-width identity header.
|
| The left column is history (what happened), the right is state (what is true now).
| Reading order matters here — an operator arrives because something looks wrong, so
| the newest sync run and the command queue sit where the eye lands first.
*/
export default function DeviceDetailDesktop({
    device, runs, commands, history, dispatching,
    canSync, onRefresh, onDispatch, onFullResync, onRetryCommand, onCancelCommand, onBack,
}) {
    const caps = device.capabilities ?? {};
    const busy = !!dispatching;

    return (
        <div className="space-y-5">
            <div>
                <button onClick={onBack} className="mb-2 text-xs font-semibold text-slate-500 transition-colors hover:text-violet-700">
                    ← Devices
                </button>
                <PageHeader
                    title={device.name}
                    subtitle={[
                        `${device.ip_address}:${device.port}`,
                        PROTOCOL_LABELS[device.protocol] || device.protocol,
                        device.location,
                    ].filter(Boolean).join(' · ')}
                >
                    <HealthBadge status={device.health_status} online={device.is_online} />
                    {!device.is_active && <Badge tone="amber">Disabled</Badge>}
                    <Button variant="secondary" onClick={onRefresh}>Refresh</Button>
                </PageHeader>
            </div>

            {/* --- Four numbers that answer "is this thing working?" at a glance. */}
            <div className="grid grid-cols-4 gap-3">
                <Card className="p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-slate-500">Punches stored</div>
                    <div className="mt-1 text-2xl font-bold tabular-nums tracking-[-0.02em] text-slate-900">
                        {(device.attendance_imported_total ?? 0).toLocaleString()}
                    </div>
                </Card>
                <Card className="p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-slate-500">Last seen</div>
                    <div className="mt-1 text-2xl font-bold tracking-[-0.02em] text-slate-900">
                        {relativeTime(device.last_seen_at)}
                    </div>
                    {device.last_latency_ms != null && (
                        <div className="mt-0.5 text-xs text-slate-500">{device.last_latency_ms}ms round trip</div>
                    )}
                </Card>
                <Card className="p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-slate-500">Sync position</div>
                    <div className="mt-1 text-sm font-semibold text-slate-900">{shortTime(device.attendance_watermark)}</div>
                    {/*
                      | The watermark is stored in UTC and rendered in the browser's zone.
                      | This label existed because a watermark written in device-local time
                      | once skipped three hours of real punches on every sync.
                    */}
                    <div className="mt-0.5 text-xs text-slate-500">
                        {device.attendance_watermark ? 'Next sync reads from here' : 'Never synced — next run reads everything'}
                    </div>
                </Card>
                <Card className="p-4">
                    <div className="text-xs font-medium uppercase tracking-wide text-slate-500">Consecutive failures</div>
                    <div className={`mt-1 text-2xl font-bold tabular-nums tracking-[-0.02em] ${
                        (device.consecutive_failures ?? 0) > 0 ? 'text-rose-600' : 'text-slate-900'
                    }`}>
                        {device.consecutive_failures ?? 0}
                    </div>
                </Card>
            </div>

            {canSync && (
                <SectionCard
                    title="Operations"
                    subtitle="Every action is queued and runs on a worker — nothing here waits on the hardware."
                >
                    <div className="flex flex-wrap gap-2">
                        <Button variant="soft" loading={dispatching === 'sync_attendance'} disabled={busy} onClick={() => onDispatch('sync_attendance')}>
                            Sync punches
                        </Button>
                        <Button variant="secondary" disabled={busy} onClick={onFullResync}>
                            Full re-read
                        </Button>
                        <Button variant="secondary" loading={dispatching === 'health_check'} disabled={busy} onClick={() => onDispatch('health_check')}>
                            Health check
                        </Button>
                        <Button variant="secondary" loading={dispatching === 'read_info'} disabled={busy} onClick={() => onDispatch('read_info')}>
                            Read info
                        </Button>
                        <Button variant="secondary" loading={dispatching === 'probe_capabilities'} disabled={busy} onClick={() => onDispatch('probe_capabilities')}>
                            Probe capabilities
                        </Button>
                        <Button variant="secondary" loading={dispatching === 'reconcile_users'} disabled={busy} onClick={() => onDispatch('reconcile_users')}>
                            Reconcile users
                        </Button>
                        {/*
                          | Setting the clock is gated on the capability, not on the
                          | permission. It is `implemented` and never confirmed on this
                          | firmware, so it is offered but honestly labelled — writing a
                          | wrong clock would misstamp every punch that follows.
                        */}
                        {caps['device.time.write']?.usable && (
                            <Button variant="secondary" loading={dispatching === 'sync_time'} disabled={busy} onClick={() => onDispatch('sync_time')}>
                                Set clock
                                {!caps['device.time.write']?.confirmed && (
                                    <span className="text-xs font-normal text-amber-700">(unproven)</span>
                                )}
                            </Button>
                        )}
                    </div>
                </SectionCard>
            )}

            <div className="grid grid-cols-3 gap-5">
                {/* ---- History: two columns wide, because it is why you came here. */}
                <div className="col-span-2 space-y-5">
                    <SectionCard title="Recent sync runs" bodyClass="p-0">
                        {runs.length === 0 ? (
                            <EmptyState title="No sync runs yet" hint="Queue a punch sync and the run will appear here with its full record counts." />
                        ) : (
                            <div className="divide-y divide-slate-100">
                                {runs.map((run) => (
                                    <div key={run.id} className="px-5 py-3.5">
                                        <div className="flex items-center justify-between gap-3">
                                            <div className="flex items-center gap-2">
                                                <RunStatusBadge status={run.status} />
                                                <span className="text-xs font-medium capitalize text-slate-600">{run.mode}</span>
                                                {run.needs_attention && <Badge tone="amber">Needs attention</Badge>}
                                            </div>
                                            <div className="text-xs text-slate-500">
                                                {shortTime(run.started_at)} · {formatDuration(run.duration_ms)}
                                            </div>
                                        </div>
                                        <div className="mt-2">
                                            <CountChips counts={run.counts} />
                                        </div>
                                        {run.error && <p className="mt-1.5 text-xs text-rose-600">{run.error}</p>}
                                    </div>
                                ))}
                            </div>
                        )}
                    </SectionCard>

                    <SectionCard title="Command queue" subtitle="Newest first. A failed command keeps its error so it can be diagnosed later." bodyClass="p-0">
                        {commands.length === 0 ? (
                            <EmptyState title="Nothing queued" hint="Commands you fire from Operations show up here with their outcome." />
                        ) : (
                            <TableShell className="rounded-none shadow-none">
                                <Thead>
                                    <tr>
                                        <th className="px-5 py-2.5 text-left">Command</th>
                                        <th className="px-5 py-2.5 text-left">Status</th>
                                        <th className="px-5 py-2.5 text-left">Attempts</th>
                                        <th className="px-5 py-2.5 text-left">Finished</th>
                                        <th className="px-5 py-2.5" />
                                    </tr>
                                </Thead>
                                <tbody className="divide-y divide-slate-100">
                                    {commands.map((c) => (
                                        <tr key={c.id} className="align-top transition-colors hover:bg-slate-50/70">
                                            <td className="px-5 py-3">
                                                <div className="font-medium text-slate-900">{commandLabel(c.command)}</div>
                                                {c.requested_by && <div className="text-xs text-slate-500">by {c.requested_by}</div>}
                                            </td>
                                            <td className="px-5 py-3">
                                                <CommandStatusBadge status={c.status} />
                                                {c.message && <div className="mt-1 max-w-xs text-xs text-slate-600">{c.message}</div>}
                                                {c.error && <div className="mt-1 max-w-xs text-xs text-rose-600">{c.error}</div>}
                                            </td>
                                            <td className="px-5 py-3 tabular-nums text-slate-600">
                                                {c.attempts}/{c.max_attempts}
                                            </td>
                                            <td className="px-5 py-3 text-slate-600">{shortTime(c.finished_at)}</td>
                                            <td className="px-5 py-3">
                                                <div className="flex justify-end gap-1.5">
                                                    {canSync && c.can_retry && (
                                                        <button onClick={() => onRetryCommand(c)} className="rounded-lg px-2 py-1 text-xs font-semibold text-violet-700 transition-colors hover:bg-violet-50">
                                                            Retry
                                                        </button>
                                                    )}
                                                    {canSync && !c.is_finished && (
                                                        <button onClick={() => onCancelCommand(c)} className="rounded-lg px-2 py-1 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-100">
                                                            Cancel
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </TableShell>
                        )}
                    </SectionCard>
                </div>

                {/* ---- State: identity, health trail, capabilities. */}
                <div className="space-y-5">
                    <SectionCard
                        title="Identity"
                        subtitle={device.info_read_at ? `Read from the terminal ${relativeTime(device.info_read_at)}` : 'Run "Read info" to fill this in'}
                    >
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
                            <Fact label="Model">{device.model}</Fact>
                            <Fact label="Firmware">{device.firmware_version}</Fact>
                            <Fact label="Platform">{device.platform}</Fact>
                            <Fact label="Serial" mono>{device.serial_number}</Fact>
                            <Fact label="MAC" mono>{device.mac_address}</Fact>
                            <Fact label="Device timezone">{device.timezone}</Fact>
                            <Fact label="User capacity">
                                {device.user_capacity != null ? device.user_capacity.toLocaleString() : null}
                            </Fact>
                            <Fact label="Log capacity">
                                {device.log_capacity != null ? device.log_capacity.toLocaleString() : null}
                            </Fact>
                        </dl>
                        <div className="mt-4 border-t border-slate-100 pt-3">
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
                                <Fact label="Last sync"><SyncBadge status={device.last_sync_status} /></Fact>
                                <Fact label="Comm key">{device.has_comm_key ? 'Set' : 'Not set'}</Fact>
                            </dl>
                            {device.last_sync_error && (
                                <p className="mt-3 text-xs text-rose-600">{device.last_sync_error}</p>
                            )}
                        </div>
                    </SectionCard>

                    <SectionCard title="Health history" subtitle="Only transitions are recorded — no rows means no changes." bodyClass="p-0">
                        {history.length === 0 ? (
                            <p className="px-5 py-6 text-sm text-slate-500">No status changes recorded yet.</p>
                        ) : (
                            <ol className="divide-y divide-slate-100">
                                {history.map((h) => (
                                    <li key={h.id} className="flex items-start justify-between gap-3 px-5 py-2.5">
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-1.5 text-xs">
                                                {h.previous_status && (
                                                    <>
                                                        <span className="capitalize text-slate-500">{h.previous_status}</span>
                                                        <span aria-hidden="true" className="text-slate-400">→</span>
                                                    </>
                                                )}
                                                <span className="font-semibold capitalize text-slate-800">{h.status}</span>
                                            </div>
                                            {h.reason && <p className="mt-0.5 truncate text-xs text-slate-500" title={h.reason}>{h.reason}</p>}
                                        </div>
                                        <div className="shrink-0 text-right text-xs text-slate-500">
                                            <div>{shortTime(h.observed_at)}</div>
                                            {h.latency_ms != null && <div className="tabular-nums">{h.latency_ms}ms</div>}
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </SectionCard>

                    <SectionCard
                        title="Capabilities"
                        subtitle={device.capabilities_probed_at
                            ? `Probed ${relativeTime(device.capabilities_probed_at)}`
                            : 'Baseline for this protocol — probe the device to confirm'}
                        bodyClass="p-0"
                    >
                        <ul className="divide-y divide-slate-100">
                            {Object.entries(caps).map(([key, cap]) => (
                                <li key={key} className="flex items-center justify-between gap-3 px-5 py-2">
                                    <span className="truncate font-mono text-[12px] text-slate-600" title={CAPABILITY_LEVELS[cap.level]?.hint}>
                                        {key}
                                    </span>
                                    <CapabilityBadge level={cap.level} />
                                </li>
                            ))}
                        </ul>
                        {/*
                          | Spelling out what the four levels mean, once, where they are
                          | shown. "Unproven" is not a warning about this device — it is an
                          | admission about our own evidence.
                        */}
                        <div className="space-y-1 border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                            {Object.entries(CAPABILITY_LEVELS).map(([level, meta]) => (
                                <div key={level}>
                                    <span className="font-semibold text-slate-600">{meta.label}</span> — {meta.hint}
                                </div>
                            ))}
                        </div>
                    </SectionCard>
                </div>
            </div>

            {device.info_read_at && (
                <p className="text-xs text-slate-500">
                    Identity last read {absoluteTime(device.info_read_at)}.
                </p>
            )}
        </div>
    );
}
