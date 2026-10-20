import { ActionMenu, Button, Card, EmptyState, PageHeader, SelectField, Spinner, TableShell, TextField, Thead } from '../../shared/ui';
import { ExportRuns, hours, rangeLabel } from './parts';

// Desktop payroll summary: range + department filters, one row per employee,
// and a totals row that comes from the server's own sum.
export default function ReportsDesktop({ report, departments, filters, onFilter, loading, exporting, onExport, runs, downloading, deleting, onDownloadRun, onDeleteRun, allEmployeesItems }) {
    const rows = report?.rows ?? [];

    function set(key, value) {
        onFilter({ ...filters, [key]: value });
    }

    return (
        <div className="space-y-5">
            <PageHeader
                title="Payroll summary"
                subtitle={`${rangeLabel(report)} · ${(report?.totals?.employees ?? 0).toLocaleString()} active employees`}
            >
                {loading && <Spinner className="h-5 w-5 text-violet-600" />}
                <Button variant="secondary" loading={exporting === 'payroll'} disabled={!!exporting} onClick={() => onExport('payroll')}>
                    {exporting === 'payroll' ? 'Preparing…' : 'Payroll PDF'}
                </Button>
                <Button variant="secondary" loading={exporting === 'statement'} disabled={!!exporting} onClick={() => onExport('statement')}>
                    {exporting === 'statement' ? 'Preparing…' : 'Monthly statement'}
                </Button>
                <ActionMenu
                    label="Employees"
                    className="min-h-[42px] px-4 text-sm"
                    items={allEmployeesItems}
                />
                <Button loading={exporting === 'report'} disabled={!!exporting} onClick={() => onExport('report')}>
                    {exporting === 'report' ? 'Preparing…' : 'Attendance report'}
                </Button>
            </PageHeader>

            <Card className="p-4">
                <div className="grid grid-cols-2 items-end gap-3 lg:grid-cols-4">
                    <TextField label="From" type="date" value={filters.from} onChange={(e) => set('from', e.target.value)} />
                    <TextField label="To" type="date" value={filters.to} onChange={(e) => set('to', e.target.value)} />
                    <SelectField label="Department" value={filters.department_id} onChange={(e) => set('department_id', e.target.value)}>
                        <option value="">All departments</option>
                        {departments.map((d) => (
                            <option key={d.id} value={d.id}>{d.name}</option>
                        ))}
                    </SelectField>
                    {(filters.from || filters.to || filters.department_id) && (
                        <Button variant="ghost" onClick={() => onFilter({ from: '', to: '', department_id: '' })}>
                            Clear filters
                        </Button>
                    )}
                </div>
            </Card>

            <ExportRuns runs={runs} downloading={downloading} deleting={deleting} onDownload={onDownloadRun} onDelete={onDeleteRun} />

            <TableShell>
                <Thead>
                    <tr>
                        <th className="px-5 py-3 text-left">Employee</th>
                        <th className="px-5 py-3 text-left">Code</th>
                        <th className="px-5 py-3 text-left">Department</th>
                        <th className="px-5 py-3 text-right">Present</th>
                        <th className="px-5 py-3 text-right">Absent</th>
                        <th className="px-5 py-3 text-right">Late</th>
                        <th className="px-5 py-3 text-right">Excused</th>
                        <th className="px-5 py-3 text-right">Payroll hours</th>
                    </tr>
                </Thead>
                <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={8}>
                                <EmptyState
                                    title="Nothing to report"
                                    hint="No active employees match this filter."
                                />
                            </td>
                        </tr>
                    )}
                    {rows.map((row) => (
                        <tr key={row.employee_id} className="transition-colors duration-150 hover:bg-slate-50/70">
                            <td className="px-5 py-3 font-medium text-slate-900">{row.name}</td>
                            <td className="px-5 py-3 font-mono text-xs text-slate-600">{row.employee_code}</td>
                            <td className="px-5 py-3 text-slate-600">{row.department || '—'}</td>
                            <td className="px-5 py-3 text-right tabular-nums text-slate-700">{row.present_days}</td>
                            <td className="px-5 py-3 text-right tabular-nums text-slate-700">{row.absent_days}</td>
                            <td className="px-5 py-3 text-right tabular-nums text-slate-700">{row.late_days}</td>
                            <td className="px-5 py-3 text-right tabular-nums text-slate-700">{row.excused_days}</td>
                            <td className="px-5 py-3 text-right font-semibold tabular-nums text-slate-900">
                                {hours(row.total_payroll_hours)}
                            </td>
                        </tr>
                    ))}
                    {rows.length > 0 && (
                        <tr className="bg-slate-50/60">
                            <td className="px-5 py-3 font-semibold text-slate-900" colSpan={7}>
                                {report.totals.employees.toLocaleString()} employees
                            </td>
                            <td className="px-5 py-3 text-right font-semibold tabular-nums text-slate-900">
                                {hours(report.totals.payroll_hours)}
                            </td>
                        </tr>
                    )}
                </tbody>
            </TableShell>
        </div>
    );
}
