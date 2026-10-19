import { Link } from 'react-router-dom';
import { Button, InlineAlert, PageHeader, Spinner } from '../../../shared/ui';
import { FOCUS } from '../../../shared/theme';
import { PERIODS, rangeLabel } from './model';
import { useClockFormat } from '../../../shared/clockFormat';

/*
| The report's header and period switch, shared by both presentations so they
| cannot drift (rule 5). Light, like every other page header; the dark "space"
| surface starts below it.
*/
export function PeriodSwitch({ period, onPeriod }) {
    return (
        <div role="group" aria-label="Period" className="flex gap-1 rounded-xl bg-white p-1 ring-1 ring-slate-200">
            {PERIODS.map((p) => (
                <button
                    key={p.days}
                    type="button"
                    aria-pressed={period === p.days}
                    onClick={() => onPeriod(p.days)}
                    className={`min-h-[40px] rounded-lg px-3.5 text-sm font-semibold transition-colors ${period === p.days ? 'bg-violet-600 text-white' : 'text-slate-600 hover:bg-slate-100'} ${FOCUS}`}
                >
                    {p.label}
                </button>
            ))}
        </div>
    );
}

// 12-hour by default; 24 only when switched here. Same segmented look as the
// period switch beside it, so the header reads as one row of controls.
export function ClockSwitch() {
    const [format, setFormat] = useClockFormat();
    return (
        <div role="group" aria-label="Time format" className="flex gap-1 rounded-xl bg-white p-1 ring-1 ring-slate-200">
            {[['12', '12h'], ['24', '24h']].map(([value, label]) => (
                <button
                    key={value}
                    type="button"
                    aria-pressed={format === value}
                    aria-label={`${value}-hour clock`}
                    onClick={() => setFormat(value)}
                    className={`min-h-[40px] rounded-lg px-3 text-sm font-semibold transition-colors ${format === value ? 'bg-violet-600 text-white' : 'text-slate-600 hover:bg-slate-100'} ${FOCUS}`}
                >
                    {label}
                </button>
            ))}
        </div>
    );
}

export default function ReportHeader({ report, period, onPeriod, refreshing, sample, exporting = false, onExport = null, compact = false }) {
    return (
        <div className="flex flex-col gap-3">
            <Link to="/employees" className={`self-start rounded text-sm font-medium text-violet-700 hover:text-violet-900 ${FOCUS}`}>← Employees</Link>
            <PageHeader
                compact={compact}
                title={report ? `${report.employee.name} — report` : 'Employee report'}
                subtitle={report ? rangeLabel(report.period.from, report.period.to) : undefined}
            >
                {refreshing && <span className="text-violet-600"><Spinner className="h-4 w-4" /></span>}
                <ClockSwitch />
                <PeriodSwitch period={period} onPeriod={onPeriod} />
                {onExport && report && (
                    <Button variant="secondary" className="min-h-[40px]" loading={exporting} disabled={exporting} onClick={onExport}>
                        {exporting ? 'Preparing…' : compact ? 'PDF' : 'Export PDF'}
                    </Button>
                )}
            </PageHeader>
            {sample && (
                <InlineAlert tone="info">
                    Sample data — this is not a real employee. It shows what the report looks like; open <span className="font-semibold">Report</span> on
                    any employee on the Employees screen for their real figures.
                </InlineAlert>
            )}
            {report && !sample && report.tracking_from === null && (
                <InlineAlert tone="info">
                    No punches on any terminal for this person yet, and no hire date — there is nothing to report until they clock in.
                </InlineAlert>
            )}
        </div>
    );
}
