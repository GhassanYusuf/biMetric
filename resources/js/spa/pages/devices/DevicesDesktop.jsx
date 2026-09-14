import { Fragment } from 'react';
import { Link } from 'react-router-dom';
import { ActionMenu, Badge, Button, EmptyState, PageHeader, ProgressBar, TableShell, Thead } from '../../shared/ui';
import { PROTOCOL_LABELS, SyncBadge, relativeTime } from './parts';
import { describeProgress } from '../../shared/useSyncProgress';

// Desktop devices: wide table, one row per unit, hardware actions inline.
export default function DevicesDesktop({
    rows, busy, syncingId, syncProgress, syncStalled,
    canCreate, canUpdate, canDelete, canSync,
    onAdd, onEdit, onDelete, onTest, onSync, onImport, onFullResync,
}) {
    const showActions = canSync || canUpdate || canDelete;
    const failing = rows.filter((d) => d.last_sync_status === 'error').length;

    return (
        <div className="space-y-5">
            <PageHeader
                title="Devices"
                subtitle={
                    rows.length === 0
                        ? 'Biometric terminals this workspace reads attendance from'
                        : `${rows.length} terminal${rows.length > 1 ? 's' : ''}${failing ? ` · ${failing} reporting errors` : ' · all reporting cleanly'}`
                }
            >
                {canCreate && <Button onClick={onAdd}>+ Add device</Button>}
            </PageHeader>

            <TableShell>
                <Thead>
                    <tr>
                        <th className="px-5 py-3 text-left">Device</th>
                        <th className="px-5 py-3 text-left">Address</th>
                        <th className="px-5 py-3 text-left">Protocol</th>
                        <th className="px-5 py-3 text-left">Status</th>
                        <th className="px-5 py-3 text-left">Last sync</th>
                        <th className="px-5 py-3 text-right">Punches</th>
                        {showActions && <th className="px-5 py-3" />}
                    </tr>
                </Thead>
                <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={7}>
                                <EmptyState
                                    title="No devices yet"
                                    hint="Add a terminal by IP address to start pulling punches into attendance."
                                    action={canCreate && <Button onClick={onAdd}>+ Add your first device</Button>}
                                />
                            </td>
                        </tr>
                    )}
                    {rows.map((d) => {
                        const running = busy[d.id];
                        const syncing = syncingId === d.id;
                        return (
                            <Fragment key={d.id}>
                            <tr className="transition-colors duration-150 hover:bg-slate-50/70">
                                <td className="px-5 py-3.5">
                                    {/* The name is the way in to everything else about this unit. */}
                                    <Link to={`/devices/${d.id}`} className="font-medium text-slate-900 hover:text-violet-700 hover:underline">
                                        {d.name}
                                    </Link>
                                    {d.location && <div className="text-xs text-slate-500">{d.location}</div>}
                                </td>
                                <td className="px-5 py-3.5 font-mono text-xs text-slate-600">{d.ip_address}:{d.port}</td>
                                <td className="px-5 py-3.5 text-slate-600">{PROTOCOL_LABELS[d.protocol] || d.protocol}</td>
                                <td className="px-5 py-3.5">
                                    <div className="flex items-center gap-1.5">
                                        <SyncBadge status={d.last_sync_status} />
                                        {!d.is_active && <Badge tone="amber">Disabled</Badge>}
                                    </div>
                                    {d.last_sync_error && (
                                        <div className="mt-1 max-w-xs truncate text-xs text-rose-600" title={d.last_sync_error}>
                                            {d.last_sync_error}
                                        </div>
                                    )}
                                </td>
                                <td className="px-5 py-3.5 text-slate-600">{relativeTime(d.last_synced_at)}</td>
                                <td className="px-5 py-3.5 text-right font-medium tabular-nums text-slate-700">
                                    {(d.attendance_logs_count ?? 0).toLocaleString()}
                                </td>
                                {showActions && (
                                    <td className="px-5 py-3.5">
                                        {/*
                                          | One menu, not six buttons. The strip that used to
                                          | live here shrank every label past readability and
                                          | put Delete next to Sync punches; the menu gives
                                          | each action a full label, a line saying what it
                                          | actually does, and keeps the destructive one
                                          | below a rule of its own.
                                          |
                                          | The per-action spinner is not lost — while a
                                          | command runs the trigger wears that command's
                                          | own label (see ActionMenu).
                                        */}
                                        <div className="flex justify-end">
                                            <ActionMenu
                                                label="Actions"
                                                items={[
                                                    canSync && {
                                                        key: 'test',
                                                        label: 'Test',
                                                        description: 'Open a session and confirm the terminal answers.',
                                                        loading: running === 'test-connection',
                                                        disabled: !!running,
                                                        onSelect: () => onTest(d),
                                                    },
                                                    canSync && {
                                                        key: 'sync',
                                                        label: 'Sync punches',
                                                        description: 'Read everything recorded since the last watermark.',
                                                        tone: 'primary',
                                                        loading: running === 'sync-attendance',
                                                        disabled: !!running,
                                                        onSelect: () => onSync(d),
                                                    },
                                                    canSync && {
                                                        /*
                                                          | Directly under Sync punches because it is the
                                                          | same operation with the watermark ignored.
                                                          | Not `danger` — slow, not destructive.
                                                        */
                                                        key: 'full-resync',
                                                        label: 'Full re-read',
                                                        description: 'The same read with the watermark ignored. Slow, but safe.',
                                                        loading: running === 'full-resync',
                                                        disabled: !!running,
                                                        onSelect: () => onFullResync(d),
                                                    },
                                                    canSync && {
                                                        key: 'import',
                                                        label: 'Import staff',
                                                        description: 'Pull the employee records the terminal holds.',
                                                        loading: running === 'import-employees',
                                                        disabled: !!running,
                                                        onSelect: () => onImport(d),
                                                    },
                                                    canSync && (canUpdate || canDelete) && { key: 'sep', separator: true },
                                                    canUpdate && {
                                                        key: 'edit',
                                                        label: 'Edit device',
                                                        description: 'Name, address, protocol and credentials.',
                                                        onSelect: () => onEdit(d),
                                                    },
                                                    canDelete && {
                                                        key: 'delete',
                                                        label: 'Delete device',
                                                        description: 'Removes the terminal and its link to these punches.',
                                                        tone: 'danger',
                                                        onSelect: () => onDelete(d),
                                                    },
                                                ]}
                                            />
                                        </div>
                                    </td>
                                )}
                            </tr>
                            {/*
                              | A full-width row beneath the device, not a bar squeezed
                              | into a cell: the numbers need room, and the bar belongs
                              | to the whole row rather than to any one column.
                              |
                              | This is page STATE, not an event, so it is content
                              | rather than a toast (rule 2) — it describes how things
                              | currently are and stops existing when they change.
                            */}
                            {syncing && (
                                <tr>
                                    <td colSpan={showActions ? 7 : 6} className="bg-violet-50/40 px-5 pb-3.5">
                                        <SyncProgressRow progress={syncProgress} stalled={syncStalled} />
                                    </td>
                                </tr>
                            )}
                            </Fragment>
                        );
                    })}
                </tbody>
            </TableShell>

            {rows.length > 0 && (
                <p className="text-xs text-slate-500">
                    If <strong className="font-semibold text-slate-600">Test</strong> times out on the Binary protocol, the
                    terminal is likely reseller-branded firmware — try CSL HTTP with its web credentials.
                </p>
            )}
        </div>
    );
}

/**
 * The bar shown under a syncing device. Shared by both presentations via
 * describeProgress(), so desktop and mobile cannot drift apart (rule 5).
 */
export function SyncProgressRow({ progress, stalled }) {
    const { label, detail, percent } = describeProgress(progress);

    return (
        <div>
            <ProgressBar
                percent={percent}
                label={label}
                detail={detail}
                tone={progress?.phase === 'importing' ? 'emerald' : 'violet'}
            />
            {stalled && (
                // Careful wording: we lost the progress feed, which is NOT evidence
                // the sync failed. It runs in a different request and may be fine.
                <p className="mt-1.5 text-xs text-slate-500">
                    Lost contact with the progress feed — the sync may still be running.
                </p>
            )}
        </div>
    );
}
