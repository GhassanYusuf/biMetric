import { Link } from 'react-router-dom';
import { InlineAlert } from '../../../shared/ui';
import ReportHeader from './ReportHeader';
import { Alerts, ArrivalDrift, IdentityCard, KpiTiles, MissionLog, MonthlyTrend, Orbit, OutcomeSplit, Panel, WeekdayBars, WhereHow, YearCalendar } from './charts';
import { clock, rangeLabel } from './model';

export default function EmployeeReportDesktop({ report, derived, period, onPeriod, refreshing, sample, failure, exporting, onExport }) {
    if (failure) {
        return (
            <div className="flex flex-col gap-4">
                <Link to="/employees" className="self-start text-sm font-medium text-violet-700 hover:text-violet-900">← Employees</Link>
                <InlineAlert tone="error">{failure}</InlineAlert>
            </div>
        );
    }
    const { stats, shift } = report;
    const range = rangeLabel(report.period.from, report.period.to);

    return (
        <div className="flex flex-col gap-5">
            <ReportHeader report={report} period={period} onPeriod={onPeriod} refreshing={refreshing} sample={sample} exporting={exporting} onExport={onExport} />
            <div className="flex flex-col gap-5">
                <div className="grid gap-5 xl:grid-cols-[440px_minmax(0,1fr)]">
                    <IdentityCard employee={report.employee} enrollment={report.enrollment} lastSeen={derived.lastSeen} shift={shift} />
                    <Panel
                        big
                        title="The orbit"
                        subtitle={`Each of the last ${derived.orbitDays.length} work days is one ring on a 24-hour clock — innermost is the oldest. The arc runs from first punch to last; the tinted band is the ${clock(shift.start_minutes)}–${clock(shift.end_minutes)} shift.`}
                        aside={<span className="font-mono text-xs text-[#8B91B5]">{range}</span>}
                    >
                        <Orbit days={derived.orbitDays} shift={shift} stats={stats} caption="Radial chart of daily presence" />
                    </Panel>
                </div>
                <KpiTiles kpis={derived.kpis} className="grid-cols-2 lg:grid-cols-3 xl:grid-cols-5" />
                <div className="grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <ArrivalDrift days={derived.periodTracked} shift={shift} />
                    <OutcomeSplit split={derived.split} workDays={stats.work_days} subtitle={`${stats.work_days} work days, ${range}`} />
                </div>
                <YearCalendar calendar={derived.calendar} shift={shift} />
                <div className="grid gap-5 lg:grid-cols-3">
                    <WeekdayBars data={derived.weekday} />
                    <MonthlyTrend months={derived.months} />
                    <WhereHow where={derived.where} />
                </div>
                <div className="grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <MissionLog log={derived.log} twoColumns={derived.log.length > 5} />
                    <Alerts alerts={derived.alerts} />
                </div>
            </div>
        </div>
    );
}
