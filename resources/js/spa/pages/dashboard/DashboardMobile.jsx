import { KpiCard, SectionCard } from '../../shared/ui';
import { Donut, Legend, TrendBars } from '../../shared/charts';
import { STATUS_COLORS, RANGES } from '../../shared/theme';
import { QuickOverview, RecentTable, buildTiles, trendProps } from './parts';

export default function DashboardMobile({ data, days }) {
    const { kpis, punch_series: series = [], recent_activity: recent = [] } = data;
    const trends = kpis.trends || {};
    const rangeLabel = (RANGES.find((r) => r.days === days) ?? RANGES[0]).label.toLowerCase();

    const tracked = kpis.today.present + kpis.today.absent;
    const split = [
        { label: 'Present', value: kpis.today.present, color: STATUS_COLORS.present },
        { label: 'Absent', value: kpis.today.absent, color: STATUS_COLORS.absent },
    ];

    return (
        <div className="space-y-4">
            <QuickOverview tiles={buildTiles(kpis)} />

            <div className="grid grid-cols-2 gap-3">
                <KpiCard value={kpis.today.present} label="Present" caption="Checked in" {...trendProps(trends.present)} />
                <KpiCard value={kpis.today.absent} label="Absent" caption="Not in yet" {...trendProps(trends.absent)} />
            </div>

            <SectionCard title="Attendance today" subtitle={`${kpis.employees.active} active employees`}>
                <div className="flex flex-col items-center gap-5">
                    <Donut segments={tracked === 0 ? [] : split} centerValue={tracked} centerLabel="tracked" />
                    <div className="w-full"><Legend items={split} /></div>
                </div>
            </SectionCard>

            <SectionCard title="Punch trend" subtitle={`Daily totals, ${rangeLabel}`}>
                <TrendBars data={series} height={150} />
            </SectionCard>

            <SectionCard title="Recent activity" subtitle="Newest punches first">
                <RecentTable rows={recent.slice(0, 5)} />
            </SectionCard>
        </div>
    );
}
