import { useState } from 'react';
import { Badge, Button, Card, EmptyState, PageHeader, SelectField, Skeleton, TextField } from '../../shared/ui';
import { Pager, formatPunch, groupByDay, verifyLabel } from './parts';

// Mobile attendance: collapsed filters behind a toggle (they'd otherwise eat the
// whole first screen), then punches grouped under sticky day headers.
export default function AttendanceMobile({ rows, devices, filters, onFilter, loading, pagination, onPage }) {
    const [filtersOpen, setFiltersOpen] = useState(false);
    const hasFilters = !!(filters.device_id || filters.from || filters.to);
    const activeCount = [filters.device_id, filters.from, filters.to].filter(Boolean).length;

    function set(key, value) {
        onFilter({ ...filters, [key]: value });
    }

    return (
        <div className="space-y-4">
            <PageHeader title="Attendance" compact>
                <Button
                    variant="secondary"
                    className="min-h-[38px] px-3 text-sm"
                    onClick={() => setFiltersOpen((v) => !v)}
                    aria-expanded={filtersOpen}
                >
                    Filters
                    {activeCount > 0 && <Badge tone="violet">{activeCount}</Badge>}
                </Button>
            </PageHeader>

            {filtersOpen && (
                <Card className="space-y-3 p-4">
                    <SelectField label="Device" value={filters.device_id} onChange={(e) => set('device_id', e.target.value)}>
                        <option value="">All devices</option>
                        {devices.map((d) => (
                            <option key={d.id} value={d.id}>{d.name}</option>
                        ))}
                    </SelectField>
                    <div className="grid grid-cols-2 gap-3">
                        <TextField label="From" type="date" value={filters.from} onChange={(e) => set('from', e.target.value)} />
                        <TextField label="To" type="date" value={filters.to} onChange={(e) => set('to', e.target.value)} />
                    </div>
                    {hasFilters && (
                        <Button variant="secondary" className="w-full" onClick={() => onFilter({ device_id: '', from: '', to: '' })}>
                            Clear filters
                        </Button>
                    )}
                </Card>
            )}

            {loading && rows.length === 0 && (
                <div className="space-y-2" aria-busy="true" aria-label="Loading punches">
                    {Array.from({ length: 6 }, (_, i) => (
                        <Card key={i} className="flex items-center gap-3 p-3.5">
                            <div className="min-w-0 flex-1">
                                <Skeleton className="h-3.5 w-32" />
                                <Skeleton className="mt-2 h-2.5 w-40" />
                            </div>
                            <Skeleton className="h-3.5 w-10" />
                        </Card>
                    ))}
                </div>
            )}

            {!loading && rows.length === 0 ? (
                <Card>
                    <EmptyState
                        title="No punches found"
                        hint={hasFilters ? 'No punches match these filters. Try widening the date range.' : 'Sync a device to pull attendance in.'}
                        action={hasFilters && (
                            <Button variant="secondary" onClick={() => onFilter({ device_id: '', from: '', to: '' })}>
                                Clear filters
                            </Button>
                        )}
                    />
                </Card>
            ) : (
                <div className="space-y-4">
                    {groupByDay(rows).map((group) => (
                        <div key={group.day}>
                            <div className="sticky top-0 z-[1] -mx-4 bg-[#f6f7fb]/95 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 backdrop-blur">
                                {group.day}
                            </div>
                            <div className="mt-1 space-y-2">
                                {group.rows.map((log) => (
                                    <Card key={log.id} className="flex items-center gap-3 p-3.5">
                                        <div className="min-w-0 flex-1">
                                            <div className="truncate font-medium text-slate-900">
                                                {log.employee_name || <span className="text-slate-500">Unmatched</span>}
                                            </div>
                                            <div className="truncate text-xs text-slate-500">
                                                {log.device_name || '—'} · {verifyLabel(log.verify_method)}
                                                {log.employee_code && <span className="font-mono"> · {log.employee_code}</span>}
                                            </div>
                                        </div>
                                        <div className="shrink-0 text-right text-sm font-semibold tabular-nums text-slate-700">
                                            {formatPunch(log.punched_at).time}
                                        </div>
                                    </Card>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <Pager pagination={pagination} onPage={onPage} loading={loading} />
        </div>
    );
}
