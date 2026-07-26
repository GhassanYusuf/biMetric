// Self-contained SVG charts — no external library, so the bundle stays lean.
// Every value shown is real; nothing is padded to look fuller than it is.
//
// The guiding rule: if you have to hover to get the gist, the chart failed. So
// totals and axis labels are always visible, and hover only adds precision.

import { useId, useState } from 'react';

/**
 * Donut / ring chart. segments: [{ label, value, color }].
 * An empty `segments` array renders the track alone — an honest "nothing yet"
 * rather than a full ring in placeholder grey that reads as real data.
 */
export function Donut({ segments = [], size = 168, thickness = 22, centerValue, centerLabel }) {
    const total = segments.reduce((s, x) => s + x.value, 0);
    const r = (size - thickness) / 2;
    const c = 2 * Math.PI * r;
    const drawn = segments.filter((s) => s.value > 0);
    let offset = 0;

    return (
        <div className="relative inline-flex items-center justify-center">
            <svg
                width={size}
                height={size}
                viewBox={`0 0 ${size} ${size}`}
                className="-rotate-90"
                role="img"
                aria-label={centerLabel ? `${centerValue} ${centerLabel}` : 'Chart'}
            >
                <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="#eef0f4" strokeWidth={thickness} />
                {total > 0 && drawn.map((seg, i) => {
                    const len = (seg.value / total) * c;
                    const el = (
                        <circle
                            key={i}
                            cx={size / 2}
                            cy={size / 2}
                            r={r}
                            fill="none"
                            stroke={seg.color}
                            strokeWidth={thickness}
                            strokeDasharray={`${len} ${c - len}`}
                            strokeDashoffset={-offset}
                            // Round caps on a single full-circle segment leave a
                            // visible notch; only round when there are joins.
                            strokeLinecap={drawn.length > 1 ? 'round' : 'butt'}
                        />
                    );
                    offset += len;
                    return el;
                })}
            </svg>
            {centerValue !== undefined && (
                <div className="absolute flex flex-col items-center">
                    <span className="text-2xl font-bold tabular-nums tracking-[-0.02em] text-slate-900">{centerValue}</span>
                    {centerLabel && <span className="text-xs text-slate-500">{centerLabel}</span>}
                </div>
            )}
        </div>
    );
}

// Legend doubles as the readout: value and share, so the donut needs no hover.
export function Legend({ items }) {
    const total = items.reduce((s, i) => s + (i.value ?? 0), 0);

    return (
        <ul className="space-y-2">
            {items.map((it) => (
                <li key={it.label} className="flex items-center gap-2.5 text-sm">
                    <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: it.color }} aria-hidden="true" />
                    <span className="min-w-0 flex-1 truncate text-slate-600">{it.label}</span>
                    {it.value !== undefined && (
                        <>
                            <span className="tabular-nums font-semibold text-slate-800">{it.value}</span>
                            {total > 0 && (
                                <span className="w-9 text-right text-xs tabular-nums text-slate-500">
                                    {Math.round((it.value / total) * 100)}%
                                </span>
                            )}
                        </>
                    )}
                </li>
            ))}
        </ul>
    );
}

/**
 * Daily trend bars. data: [{ label, date, punches }].
 *
 * Bar width and label density adapt to the bucket count — 90 bars cannot each
 * carry a label, so only every Nth is drawn while every bar stays hoverable.
 * Total and peak sit in the header so the shape reads without interaction.
 */
export function TrendBars({ data = [], height = 180 }) {
    const [hover, setHover] = useState(null);
    const gradientId = useId();

    if (data.length === 0) {
        return <div className="py-14 text-center text-sm text-slate-500">No punches in this range.</div>;
    }

    const max = Math.max(1, ...data.map((d) => d.punches));
    const total = data.reduce((s, d) => s + d.punches, 0);
    const peak = data.reduce((best, d) => (d.punches > best.punches ? d : best), data[0]);

    // Aim for ~8 visible labels regardless of range length.
    const labelEvery = Math.max(1, Math.ceil(data.length / 8));
    const dense = data.length > 14;
    const plotHeight = height - 26;

    return (
        <div>
            <div className="mb-4 flex flex-wrap items-baseline gap-x-5 gap-y-1">
                <span className="text-2xl font-bold tabular-nums tracking-[-0.02em] text-slate-900">
                    {total.toLocaleString()}
                </span>
                <span className="text-sm text-slate-500">punches total</span>
                {total > 0 && (
                    <span className="ml-auto text-xs text-slate-500">
                        Peak <span className="font-semibold text-slate-700">{peak.punches}</span> on {peak.label}
                    </span>
                )}
            </div>

            <svg width="0" height="0" aria-hidden="true" className="absolute">
                <defs>
                    <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="#a78bfa" />
                        <stop offset="100%" stopColor="#ddd6fe" />
                    </linearGradient>
                </defs>
            </svg>

            <div className={`flex items-end ${dense ? 'gap-[2px]' : 'gap-2'}`} style={{ height }}>
                {data.map((d, i) => {
                    const barHeight = d.punches === 0 ? 3 : Math.max(4, (d.punches / max) * plotHeight);
                    const isHover = hover === i;
                    const isPeak = total > 0 && d.punches === peak.punches;

                    return (
                        <div
                            key={d.date}
                            className="relative flex h-full flex-1 flex-col items-center justify-end gap-2"
                            onMouseEnter={() => setHover(i)}
                            onMouseLeave={() => setHover(null)}
                        >
                            <div
                                className={`w-full rounded-full transition-[background-color] duration-150 ${dense ? 'max-w-[6px]' : 'max-w-[10px]'}`}
                                style={{
                                    height: barHeight,
                                    background: d.punches === 0
                                        ? '#e2e8f0'
                                        : isHover || isPeak
                                            ? '#7c3aed'
                                            : 'linear-gradient(180deg, #a78bfa, #ddd6fe)',
                                }}
                            />
                            <span
                                className={`h-3.5 shrink-0 text-[11px] tabular-nums text-slate-500 ${
                                    data.length <= 7 || i % labelEvery === 0 ? '' : 'invisible'
                                }`}
                            >
                                {dense ? d.label.split(' ')[0] : d.label}
                            </span>

                            {isHover && (
                                <div className="pointer-events-none absolute -top-1 left-1/2 z-10 -translate-x-1/2 whitespace-nowrap rounded-lg bg-slate-900 px-2 py-1 text-[11px] font-medium text-white">
                                    {d.punches} on {d.label}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
