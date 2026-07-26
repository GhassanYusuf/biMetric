import { Button, Card, EmptyState, PageHeader, SelectField, Spinner, TableShell, TextField, Thead } from '../../shared/ui';
import { Pager, formatPunch, verifyLabel } from './parts';

// Desktop attendance: filter bar + wide punch table + pager.
export default function AttendanceDesktop({ rows, devices, filters, onFilter, loading, pagination, onPage }) {
    function set(key, value) {
        onFilter({ ...filters, [key]: value });
    }

    const hasFilters = !!(filters.device_id || filters.from || filters.to);
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
            </PageHeader>

            <Card className="p-4">
                <div className="grid grid-cols-2 items-end gap-3 lg:grid-cols-4">
                    <SelectField label="Device" value={filters.device_id} onChange={(e) => set('device_id', e.target.value)}>
                        <option value="">All devices</option>
                        {devices.map((d) => (
                            <option key={d.id} value={d.id}>{d.name}</option>
                        ))}
                    </SelectField>
                    <TextField label="From" type="date" value={filters.from} onChange={(e) => set('from', e.target.value)} />
                    <TextField label="To" type="date" value={filters.to} onChange={(e) => set('to', e.target.value)} />
                    {hasFilters && (
                        <Button variant="ghost" onClick={() => onFilter({ device_id: '', from: '', to: '' })}>
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
                        <th className="px-5 py-3 text-left">Device</th>
                        <th className="px-5 py-3 text-left">Method</th>
                        <th className="px-5 py-3 text-left">Date</th>
                        <th className="px-5 py-3 text-right">Time</th>
                    </tr>
                </Thead>
                <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={6}>
                                <EmptyState
                                    title="No punches found"
                                    hint={hasFilters ? 'No punches match these filters. Try widening the date range.' : 'Sync a device to pull attendance in.'}
                                    action={hasFilters && (
                                        <Button variant="secondary" onClick={() => onFilter({ device_id: '', from: '', to: '' })}>
                                            Clear filters
                                        </Button>
                                    )}
                                />
                            </td>
                        </tr>
                    )}
                    {rows.map((log) => {
                        const { date, time } = formatPunch(log.punched_at);
                        return (
                            <tr key={log.id} className="transition-colors duration-150 hover:bg-slate-50/70">
                                <td className="px-5 py-3 font-medium text-slate-900">
                                    {log.employee_name || <span className="font-normal text-slate-500">Unmatched</span>}
                                </td>
                                <td className="px-5 py-3 font-mono text-xs text-slate-600">{log.employee_code || '—'}</td>
                                <td className="px-5 py-3 text-slate-600">{log.device_name || '—'}</td>
                                <td className="px-5 py-3 text-slate-600">{verifyLabel(log.verify_method)}</td>
                                <td className="px-5 py-3 text-slate-600">{date}</td>
                                <td className="px-5 py-3 text-right font-semibold tabular-nums text-slate-700">{time}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </TableShell>

            <Pager pagination={pagination} onPage={onPage} loading={loading} />
        </div>
    );
}
