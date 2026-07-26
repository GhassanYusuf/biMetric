import { KpiCard, SectionCard } from '../../shared/ui';
import { Donut, Legend, TrendBars } from '../../shared/charts';
import { STATUS_COLORS, RANGES } from '../../shared/theme';
import { QuickOverview, RecentTable, buildTiles, trendProps } from './parts';

export default function DashboardDesktop({ data, days }) {
    const { kpis, punch_series: series = [], recent_activity: recent = [] } = data;
    const trends = kpis.trends || {};
    const rangeLabel = (RANGES.find((r) => r.days === days) ?? RANGES[0]).label.toLowerCase();

    const tracked = kpis.today.present + kpis.today.absent;
    const split = [
        { label: 'Present', value: kpis.today.present, color: STATUS_COLORS.present },
        { label: 'Absent', value: kpis.today.absent, color: STATUS_COLORS.absent },
    ];

    return (
        <div className="space-y-5">
            <QuickOverview tiles={buildTiles(kpis)} />

            <div className="grid grid-cols-3 items-start gap-5">
                <SectionCard title="Attendance today" subtitle={`${kpis.employees.active} active employees`} className="col-span-1">
                    <div className="flex flex-col items-center gap-5">
                        <Donut
                            segments={tracked === 0 ? [] : split}
                            centerValue={tracked}
                            centerLabel="tracked"
                        />
                        <div className="w-full">
                            <Legend items={split} />
                        </div>
                    </div>
                </SectionCard>

                <div className="col-span-2 grid grid-cols-2 gap-5">
                    <KpiCard value={kpis.today.present} label="Present" caption="Checked in today" {...trendProps(trends.present)} />
                    <KpiCard value={kpis.today.absent} label="Absent" caption="Not yet checked in" {...trendProps(trends.absent)} />
                    <KpiCard value={kpis.today.punches} label="Punches" caption="Recorded today" {...trendProps(trends.punches)} />
                    <KpiCard
                        value={kpis.devices.active}
                        label="Devices online"
                        caption={kpis.devices.errored > 0 ? `${kpis.devices.errored} reporting errors` : 'All reporting cleanly'}
                    />
                </div>
            </div>

            <div className="grid grid-cols-3 items-start gap-5">
                <SectionCard
                    title="Punch trend"
                    subtitle={`Daily totals, ${rangeLabel}`}
                    className="col-span-2"
                >
                    <TrendBars data={series} />
                </SectionCard>

                <SectionCard title="Recent activity" subtitle="Newest punches first" className="col-span-1">
                    <RecentTable rows={recent.slice(0, 7)} />
                </SectionCard>
            </div>
        </div>
    );
}
