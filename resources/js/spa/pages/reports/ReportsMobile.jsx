import { useState } from 'react';
import { ActionMenu, Badge, Button, Card, EmptyState, PageHeader, SelectField, TextField } from '../../shared/ui';
import { ExportRuns, hours, rangeLabel } from './parts';

// Mobile payroll summary: the same report and the same export, with the filters
// collapsed behind a toggle and each employee as a card.
export default function ReportsMobile({ report, departments, filters, onFilter, loading, exporting, onExport, runs, downloading, deleting, onDownloadRun, onDeleteRun, allEmployeesItems }) {
    const [filtersOpen, setFiltersOpen] = useState(false);
    const rows = report?.rows ?? [];
    const activeCount = [filters.from, filters.to, filters.department_id].filter(Boolean).length;

    function set(key, value) {
        onFilter({ ...filters, [key]: value });
    }

    return (
        <div className="space-y-4">
            <PageHeader title="Payroll summary" compact>
                <Button
                    className="min-h-[38px] px-3 text-sm"
                    loading={exporting === 'report'}
                    disabled={!!exporting}
                    onClick={() => onExport('report')}
                >
                    {exporting === 'report' ? 'Preparing…' : 'Report'}
                </Button>
                <ActionMenu
                    label="All employees"
                    className="min-h-[38px] px-3 text-sm"
                    items={allEmployeesItems}
                />
                <Button
                    variant="secondary"
                    className="min-h-[38px] px-3 text-sm"
                    loading={exporting === 'payroll'}
                    disabled={!!exporting}
                    onClick={() => onExport('payroll')}
                >
                    {exporting === 'payroll' ? 'Preparing…' : 'Payroll'}
                </Button>
                <Button
                    variant="secondary"
                    className="min-h-[38px] px-3 text-sm"
                    loading={exporting === 'statement'}
                    disabled={!!exporting}
                    onClick={() => onExport('statement')}
                >
                    {exporting === 'statement' ? 'Preparing…' : 'Statement'}
                </Button>
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

            <p className="text-xs text-slate-500">
                {rangeLabel(report)} · {(report?.totals?.employees ?? 0).toLocaleString()} active employees ·{' '}
                {hours(report?.totals?.payroll_hours)} total
            </p>

            {filtersOpen && (
                <Card className="space-y-3 p-4">
                    <div className="grid grid-cols-2 gap-3">
                        <TextField label="From" type="date" value={filters.from} onChange={(e) => set('from', e.target.value)} />
                        <TextField label="To" type="date" value={filters.to} onChange={(e) => set('to', e.target.value)} />
                    </div>
                    <SelectField label="Department" value={filters.department_id} onChange={(e) => set('department_id', e.target.value)}>
                        <option value="">All departments</option>
                        {departments.map((d) => (
                            <option key={d.id} value={d.id}>{d.name}</option>
                        ))}
                    </SelectField>
                    {activeCount > 0 && (
                        <Button variant="secondary" className="w-full" onClick={() => onFilter({ from: '', to: '', department_id: '' })}>
                            Clear filters
                        </Button>
                    )}
                </Card>
            )}

            <ExportRuns runs={runs} downloading={downloading} deleting={deleting} onDownload={onDownloadRun} onDelete={onDeleteRun} />

            {rows.length === 0 ? (
                <Card>
                    <EmptyState title="Nothing to report" hint="No active employees match this filter." />
                </Card>
            ) : (
                <div className="space-y-2" aria-busy={loading}>
                    {rows.map((row) => (
                        <Card key={row.employee_id} className="p-3.5">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="truncate font-medium text-slate-900">{row.name}</div>
                                    <div className="truncate text-xs text-slate-500">
                                        <span className="font-mono">{row.employee_code}</span>
                                        {row.department ? ` · ${row.department}` : ''}
                                    </div>
                                </div>
                                <div className="shrink-0 text-right text-sm font-semibold tabular-nums text-slate-900">
                                    {hours(row.total_payroll_hours)}
                                </div>
                            </div>
                            <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t border-slate-100 pt-3 text-xs text-slate-600">
                                <span>{row.present_days} present</span>
                                <span>{row.absent_days} absent</span>
                                <span>{row.late_days} late</span>
                                <span>{row.excused_days} excused</span>
                            </div>
                        </Card>
                    ))}
                </div>
            )}
        </div>
    );
}
