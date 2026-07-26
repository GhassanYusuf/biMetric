// Dashboard building blocks shared by the mobile and desktop presentations.
import { ELEVATION } from '../../shared/theme';

/*
| The "Quick Overview" hero: the one place on the surface that carries colour as
| a field rather than an accent. Frosted tiles float on a violet→sky→amber wash.
|
| The glass is a specific effect here, not decoration — it only works because
| there is a real gradient beneath it to refract. Tiles declare elevation with a
| light edge (no shadow), since a shadow over a gradient reads as dirt.
| Secondary text is tinted from the wash rather than grey, so it holds contrast
| across all three hues.
*/
export function QuickOverview({ tiles }) {
    return (
        <div className={`relative overflow-hidden rounded-2xl p-5 ${ELEVATION.card}`}>
            <div className="absolute inset-0 bg-gradient-to-r from-violet-200 via-sky-100 to-amber-100" />
            <div className="pointer-events-none absolute -right-24 -top-32 h-80 w-80 rounded-full bg-gradient-to-br from-sky-300/60 to-transparent blur-2xl" />
            <div className="pointer-events-none absolute -bottom-28 -left-20 h-72 w-72 rounded-full bg-gradient-to-tr from-violet-300/50 to-transparent blur-2xl" />

            <div className="relative">
                <h2 className="text-base font-semibold tracking-[-0.01em] text-slate-900">Quick Overview</h2>
                <p className="text-sm text-violet-950/60">Your workforce at a glance</p>
                <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    {tiles.map((t, i) => (
                        <HeroTile key={t.label} {...t} highlight={i === 0} />
                    ))}
                </div>
            </div>
        </div>
    );
}

function HeroTile({ value, sub, label, highlight }) {
    return (
        <div
            className={`rounded-xl p-4 backdrop-blur-md transition-colors duration-150 ${
                highlight
                    ? 'border border-white/90 bg-white/70 ring-1 ring-violet-400/40'
                    : 'border border-white/60 bg-white/45 hover:bg-white/60'
            }`}
        >
            <div className="flex items-baseline gap-1.5">
                <span className="text-2xl font-bold tabular-nums tracking-[-0.02em] text-slate-900">{value}</span>
                {sub && <span className="text-xs text-violet-950/60">{sub}</span>}
            </div>
            <div className="mt-1 text-sm font-medium text-slate-800">{label}</div>
        </div>
    );
}

// Maps the API summary into the hero tiles.
export function buildTiles(kpis) {
    return [
        { value: kpis.employees.total, sub: `${kpis.employees.active} active`, label: 'Employees' },
        { value: kpis.today.present, sub: `of ${kpis.employees.active}`, label: 'Present today' },
        { value: kpis.today.absent, label: 'Absent today' },
        { value: kpis.today.punches, label: 'Punches today' },
        { value: kpis.devices.active, sub: kpis.devices.errored > 0 ? `${kpis.devices.errored} errors` : undefined, label: 'Devices' },
    ];
}

// Turns an API trend object ({pct, direction}) into KpiCard props. A null pct
// means there was no baseline to compare against, so we render no chip at all
// rather than inventing a number.
export function trendProps(trend) {
    if (!trend || trend.pct === null || trend.pct === undefined) return {};
    return {
        trend: `${Math.abs(trend.pct)}%`,
        trendDirection: trend.direction,
    };
}

export function RecentTable({ rows }) {
    if (rows.length === 0) {
        return (
            <p className="py-10 text-center text-sm text-slate-500">
                No punches yet. Sync a device to pull attendance in.
            </p>
        );
    }

    return (
        <ul className="divide-y divide-slate-100">
            {rows.map((r) => (
                <li key={r.id} className="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-violet-50 text-[11px] font-bold text-violet-700">
                        {initials(r.employee_name)}
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-medium text-slate-800">
                            {r.employee_name || 'Unmatched punch'}
                        </span>
                        <span className="block truncate text-xs text-slate-500">{r.device_name || '—'}</span>
                    </span>
                    <span className="shrink-0 text-xs font-semibold tabular-nums text-slate-600">
                        {r.punched_at
                            ? new Date(r.punched_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
                            : '—'}
                    </span>
                </li>
            ))}
        </ul>
    );
}

function initials(name) {
    if (!name) return '?';
    return name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
}
