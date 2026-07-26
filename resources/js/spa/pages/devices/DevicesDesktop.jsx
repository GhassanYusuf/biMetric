import { Link } from 'react-router-dom';
import { Badge, Button, EmptyState, PageHeader, TableShell, Thead } from '../../shared/ui';
import { PROTOCOL_LABELS, SyncBadge, relativeTime } from './parts';

// Desktop devices: wide table, one row per unit, hardware actions inline.
export default function DevicesDesktop({
    rows, busy,
    canCreate, canUpdate, canDelete, canSync,
    onAdd, onEdit, onDelete, onTest, onSync, onImport,
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
                        return (
                            <tr key={d.id} className="transition-colors duration-150 hover:bg-slate-50/70">
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
                                        <div className="flex items-center justify-end gap-1.5">
                                            {canSync && (
                                                <>
                                                    <Button variant="secondary" className="min-h-[34px] px-2.5 text-xs" disabled={!!running} loading={running === 'test-connection'} onClick={() => onTest(d)}>
                                                        Test
                                                    </Button>
                                                    <Button variant="soft" className="min-h-[34px] px-2.5 text-xs" disabled={!!running} loading={running === 'sync-attendance'} onClick={() => onSync(d)}>
                                                        Sync punches
                                                    </Button>
                                                    <Button variant="secondary" className="min-h-[34px] px-2.5 text-xs" disabled={!!running} loading={running === 'import-employees'} onClick={() => onImport(d)}>
                                                        Import staff
                                                    </Button>
                                                </>
                                            )}
                                            {canUpdate && (
                                                <button onClick={() => onEdit(d)} className="rounded-lg px-2 py-1 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900">Edit</button>
                                            )}
                                            {canDelete && (
                                                <button onClick={() => onDelete(d)} className="rounded-lg px-2 py-1 text-sm font-medium text-rose-600 transition-colors hover:bg-rose-50">Delete</button>
                                            )}
                                        </div>
                                    </td>
                                )}
                            </tr>
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
