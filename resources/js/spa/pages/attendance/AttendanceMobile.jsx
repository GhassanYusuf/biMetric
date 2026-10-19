import { useState } from 'react';
import { FOCUS } from '../../shared/theme';
import { ActionMenu, Badge, Button, Card, EmptyState, PageHeader, Pager, SelectField, Skeleton, TextField } from '../../shared/ui';
import UnmatchedName from './UnmatchedName';
import DepartmentTree from '../employees/DepartmentTree';
import { formatPunch, groupByDay, verifyLabel } from './parts';

// Same object as the desktop's, for the same reason: one place that knows what a
// cleared filter bar is.
const NO_FILTERS = { employee_id: '', device_id: '', department_id: '', search: '', from: '', to: '' };

// Mobile attendance: collapsed filters behind a toggle (they'd otherwise eat the
// whole first screen), then punches grouped under sticky day headers.
export default function AttendanceMobile({
    rows, devices, departments, unassignedCount, departmentsLoaded,
    filters, onFilter, search, onSearch, loading, pagination, onPage, onSelect, exportItems,
}) {
    const [filtersOpen, setFiltersOpen] = useState(false);
    const hasFilters = !!(filters.employee_id || filters.device_id || filters.department_id || filters.search || filters.from || filters.to);
    // The employee counts too: with the panel shut, the badge is the only thing
    // saying the list has been narrowed by something.
    const activeCount = [filters.employee_id, filters.device_id, filters.department_id, filters.from, filters.to].filter(Boolean).length;

    function set(key, value) {
        onFilter({ ...filters, [key]: value });
    }

    return (
        <div className="space-y-4">
            <PageHeader title="Attendance" compact>
                {/* Same exports as desktop, same filters, same server-side scope. */}
                <ActionMenu label="Export" className="min-h-[38px] px-3 text-sm" items={exportItems} />
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

            {/* Search and the tree stay out from behind the Filters toggle: they are
                how most people get to one person's punches (rule 5: desktop has both). */}
            <TextField
                aria-label="Search punches"
                placeholder="Search by name, code or PIN…"
                value={search}
                onChange={(e) => onSearch(e.target.value)}
            />
            <DepartmentTree
                variant="collapsible"
                departments={departments}
                unassignedCount={unassignedCount}
                loaded={departmentsLoaded}
                counts={false}
                allLabel="Everyone"
                value={filters.department_id ?? ''}
                onChange={(id) => set('department_id', id)}
            />

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
                        <Button variant="secondary" className="w-full" onClick={() => onFilter(NO_FILTERS)}>
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
                            <Button variant="secondary" onClick={() => onFilter(NO_FILTERS)}>
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
                                    <PunchCard key={log.id} log={log} onSelect={onSelect} />
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <Pager pagination={pagination} onPage={onPage} loading={loading} noun="punches" />
        </div>
    );
}

/*
| One punch. Opens the employee behind it, matching the desktop row exactly —
| same target, same keyboard handling, same "Unmatched has nothing to open".
|
| A punch the sync could not attribute to an employee stays inert: a dialog with
| only a device name in it is worse than a row that does not respond.
*/
function PunchCard({ log, onSelect }) {
    const openable = !!log.employee_id;

    return (
        <Card
            role={openable ? 'button' : undefined}
            tabIndex={openable ? 0 : undefined}
            aria-label={openable ? `View ${log.employee_name || 'employee'} details` : undefined}
            onClick={openable ? () => onSelect(log) : undefined}
            onKeyDown={openable ? (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    onSelect(log);
                }
            } : undefined}
            className={`flex items-center gap-3 p-3.5 ${openable ? `cursor-pointer active:bg-slate-50 ${FOCUS}` : ''}`}
        >
            <div className="min-w-0 flex-1">
                <div className="truncate font-medium text-slate-900">
                    {log.employee_name || <UnmatchedName log={log} />}
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
    );
}
