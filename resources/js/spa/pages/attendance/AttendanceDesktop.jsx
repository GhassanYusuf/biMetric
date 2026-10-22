import { FOCUS } from '../../shared/theme';
import { ActionMenu, Button, Card, EmptyState, PageHeader, Pager, SelectField, Spinner, TableShell, TextField, Thead } from '../../shared/ui';
import UnmatchedName from './UnmatchedName';
import DepartmentTree from '../employees/DepartmentTree';
import { formatPunch, verifyLabel } from './parts';

// Every filter this page owns, cleared. Named so "Clear filters" cannot forget one
// — an employee_id left behind would leave the table showing one person under a
// header claiming no filters are set.
const NO_FILTERS = { employee_id: '', device_id: '', department_id: '', search: '', from: '', to: '' };

// Desktop attendance: filter bar + wide punch table + pager.
export default function AttendanceDesktop({
    rows, devices, departments, unassignedCount, departmentsLoaded,
    filters, onFilter, search, onSearch, loading, pagination, onPage, onSelect, exportItems,
}) {
    function set(key, value) {
        onFilter({ ...filters, [key]: value });
    }

    const hasFilters = !!(filters.employee_id || filters.device_id || filters.department_id || filters.search || filters.from || filters.to);
    const total = pagination?.total;

    return (
        <div className="space-y-5">
            <PageHeader
                title="Attendance"
                subtitle={
                    total !== undefined && total !== null
                        ? `${total.toLocaleString()} punch${total === 1 ? '' : 'es'}${hasFilters ? ' matching your filters' : ' recorded'}`
                        : 'Every punch recorded by your devices'
                }
            >
                {/* Refetches keep the table in place; this only says work is happening. */}
                {loading && <Spinner className="h-5 w-5 text-violet-600" />}
                {/* These punches (everything matching the filters, not the visible page), or
                    every employee as a PDF or an Excel workbook. */}
                <ActionMenu label="Export" className="min-h-[42px] px-4 text-sm" items={exportItems} />
            </PageHeader>

            {/* The same Company › Department tree as the Employees page (2026-10-11),
                on the left; the bar above the table narrows within it. */}
            <div className="grid grid-cols-[15rem_minmax(0,1fr)] items-start gap-5 xl:grid-cols-[17rem_minmax(0,1fr)]">
            <div className="sticky top-4 max-h-[calc(100vh-2rem)] overflow-y-auto">
                <DepartmentTree
                    departments={departments}
                    unassignedCount={unassignedCount}
                    loaded={departmentsLoaded}
                    counts={false}
                    allLabel="Everyone"
                    value={filters.department_id ?? ''}
                    onChange={(id) => set('department_id', id)}
                />
            </div>

            <div className="min-w-0 space-y-5">
            <Card className="space-y-4 p-4">
                <div className="grid grid-cols-2 items-end gap-3 lg:grid-cols-5">
                    <TextField
                        label="Search"
                        placeholder="Name, code or PIN…"
                        value={search}
                        onChange={(e) => onSearch(e.target.value)}
                    />
                    <SelectField label="Device" value={filters.device_id} onChange={(e) => set('device_id', e.target.value)}>
                        <option value="">All devices</option>
                        {devices.map((d) => (
                            <option key={d.id} value={d.id}>{d.name}</option>
                        ))}
                    </SelectField>
                    <TextField label="From" type="date" value={filters.from} onChange={(e) => set('from', e.target.value)} />
                    <TextField label="To" type="date" value={filters.to} onChange={(e) => set('to', e.target.value)} />
                    {hasFilters && (
                        <Button variant="ghost" onClick={() => onFilter(NO_FILTERS)}>
                            Clear filters
                        </Button>
                    )}
                </div>
            </Card>

            <TableShell>
                <Thead>
                    <tr>
                        <th className="px-5 py-3 text-left">Employee</th>
                        <th className="px-5 py-3 text-left">Code</th>
                        <th className="px-5 py-3 text-left">Device user ID</th>
                        <th className="px-5 py-3 text-left">Device</th>
                        <th className="px-5 py-3 text-left">Method</th>
                        <th className="px-5 py-3 text-left">Date</th>
                        <th className="px-5 py-3 text-right">Time</th>
                    </tr>
                </Thead>
                <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={7}>
                                <EmptyState
                                    title="No punches found"
                                    hint={hasFilters ? 'No punches match these filters. Try widening the date range.' : 'Sync a device to pull attendance in.'}
                                    action={hasFilters && (
                                        <Button variant="secondary" onClick={() => onFilter(NO_FILTERS)}>
                                            Clear filters
                                        </Button>
                                    )}
                                />
                            </td>
                        </tr>
                    )}
                    {rows.map((log) => {
                        const { date, time } = formatPunch(log.punched_at);
                        // A punch the sync could not attribute to anybody has no
                        // employee to show, so that row is not a link.
                        const openable = !!log.employee_id;

                        return (
                            <tr
                                key={log.id}
                                onClick={openable ? () => onSelect(log) : undefined}
                                onKeyDown={openable ? (event) => {
                                    if (event.key === 'Enter' || event.key === ' ') {
                                        event.preventDefault();
                                        onSelect(log);
                                    }
                                } : undefined}
                                tabIndex={openable ? 0 : undefined}
                                role={openable ? 'button' : undefined}
                                aria-label={openable ? `View ${log.employee_name || 'employee'} details` : undefined}
                                className={`transition-colors duration-150 ${
                                    openable ? `cursor-pointer hover:bg-violet-50/40 ${FOCUS}` : 'hover:bg-slate-50/70'
                                }`}
                            >
                                <td className="px-5 py-3 font-medium text-slate-900">
                                    {log.employee_name || <UnmatchedName log={log} />}
                                </td>
                                <td className="px-5 py-3 font-mono text-xs text-slate-600">{log.employee_code || '—'}</td>
                                {/* The number the terminal recorded — amber when it is not their
                                    Personnel ID (e.g. a terminal that could not move them). */}
                                <td
                                    className={`px-5 py-3 font-mono text-xs ${log.employee_code && String(log.device_user_id) !== String(log.employee_code) ? 'text-amber-700' : 'text-slate-600'}`}
                                    title={log.employee_code && String(log.device_user_id) !== String(log.employee_code) ? `Recorded by ${log.device_name || 'the terminal'} under ${log.device_user_id}, not their Personnel ID ${log.employee_code}` : undefined}
                                >
                                    {log.device_user_id || '—'}
                                </td>
                                <td className="px-5 py-3 text-slate-600">{log.device_name || '—'}</td>
                                <td className="px-5 py-3 text-slate-600">{verifyLabel(log.verify_method)}</td>
                                <td className="px-5 py-3 text-slate-600">{date}</td>
                                <td className="px-5 py-3 text-right font-semibold tabular-nums text-slate-700">{time}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </TableShell>

            <Pager pagination={pagination} onPage={onPage} loading={loading} noun="punches" />
            </div>
            </div>
        </div>
    );
}
