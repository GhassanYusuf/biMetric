import { Link } from 'react-router-dom';
import { Badge, Button, Card, EmptyState, PageHeader } from '../../shared/ui';
import { PROTOCOL_LABELS, SyncBadge, can, relativeTime } from './parts';
import { SyncProgressRow } from './DevicesDesktop';

// Mobile devices: stacked cards, actions as a full-width row of large tap
// targets rather than a cramped table.
export default function DevicesMobile({
    rows, busy, syncingId, syncProgress, syncStalled,
    canCreate, canUpdate, canDelete, canSync,
    onAdd, onEdit, onDelete, onTest, onSync, onImport, onFullResync,
}) {
    return (
        <div className="space-y-4">
            <PageHeader title="Devices" compact>
                {canCreate && <Button onClick={onAdd} className="px-3">+ Add</Button>}
            </PageHeader>

            {rows.length === 0 ? (
                <Card>
                    <EmptyState
                        title="No devices yet"
                        hint="Add a terminal by IP address to start pulling punches."
                        action={canCreate && <Button onClick={onAdd}>+ Add your first device</Button>}
                    />
                </Card>
            ) : (
                <div className="space-y-3">
                    {rows.map((d) => {
                        const running = busy[d.id];
                        return (
                            <Card key={d.id} className="p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        {/* The name opens the full record for this unit. */}
                                        <Link to={`/devices/${d.id}`} className="block truncate font-semibold text-slate-900">
                                            {d.name}
                                        </Link>
                                        <div className="font-mono text-xs text-slate-500">{d.ip_address}:{d.port}</div>
                                        {d.location && <div className="truncate text-xs text-slate-500">{d.location}</div>}
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <SyncBadge status={d.last_sync_status} />
                                        <div className="mt-1 text-[11px] text-slate-500">{relativeTime(d.last_synced_at)}</div>
                                    </div>
                                </div>

                                <div className="mt-3 flex flex-wrap items-center gap-1.5">
                                    <Badge tone="violet">{PROTOCOL_LABELS[d.protocol] || d.protocol}</Badge>
                                    {!d.is_active && <Badge tone="amber">Disabled</Badge>}
                                    <span className="ml-auto text-xs tabular-nums text-slate-500">
                                        {(d.attendance_logs_count ?? 0).toLocaleString()} punches
                                    </span>
                                </div>

                                {d.last_sync_error && (
                                    <p className="mt-2 text-xs text-rose-600">{d.last_sync_error}</p>
                                )}

                                {/*
                                  | Same bar, same words as the desktop table — rule 5
                                  | says the two presentations must behave identically,
                                  | and both render it from describeProgress().
                                */}
                                {syncingId === d.id && (
                                    <div className="mt-3 rounded-xl bg-violet-50/60 p-3">
                                        <SyncProgressRow progress={syncProgress} stalled={syncStalled} />
                                    </div>
                                )}

                                {canSync && (
                                    <div className="mt-3 grid grid-cols-3 gap-2">
                                        {/* Same capability gate as the desktop menu — rule 5
                                            says the two presentations behave identically, and
                                            rule 9 says an impossible action is hidden. */}
                                        <Button variant="secondary" className="px-1 text-xs" disabled={!!running} loading={running === 'test-connection'} onClick={() => onTest(d)}>
                                            Test
                                        </Button>
                                        {can(d, 'attendance.read') && (
                                            <Button variant="soft" className="px-1 text-xs" disabled={!!running} loading={running === 'sync-attendance'} onClick={() => onSync(d)}>
                                                Sync
                                            </Button>
                                        )}
                                        {can(d, 'user.read') && (
                                            <Button variant="secondary" className="px-1 text-xs" disabled={!!running} loading={running === 'import-employees'} onClick={() => onImport(d)}>
                                                Import
                                            </Button>
                                        )}
                                        {/*
                                          | Full width on its own row rather than a fourth
                                          | cramped column — the same shape device detail
                                          | uses, and the label does not survive truncation.
                                        */}
                                        {can(d, 'attendance.read') && (
                                            <Button variant="secondary" className="col-span-3 text-xs" disabled={!!running} loading={running === 'full-resync'} onClick={() => onFullResync(d)}>
                                                Full re-read
                                            </Button>
                                        )}
                                    </div>
                                )}

                                {(canUpdate || canDelete) && (
                                    <div className="mt-3 flex items-center gap-4 border-t border-slate-100 pt-3">
                                        {canUpdate && <button onClick={() => onEdit(d)} className="text-sm font-medium text-slate-500">Edit</button>}
                                        {canDelete && <button onClick={() => onDelete(d)} className="ml-auto text-sm font-medium text-rose-600">Delete</button>}
                                    </div>
                                )}
                            </Card>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
