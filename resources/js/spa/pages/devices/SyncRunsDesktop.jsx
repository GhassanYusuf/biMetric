import { Link } from 'react-router-dom';
import { Badge, Button, Card, EmptyState, PageHeader, SelectField, TableShell, Thead } from '../../shared/ui';
import { describeRunMode } from './parts';
import { DeviceError, RUN_STATUSES, RunStatusBadge, absoluteTime, formatDuration } from './statusParts';

/*
| Desktop sync history: one row per run, with all five record counts as their own
| columns.
|
| The columns are deliberately not collapsed into a single "imported" number. Read
| vs. new vs. already-known is the difference between a healthy incremental sync and
| a decoder that is dropping records — and `undecodable` above zero is the one number
| that always deserves someone's attention.
*/
export default function SyncRunsDesktop({ runs, devices, meta, totals, filters, loading, clearing, canClear, onFilter, onRefresh, onClear }) {
    const attention = runs.filter((r) => r.needs_attention).length;

    return (
        <div className="space-y-5">
            <PageHeader
                title="Sync history"
                subtitle={
                    runs.length === 0
                        ? 'Every attendance read from every terminal, with its full record counts'
                        : `${meta?.pagination?.total?.toLocaleString() ?? runs.length} run${runs.length === 1 ? '' : 's'}${attention ? ` · ${attention} on this page need attention` : ''}`
                }
            >
                <Button variant="secondary" loading={loading} onClick={onRefresh}>Refresh</Button>
                {/*
                  | Clearing follows the filters above, so the label says which it is.
                  | Hidden without devices.delete, and disabled with nothing on screen
                  | to clear — a destructive button that does nothing is still a button
                  | somebody will press.
                  */}
                {canClear && (
                    <Button
                        variant="danger"
                        loading={clearing}
                        disabled={runs.length === 0}
                        onClick={onClear}
                    >
                        {filters.device_id || filters.status ? 'Clear these runs' : 'Clear history'}
                    </Button>
                )}
            </PageHeader>

            <div className="flex flex-wrap items-end gap-3">
                <SelectField
                    label="Terminal"
                    className="min-w-[14rem]"
                    value={filters.device_id}
                    onChange={(e) => onFilter('device_id', e.target.value)}
                >
                    <option value="">All terminals</option>
                    {devices.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                </SelectField>
                <SelectField
                    label="Outcome"
                    className="min-w-[11rem]"
                    value={filters.status}
                    onChange={(e) => onFilter('status', e.target.value)}
                >
                    <option value="">Any outcome</option>
                    {RUN_STATUSES.map((s) => <option key={s} value={s} className="capitalize">{s}</option>)}
                </SelectField>
            </div>

            {/* Page totals — explicitly scoped to what is loaded, never implied as all-time. */}
            {runs.length > 0 && (
                <div className="grid grid-cols-5 gap-3">
                    {[
                        { label: 'Records read', value: totals.read, tone: 'text-slate-900' },
                        { label: 'Newly imported', value: totals.imported, tone: 'text-emerald-700' },
                        { label: 'Already known', value: totals.duplicate, tone: 'text-slate-900' },
                        { label: 'Unattributed', value: totals.unmatched, tone: totals.unmatched ? 'text-amber-700' : 'text-slate-900' },
                        { label: 'Undecodable', value: totals.undecodable, tone: totals.undecodable ? 'text-rose-700' : 'text-slate-900' },
                    ].map((t) => (
                        <Card key={t.label} className="p-4">
                            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{t.label}</div>
                            <div className={`mt-1 text-2xl font-bold tabular-nums tracking-[-0.02em] ${t.tone}`}>
                                {t.value.toLocaleString()}
                            </div>
                        </Card>
                    ))}
                </div>
            )}

            <TableShell>
                <Thead>
                    <tr>
                        <th className="px-5 py-3 text-left">Started</th>
                        <th className="px-5 py-3 text-left">Terminal</th>
                        <th className="px-5 py-3 text-left">Mode</th>
                        <th className="px-5 py-3 text-left">Outcome</th>
                        <th className="px-4 py-3 text-right">Read</th>
                        <th className="px-4 py-3 text-right">New</th>
                        <th className="px-4 py-3 text-right">Known</th>
                        <th className="px-4 py-3 text-right">Unattr.</th>
                        <th className="px-4 py-3 text-right">Undec.</th>
                        <th className="px-5 py-3 text-right">Took</th>
                    </tr>
                </Thead>
                <tbody className="divide-y divide-slate-100">
                    {runs.length === 0 && (
                        <tr>
                            <td colSpan={10}>
                                <EmptyState
                                    title={filters.device_id || filters.status ? 'No runs match those filters' : 'No sync runs yet'}
                                    hint={filters.device_id || filters.status
                                        ? 'Clear the filters to see the full history.'
                                        : 'Queue a punch sync from a device and its run will be recorded here.'}
                                />
                            </td>
                        </tr>
                    )}
                    {runs.map((run) => (
                        <tr key={run.id} className="align-top transition-colors duration-150 hover:bg-slate-50/70">
                            <td className="px-5 py-3.5 text-slate-600">{absoluteTime(run.started_at)}</td>
                            <td className="px-5 py-3.5">
                                <Link to={`/devices/${run.device_id}`} className="font-medium text-violet-700 hover:underline">
                                    {run.device_name ?? `Device ${run.device_id}`}
                                </Link>
                            </td>
                            <td className="px-5 py-3.5 text-slate-600 first-letter:uppercase">{describeRunMode(run)}</td>
                            <td className="px-5 py-3.5">
                                <div className="flex items-center gap-1.5">
                                    <RunStatusBadge status={run.status} />
                                    {run.needs_attention && <Badge tone="amber">Check</Badge>}
                                </div>
                                {run.message && <div className="mt-1 max-w-xs text-xs text-slate-600">{run.message}</div>}
                                <DeviceError error={run.error} />
                            </td>
                            <td className="px-4 py-3.5 text-right tabular-nums text-slate-700">{(run.counts?.read ?? 0).toLocaleString()}</td>
                            <td className={`px-4 py-3.5 text-right font-semibold tabular-nums ${run.counts?.imported > 0 ? 'text-emerald-700' : 'text-slate-500'}`}>
                                {(run.counts?.imported ?? 0).toLocaleString()}
                            </td>
                            <td className="px-4 py-3.5 text-right tabular-nums text-slate-500">{(run.counts?.duplicate ?? 0).toLocaleString()}</td>
                            <td className={`px-4 py-3.5 text-right tabular-nums ${run.counts?.unmatched > 0 ? 'font-semibold text-amber-700' : 'text-slate-500'}`}>
                                {(run.counts?.unmatched ?? 0).toLocaleString()}
                            </td>
                            <td className={`px-4 py-3.5 text-right tabular-nums ${run.counts?.undecodable > 0 ? 'font-semibold text-rose-700' : 'text-slate-500'}`}>
                                {(run.counts?.undecodable ?? 0).toLocaleString()}
                            </td>
                            <td className="px-5 py-3.5 text-right text-slate-600">{formatDuration(run.duration_ms)}</td>
                        </tr>
                    ))}
                </tbody>
            </TableShell>

            {runs.length > 0 && (
                <div className="space-y-1 text-xs text-slate-500">
                    <p>
                        Totals above cover the {runs.length} run{runs.length === 1 ? '' : 's'} on this page, not all history.
                    </p>
                    <p>
                        <strong className="font-semibold text-slate-600">Known</strong> means the punch was already stored — a high count with zero
                        new records is correct incremental behaviour, not a failure.{' '}
                        <strong className="font-semibold text-slate-600">Undecodable</strong> above zero means the terminal offered punches the decoder
                        could not read; they are archived for reparsing rather than discarded.
                    </p>
                </div>
            )}
        </div>
    );
}
