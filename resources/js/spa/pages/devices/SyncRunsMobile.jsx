import { Link } from 'react-router-dom';
import { Badge, Button, Card, EmptyState, PageHeader, SelectField } from '../../shared/ui';
import { CountChips, RUN_STATUSES, RunStatusBadge, formatDuration, shortTime } from './statusParts';

/*
| Mobile sync history: one card per run.
|
| The five counts stay together as a single chip row rather than becoming columns —
| they are only meaningful read against each other, and splitting them across a narrow
| screen would lose the comparison that makes them worth showing.
*/
export default function SyncRunsMobile({ runs, devices, meta, filters, loading, onFilter, onRefresh }) {
    return (
        <div className="space-y-4">
            <PageHeader
                compact
                title="Sync history"
                subtitle={meta?.pagination?.total ? `${meta.pagination.total.toLocaleString()} runs recorded` : 'Attendance reads per terminal'}
            >
                <Button variant="secondary" className="min-h-[38px] px-3 text-xs" loading={loading} onClick={onRefresh}>
                    Refresh
                </Button>
            </PageHeader>

            <div className="grid grid-cols-2 gap-2">
                <SelectField
                    aria-label="Filter by terminal"
                    value={filters.device_id}
                    onChange={(e) => onFilter('device_id', e.target.value)}
                >
                    <option value="">All terminals</option>
                    {devices.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                </SelectField>
                <SelectField
                    aria-label="Filter by outcome"
                    value={filters.status}
                    onChange={(e) => onFilter('status', e.target.value)}
                >
                    <option value="">Any outcome</option>
                    {RUN_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
                </SelectField>
            </div>

            {runs.length === 0 ? (
                <Card>
                    <EmptyState
                        title={filters.device_id || filters.status ? 'Nothing matches those filters' : 'No sync runs yet'}
                        hint={filters.device_id || filters.status
                            ? 'Clear the filters to see everything.'
                            : 'Sync a terminal and its run appears here.'}
                    />
                </Card>
            ) : (
                <div className="space-y-3">
                    {runs.map((run) => (
                        <Card key={run.id} className="p-4">
                            <div className="flex items-start justify-between gap-2">
                                <Link to={`/devices/${run.device_id}`} className="min-w-0 font-semibold text-violet-700">
                                    {run.device_name ?? `Device ${run.device_id}`}
                                </Link>
                                <div className="flex shrink-0 items-center gap-1.5">
                                    <RunStatusBadge status={run.status} />
                                    {run.needs_attention && <Badge tone="amber">Check</Badge>}
                                </div>
                            </div>
                            <div className="mt-1 text-xs text-slate-500">
                                {shortTime(run.started_at)} · {run.mode} · {formatDuration(run.duration_ms)}
                            </div>
                            <div className="mt-2.5">
                                <CountChips counts={run.counts} />
                            </div>
                            {run.message && <p className="mt-2 text-xs text-slate-600">{run.message}</p>}
                            {run.error && <p className="mt-1 text-xs text-rose-600">{run.error}</p>}
                        </Card>
                    ))}
                </div>
            )}

            {runs.length > 0 && (
                <p className="text-xs text-slate-500">
                    Lots of “already known” with no new records is correct incremental behaviour. “Undecodable” above zero is the one to chase.
                </p>
            )}
        </div>
    );
}
