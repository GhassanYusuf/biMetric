import { Link } from 'react-router-dom';
import { InlineAlert } from '../../../shared/ui';
import ReportHeader from './ReportHeader';
import { Alerts, ArrivalDrift, IdentityCard, KpiTiles, MissionLog, MonthlyTrend, Orbit, OutcomeSplit, Panel, WeekdayBars, WhereHow, YearCalendar } from './charts';
import { rangeLabel } from './model';

// Rule 5: the same sections, data and hover readouts as the desktop, stacked.
export default function EmployeeReportMobile({ report, derived, period, onPeriod, refreshing, sample, failure, exporting, onExport }) {
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
        <div className="flex flex-col gap-4">
            <ReportHeader report={report} period={period} onPeriod={onPeriod} refreshing={refreshing} sample={sample} exporting={exporting} onExport={onExport} compact />
            <div className="flex flex-col gap-4">
                <IdentityCard employee={report.employee} enrollment={report.enrollment} lastSeen={derived.lastSeen} shift={shift} />
                <Panel big title="The orbit" subtitle={`The last ${derived.orbitDays.length} work days, one ring each, on a 24-hour clock. Innermost is the oldest.`}>
                    <Orbit days={derived.orbitDays} shift={shift} stats={stats} caption="Radial chart of daily presence" />
                </Panel>
                <KpiTiles kpis={derived.kpis} className="grid-cols-2" />
                <ArrivalDrift days={derived.periodTracked} shift={shift} />
                <OutcomeSplit split={derived.split} workDays={stats.work_days} subtitle={`${stats.work_days} work days, ${range}`} />
                <YearCalendar calendar={derived.calendar} shift={shift} />
                <WeekdayBars data={derived.weekday} />
                <MonthlyTrend months={derived.months} />
                <WhereHow where={derived.where} />
                <MissionLog log={derived.log} />
                <Alerts alerts={derived.alerts} />
            </div>
        </div>
    );
}
