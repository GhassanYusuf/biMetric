import { useState } from 'react';
import { ELEVATION } from '../../../shared/theme';
import { ACCENT, OUTCOME, OUTCOMES, clock, dayLabel, describeDay } from './model';
import { formatClockTick } from '../../../shared/clockFormat';

/*
| The employee report's charts ("Orbit", 2026-09-27). Plain SVG like shared/charts.jsx
| — no chart library — on the dashboard's own white cards, slate ink and attendance
| colours (owner, 2026-09-27: "use the dashboard colors styling", not a dark theme).
|
| Every chart takes a viewBox and width="100%", so the desktop and mobile
| presentations use the SAME components and differ only in layout (rule 5).
|
| Text wears text colours, never a series colour; a coloured mark beside it carries
| the identity. Hover only adds precision — every chart states its totals up front.
*/
export const INK = { primary: '#0f172a', secondary: '#475569', muted: '#64748b', hair: '#f1f5f9', track: '#e2e8f0', surface: '#ffffff', raised: '#f8fafc' };

export function Panel({ title, subtitle, aside, children, className = '', big = false }) {
    return (
        <section className={`flex min-w-0 flex-col gap-4 rounded-2xl bg-white p-5 md:p-6 ${ELEVATION.card} ${className}`}>
            {(title || aside) && (
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        {title && <h2 className={`font-semibold tracking-[-0.01em] text-slate-900 ${big ? 'text-lg' : 'text-base'}`}>{title}</h2>}
                        {subtitle && <p className="mt-1 text-sm text-slate-600">{subtitle}</p>}
                    </div>
                    {aside}
                </div>
            )}
            {children}
        </section>
    );
}

export function Readout({ children }) {
    return <span className="rounded-lg bg-slate-100 px-2.5 py-1.5 font-mono text-xs text-slate-700 tabular-nums">{children}</span>;
}

function Swatch({ color, dashed = false }) {
    return dashed
        ? <span className="inline-block w-3.5 shrink-0 border-t-2 border-dashed" style={{ borderColor: color }} aria-hidden="true" />
        : <span className="inline-block h-2.5 w-2.5 shrink-0 rounded-[3px]" style={{ background: color }} aria-hidden="true" />;
}

export function OutcomeLegend({ extra = null }) {
    return (
        <ul className="flex flex-wrap gap-x-5 gap-y-2">
            {OUTCOMES.map((o) => (
                <li key={o.key} className="flex items-center gap-2 text-[13px] text-slate-600">
                    <Swatch color={o.color} dashed={o.key === 'missed_punch_out'} />{o.label}
                </li>
            ))}
            {extra}
        </ul>
    );
}

// ── Orbit ───────────────────────────────────────────────────────────────────
// Each work day is one ring on a 24-hour clock with noon at the top; the arc runs
// from the first punch to the last. Innermost ring is the oldest day.
export function Orbit({ days, shift, stats, caption }) {
    const [hover, setHover] = useState(null);
    const size = 640;
    const c = size / 2;
    const R0 = 112;
    const R1 = 262;
    const n = days.length;
    const step = n > 1 ? (R1 - R0) / (n - 1) : 0;
    const width = Math.max(Math.min(step - 3, 6), 2);

    const at = (r, m) => {
        const a = ((m / 60 - 12) / 24) * 2 * Math.PI;
        return [c + r * Math.sin(a), c - r * Math.cos(a)];
    };
    const arc = (r, m0, m1) => {
        const [x0, y0] = at(r, m0);
        const [x1, y1] = at(r, m1);
        return `M${x0.toFixed(1)} ${y0.toFixed(1)} A${r} ${r} 0 ${m1 - m0 > 720 ? 1 : 0} 1 ${x1.toFixed(1)} ${y1.toFixed(1)}`;
    };
    const { start_minutes: s0, end_minutes: s1 } = shift;
    const [o0, o1, i1, i0] = [at(R1 + 8, s0), at(R1 + 8, s1), at(R0 - 8, s1), at(R0 - 8, s0)];
    const band = `M${o0.join(' ')} A${R1 + 8} ${R1 + 8} 0 0 1 ${o1.join(' ')} L${i1.join(' ')} A${R0 - 8} ${R0 - 8} 0 0 0 ${i0.join(' ')} Z`;

    const focus = hover != null ? days[hover] : null;
    const desc = describeDay(focus, shift);

    return (
        <div className="flex flex-col gap-3">
            <OutcomeLegend />
            <svg viewBox={`0 0 ${size} ${size}`} width="100%" className="mx-auto max-w-[640px]" role="img" aria-label={caption}>
                <path d={band} fill={ACCENT} opacity="0.07" />
                <circle cx={c} cy={c} r={R1 + 12} fill="none" stroke="#e2e8f0" />
                {Array.from({ length: 8 }, (_, i) => {
                    const m = i * 180;
                    const [x1, y1] = at(R1 + 12, m);
                    const [x2, y2] = at(R1 + 18, m);
                    const [lx, ly] = at(R1 + 36, m);
                    return (
                        <g key={m}>
                            <line x1={x1} y1={y1} x2={x2} y2={y2} stroke="#cbd5e1" strokeWidth="1.5" />
                            <text x={lx} y={ly + 4} fill={INK.muted} fontSize="12" textAnchor="middle" className="font-mono">{formatClockTick(m)}</text>
                        </g>
                    );
                })}
                {days.map((d, i) => {
                    const r = n > 1 ? R0 + i * step : (R0 + R1) / 2;
                    const on = hover === i;
                    const op = hover != null && !on ? 0.35 : 1;
                    const w = on ? width + 2 : width;
                    let mark;
                    if (d.outcome === 'absent') {
                        mark = <path d={arc(r, s0, s1)} fill="none" stroke={OUTCOME.absent.color} strokeWidth="1.5" strokeDasharray="2 5" opacity={op} />;
                    } else if (d.outcome === 'excused') {
                        mark = <path d={arc(r, s0, s1)} fill="none" stroke={OUTCOME.excused.color} strokeWidth={w} strokeLinecap="round" opacity={op} />;
                    } else if (d.outcome === 'missed_punch_out') {
                        const [dx, dy] = at(r, d.first_minutes);
                        mark = (
                            <g opacity={op}>
                                <path d={arc(r, d.first_minutes, Math.max(s1, d.first_minutes + 30))} fill="none" stroke={OUTCOME.missed_punch_out.color} strokeWidth="2" strokeDasharray="4 4" />
                                <circle cx={dx} cy={dy} r="3.4" fill={OUTCOME.missed_punch_out.color} />
                            </g>
                        );
                    } else {
                        mark = <path d={arc(r, d.first_minutes, d.last_minutes)} fill="none" stroke={OUTCOME[d.outcome].color} strokeWidth={w} strokeLinecap="round" opacity={op} />;
                    }
                    return (
                        <g key={d.date}>
                            <circle cx={c} cy={c} r={r} fill="none" stroke={on ? '#cbd5e1' : '#f1f5f9'} />
                            {mark}
                            <circle
                                cx={c} cy={c} r={r} fill="none" stroke="transparent" strokeWidth={Math.max(step, 6)}
                                style={{ pointerEvents: 'stroke', cursor: 'crosshair' }}
                                onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)}
                            />
                        </g>
                    );
                })}
                <circle cx={c} cy={c} r="92" fill="#f8fafc" stroke="#e2e8f0" />
                {focus ? (
                    <>
                        <text x={c} y={c - 34} fill={INK.muted} fontSize="11" fontWeight="600" letterSpacing="1.3" textAnchor="middle">{dayLabel(focus.date).toUpperCase()}</text>
                        <text x={c} y={c + 4} fill={INK.primary} fontSize="24" textAnchor="middle" className="font-mono">{desc.headline}</text>
                        <text x={c} y={c + 30} fill={INK.secondary} fontSize="13" textAnchor="middle">{desc.detail}</text>
                        <text x={c} y={c + 52} fill={INK.muted} fontSize="12" textAnchor="middle">{focus.device ?? ''}</text>
                    </>
                ) : (
                    <>
                        <text x={c} y={c - 34} fill={INK.muted} fontSize="11" fontWeight="600" letterSpacing="1.3" textAnchor="middle">PAYROLL HOURS</text>
                        <text x={c} y={c + 10} fill={INK.primary} fontSize="40" textAnchor="middle" className="font-mono">{stats.payroll_hours.toFixed(1)}</text>
                        <text x={c} y={c + 34} fill={INK.secondary} fontSize="13" textAnchor="middle">{stats.present_days} of {stats.expected_days} expected days</text>
                        <text x={c} y={c + 54} fill={INK.muted} fontSize="12" textAnchor="middle">
                            {stats.missed_punch_outs ? `${stats.missed_punch_outs} day${stats.missed_punch_outs === 1 ? '' : 's'} not counted — no punch-out` : 'Every day closed'}
                        </text>
                    </>
                )}
            </svg>
        </div>
    );
}

// ── Arrival drift ───────────────────────────────────────────────────────────
export function ArrivalDrift({ days, shift }) {
    const [hover, setHover] = useState(null);
    const W = 820;
    const H = 290;
    const L = 56;
    const R = 16;
    const T = 16;
    const B = 38;
    const present = days.filter((d) => d.first_minutes != null).map((d) => d.first_minutes);
    const lo = Math.floor((Math.min(shift.start_minutes - 60, ...present) - 5) / 30) * 30;
    const hi = Math.ceil((Math.max(shift.late_after_minutes + 45, ...present) + 5) / 30) * 30;
    const y = (m) => T + ((hi - m) / (hi - lo)) * (H - T - B);
    const n = Math.max(days.length, 1);
    const bw = (W - L - R) / n;
    const x = (i) => L + (i + 0.5) * bw;
    const dot = n > 60 ? 3 : n > 31 ? 4 : 6;
    const grid = [];
    for (let m = lo; m <= hi; m += 30) grid.push(m);
    const labelEvery = Math.ceil(n / 10);
    const line = days.map((d, i) => (d.first_minutes != null ? `${x(i).toFixed(1)},${y(d.first_minutes).toFixed(1)}` : null)).filter(Boolean).join(' ');
    const focus = hover != null ? days[hover] : null;

    return (
        <Panel
            title="Arrival drift"
            subtitle={`First punch of each work day against the ${clock(shift.start_minutes)} shift start (late after ${clock(shift.late_after_minutes)})`}
            aside={<Readout>{focus ? `${dayLabel(focus.date)} · ${focus.first_minutes != null ? `in ${clock(focus.first_minutes)}${focus.first_minutes > shift.late_after_minutes ? ` · ${Math.round(focus.first_minutes - shift.start_minutes)} min late` : ' · on time'}` : OUTCOME[focus.outcome].label}` : 'Hover a day'}</Readout>}
        >
            <svg viewBox={`0 0 ${W} ${H}`} width="100%" role="img" aria-label="Arrival time per work day">
                {grid.map((m) => (
                    <g key={m}>
                        <line x1={L} x2={W - R} y1={y(m)} y2={y(m)} stroke="#f1f5f9" />
                        <text x={L - 10} y={y(m) + 4} fill={INK.muted} fontSize="12" textAnchor="end" className="font-mono">{formatClockTick(m)}</text>
                    </g>
                ))}
                <line x1={L} x2={W - R} y1={y(shift.late_after_minutes)} y2={y(shift.late_after_minutes)} stroke={ACCENT} strokeWidth="1.5" strokeDasharray="5 5" />
                <text x={W - R} y={y(shift.late_after_minutes) - 8} fill="#6d28d9" fontSize="12" textAnchor="end">Late after {clock(shift.late_after_minutes)}</text>
                {hover != null && <rect x={L + hover * bw + 1} y={T - 4} width={Math.max(bw - 2, 2)} height={H - T - B + 8} rx="6" fill={ACCENT} opacity="0.1" />}
                <polyline points={line} fill="none" stroke="#cbd5e1" strokeWidth="1.5" strokeLinejoin="round" />
                {days.map((d, i) => {
                    const on = hover === i;
                    const isPresent = d.first_minutes != null;
                    return (
                        <g key={d.date}>
                            {isPresent
                                ? <circle cx={x(i)} cy={y(d.first_minutes)} r={on ? dot + 2 : dot} fill={d.first_minutes > shift.late_after_minutes ? OUTCOME.late.color : OUTCOME.on_time.color} stroke={INK.surface} strokeWidth="2" />
                                : <circle cx={x(i)} cy={H - B - 6} r={Math.min(dot, 5)} fill={INK.surface} stroke={d.outcome === 'excused' ? OUTCOME.excused.color : OUTCOME.absent.color} strokeWidth="2" />}
                            {(i % labelEvery === 0 || i === n - 1) && (
                                <text x={x(i)} y={H - 10} fill={INK.muted} fontSize="11" textAnchor="middle" className="font-mono">{dayLabel(d.date).slice(4)}</text>
                            )}
                            <rect x={L + i * bw} y={T - 4} width={bw} height={H - T - 4} fill="transparent" onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)} />
                        </g>
                    );
                })}
            </svg>
        </Panel>
    );
}

// ── Outcome split ───────────────────────────────────────────────────────────
export function OutcomeSplit({ split, workDays, subtitle }) {
    return (
        <Panel title="How the period was spent" subtitle={subtitle}>
            {workDays === 0 ? (
                <p className="text-sm text-slate-600">No tracked work days in this period.</p>
            ) : (
                <>
                    <div className="flex h-7 gap-0.5 overflow-hidden rounded-md" role="img" aria-label="Share of work days by outcome">
                        {split.map((o) => <span key={o.key} style={{ flexGrow: o.count, background: o.color }} />)}
                    </div>
                    <ul className="flex flex-col">
                        {split.map((o) => (
                            <li key={o.key} className="flex h-9 items-center gap-3 border-b border-slate-100 text-sm text-slate-900">
                                <Swatch color={o.color} />
                                <span className="flex-1">{o.label}</span>
                                <span className="font-mono tabular-nums">{o.count} {o.count === 1 ? 'day' : 'days'}</span>
                                <span className="w-12 text-right font-mono text-slate-500 tabular-nums">{Math.round((o.count / workDays) * 100)}%</span>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            <p className="text-xs text-slate-500">Excused days come from public holidays and approved waivers, and do not count against attendance.</p>
        </Panel>
    );
}

// ── Year calendar ───────────────────────────────────────────────────────────
export function YearCalendar({ calendar, shift, cell = 19 }) {
    const [hover, setHover] = useState(null);
    const gap = 4;
    const pitch = cell + gap;
    const left = 36;
    const top = 22;
    const startWd = calendar[0].weekday;
    const cols = Math.ceil((calendar.length + startWd) / 7);
    const W = left + cols * pitch;
    const H = top + 7 * pitch;
    const focus = hover != null ? calendar[hover] : null;
    const readout = focus
        ? `${dayLabel(focus.key)} · ${focus.day ? (() => { const d = describeDay(focus.day, shift); return focus.day.outcome === 'on_time' || focus.day.outcome === 'late' || focus.day.outcome === 'left_early' ? `${d.headline} · ${OUTCOME[focus.day.outcome].label}` : d.headline; })() : 'Not a work day'}`
        : 'Hover a day';

    return (
        <Panel
            big
            title="A year in constellations"
            subtitle="Every day of the last year. Outlined squares are not work days; faint ones are before this person was tracked."
            aside={<Readout>{readout}</Readout>}
        >
            <div className="overflow-x-auto">
                <svg viewBox={`0 0 ${W} ${H}`} width="100%" style={{ minWidth: 720 }} role="img" aria-label="Calendar of daily attendance outcomes for the past year">
                    {calendar.map((c, i) => (c.dom === 1 ? (
                        <text key={`m${c.key}`} x={left + Math.floor((i + startWd) / 7) * pitch} y="12" fill={INK.muted} fontSize="12">{dayLabel(c.key).split(' ')[2]}</text>
                    ) : null))}
                    {[['Sun', 0], ['Tue', 2], ['Thu', 4]].map(([l, r]) => (
                        <text key={l} x="0" y={top + r * pitch + cell - 5} fill={INK.muted} fontSize="11">{l}</text>
                    ))}
                    {calendar.map((c, i) => {
                        const x = left + Math.floor((i + startWd) / 7) * pitch;
                        const yy = top + c.weekday * pitch;
                        const on = hover === i;
                        let fill = 'none';
                        let stroke = '#e2e8f0';
                        let dash;
                        if (c.day?.outcome === 'untracked') { fill = '#f1f5f9'; stroke = 'none'; }
                        else if (c.day) {
                            fill = OUTCOME[c.day.outcome].color;
                            stroke = c.day.outcome === 'missed_punch_out' ? '#475569' : 'none';
                            dash = c.day.outcome === 'missed_punch_out' ? '3 2' : undefined;
                        }
                        return (
                            <rect
                                key={c.key} x={x} y={yy} width={cell} height={cell} rx="4"
                                fill={fill} stroke={on ? INK.primary : stroke} strokeWidth={on ? 2 : 1.2} strokeDasharray={on ? undefined : dash}
                                style={{ cursor: 'crosshair' }}
                                onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)}
                            />
                        );
                    })}
                </svg>
            </div>
            <OutcomeLegend extra={(
                <li className="flex items-center gap-2 text-[13px] text-slate-600">
                    <span className="inline-block h-2.5 w-2.5 rounded-[3px] border border-slate-300" aria-hidden="true" />Not a work day
                </li>
            )} />
        </Panel>
    );
}

// ── Weekday lateness ────────────────────────────────────────────────────────
export function WeekdayBars({ data }) {
    const W = 370;
    const H = 232;
    const base = 196;
    const max = Math.max(1, ...data.map((d) => d.value));
    const bw = 44;
    const gap = data.length > 1 ? (W - 24 - data.length * bw) / (data.length - 1) : 0;
    const top = (x, y, w) => `M${x} ${base} V${y + 4} Q${x} ${y} ${x + 4} ${y} H${x + w - 4} Q${x + w} ${y} ${x + w} ${y + 4} V${base} Z`;
    const total = data.reduce((s, d) => s + d.value, 0);

    return (
        <Panel title="When lateness happens" subtitle={`Late arrivals by weekday, past year · ${total} in all`}>
            <svg viewBox={`0 0 ${W} ${H}`} width="100%" role="img" aria-label="Late arrivals by weekday">
                <line x1="12" x2={W - 12} y1={base} y2={base} stroke="#e2e8f0" />
                {data.map((d, i) => {
                    const x = 12 + i * (bw + gap);
                    const h = d.value ? Math.max((d.value / max) * 150, 4) : 0;
                    return (
                        <g key={d.label}>
                            {h > 0 && <path d={top(x, base - h, bw)} fill={OUTCOME.late.color} />}
                            <text x={x + bw / 2} y={base - h - 8} fill={INK.primary} fontSize="13" textAnchor="middle" className="font-mono">{d.value}</text>
                            <text x={x + bw / 2} y={base + 22} fill={INK.muted} fontSize="12" textAnchor="middle">{d.label}</text>
                        </g>
                    );
                })}
            </svg>
        </Panel>
    );
}

// ── Monthly punctuality ─────────────────────────────────────────────────────
export function MonthlyTrend({ months }) {
    const [hover, setHover] = useState(null);
    const W = 370;
    const H = 232;
    const L = 40;
    const R = 10;
    const x = (i) => L + 8 + i * ((W - L - R - 16) / 11);
    const known = months.map((m) => m.value).filter((v) => v != null);
    const lo = known.length ? Math.max(0, Math.floor((Math.min(...known) - 0.05) * 10) / 10) : 0.6;
    const y = (v) => 16 + ((1 - v) / (1 - lo || 1)) * 172;
    // Break the line across months with no data rather than bridging them.
    const segments = [];
    let current = [];
    months.forEach((m, i) => {
        if (m.value == null) { if (current.length) segments.push(current); current = []; }
        else current.push(`${x(i).toFixed(1)},${y(m.value).toFixed(1)}`);
    });
    if (current.length) segments.push(current);
    const focus = hover != null ? months[hover] : null;

    return (
        <Panel
            title="Punctuality trend"
            subtitle="On-time arrivals per month"
            aside={<Readout>{focus ? `${focus.full} · ${focus.value == null ? 'no days present' : `${Math.round(focus.value * 100)}% of ${focus.days} days`}` : 'Hover a month'}</Readout>}
        >
            <svg viewBox={`0 0 ${W} ${H}`} width="100%" role="img" aria-label="Monthly on-time arrival rate">
                {[1, (1 + lo) / 2, lo].map((v) => (
                    <g key={v}>
                        <line x1={L} x2={W - R} y1={y(v)} y2={y(v)} stroke="#f1f5f9" />
                        <text x={L - 8} y={y(v) + 4} fill={INK.muted} fontSize="11" textAnchor="end" className="font-mono">{Math.round(v * 100)}%</text>
                    </g>
                ))}
                {segments.map((s) => <polyline key={s[0]} points={s.join(' ')} fill="none" stroke={OUTCOME.on_time.color} strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />)}
                {months.map((m, i) => (
                    <g key={m.full}>
                        {m.value != null && <circle cx={x(i)} cy={y(m.value)} r={hover === i ? 6 : 4} fill={OUTCOME.on_time.color} stroke={INK.surface} strokeWidth="2" />}
                        <text x={x(i)} y={H - 10} fill={INK.muted} fontSize="11" textAnchor="middle">{m.label.charAt(0)}</text>
                        <rect x={x(i) - 13} y="0" width="26" height={H} fill="transparent" onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)} />
                    </g>
                ))}
            </svg>
        </Panel>
    );
}

// ── Where and how ───────────────────────────────────────────────────────────
export function WhereHow({ where }) {
    return (
        <Panel title="Where and how they clock in" subtitle={`${where.total.toLocaleString()} punch${where.total === 1 ? '' : 'es'} in this period`}>
            {where.total === 0 ? (
                <p className="text-sm text-slate-600">No punches in this period.</p>
            ) : (
                <>
                    {where.devices.map((d) => (
                        <div key={d.label} className="flex flex-col gap-1.5">
                            <div className="flex justify-between text-sm text-slate-900">
                                <span>{d.label}</span>
                                <span className="font-mono text-slate-600 tabular-nums">{d.count} · {Math.round(d.share * 100)}%</span>
                            </div>
                            <div className="h-2.5 rounded-full bg-slate-100"><div className="h-2.5 rounded-full" style={{ width: `${(d.share * 100).toFixed(1)}%`, background: ACCENT }} /></div>
                        </div>
                    ))}
                    <div className="h-px bg-slate-100" />
                    <span className="text-xs font-medium uppercase tracking-[0.08em] text-slate-500">Verified by</span>
                    <div className="flex flex-wrap gap-3">
                        {where.methods.map((m) => (
                            <div key={m.label} className="flex min-w-[7rem] flex-1 flex-col gap-0.5 rounded-xl bg-slate-50 px-3.5 py-3">
                                <span className="font-mono text-xl text-slate-900 tabular-nums">{Math.round(m.share * 100)}%</span>
                                <span className="text-[13px] text-slate-600">{m.label}</span>
                            </div>
                        ))}
                    </div>
                </>
            )}
        </Panel>
    );
}

// ── Mission log ─────────────────────────────────────────────────────────────
export function MissionLog({ log, twoColumns = false, action = null }) {
    return (
        <Panel big title="Mission log" aside={action}>
            {log.length === 0 ? (
                <p className="text-sm text-slate-600">No tracked work days in this period.</p>
            ) : (
                <ol className={twoColumns ? 'grid grid-flow-col grid-cols-2 grid-rows-5 gap-x-8' : 'flex flex-col'}>
                    {log.map((e) => (
                        <li key={e.key} className="flex items-start gap-3.5 border-b border-slate-100 py-2.5">
                            <span className="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-[3px]" style={{ background: e.color }} aria-hidden="true" />
                            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <div className="flex justify-between gap-3">
                                    <span className="text-sm font-semibold text-slate-900">{e.title}</span>
                                    <span className="whitespace-nowrap font-mono text-xs text-slate-500">{e.date}</span>
                                </div>
                                <span className="text-[13px] text-slate-600">{e.detail}</span>
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </Panel>
    );
}

// ── Needs attention ─────────────────────────────────────────────────────────
export function Alerts({ alerts }) {
    return (
        <Panel title="Needs HR's attention">
            {alerts.length === 0 ? (
                <p className="text-sm text-slate-600">Nothing needs attention in this period.</p>
            ) : alerts.map((a) => (
                <div key={a.title} className="flex gap-3.5 rounded-2xl bg-slate-50 p-3.5">
                    <svg className="h-[22px] w-[22px] shrink-0" viewBox="0 0 22 22" aria-hidden="true">
                        <circle cx="11" cy="11" r="9" fill="none" stroke={a.color} strokeWidth="1.8" />
                        <path d="M11 6.5v5.5M11 15v.1" fill="none" stroke={a.color} strokeWidth="2" strokeLinecap="round" />
                    </svg>
                    <div className="flex flex-col gap-1">
                        <span className="text-xs font-semibold uppercase tracking-[0.06em] text-slate-600">{a.level}</span>
                        <span className="text-sm font-semibold text-slate-900">{a.title}</span>
                        <span className="text-[13px] text-slate-600">{a.detail}</span>
                    </div>
                </div>
            ))}
        </Panel>
    );
}

// ── KPI tiles ───────────────────────────────────────────────────────────────
export function KpiTiles({ kpis, className = '' }) {
    return (
        <div className={`grid gap-4 ${className}`}>
            {kpis.map((k) => (
                <div key={k.label} className={`flex flex-col gap-2 rounded-2xl bg-white px-5 py-5 ${ELEVATION.card}`}>
                    <span className="text-xs font-medium uppercase tracking-[0.08em] text-slate-500">{k.label}</span>
                    <span className="font-mono text-3xl font-medium text-slate-900 tabular-nums">{k.value}</span>
                    <span className="text-[13px] text-slate-600">{k.note}</span>
                </div>
            ))}
        </div>
    );
}

// ── Identity ────────────────────────────────────────────────────────────────
const STATE_ROW = {
    read: (e) => ({ box: 'bg-slate-50', icon: 'read', text: [e.fingerprints === 1 ? '1 finger' : `${e.fingerprints} fingers`, e.has_card ? 'card on file' : 'no card'].join(' · ') }),
    unknown: () => ({ box: 'border border-dashed border-amber-300 bg-amber-50/50', icon: 'unknown', text: 'Unknown — template table would not decode' }),
    never_read: () => ({ box: '', icon: 'never', text: 'Never read' }),
};

function StateIcon({ kind }) {
    if (kind === 'read') {
        return (
            <svg className="h-[18px] w-[18px] shrink-0" viewBox="0 0 18 18" aria-hidden="true">
                <circle cx="9" cy="9" r="7" fill={OUTCOME.on_time.color} />
                <path d="M5.5 9.2l2.3 2.2 4.4-4.6" fill="none" stroke={INK.surface} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
        );
    }
    if (kind === 'unknown') {
        return (
            <svg className="h-[18px] w-[18px] shrink-0" viewBox="0 0 18 18" aria-hidden="true">
                <circle cx="9" cy="9" r="7" fill="none" stroke="#d97706" strokeWidth="1.6" strokeDasharray="3 2.2" />
                <path d="M7.2 7.2a1.9 1.9 0 1 1 2.6 1.8c-.5.2-.8.6-.8 1.1M9 12.6v.1" fill="none" stroke="#d97706" strokeWidth="1.6" strokeLinecap="round" />
            </svg>
        );
    }
    return <svg className="h-[18px] w-[18px] shrink-0" viewBox="0 0 18 18" aria-hidden="true"><circle cx="9" cy="9" r="6.5" fill="none" stroke="#94a3b8" strokeWidth="1.6" /></svg>;
}

function shortTime(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return `read ${d.toLocaleDateString([], { day: 'numeric', month: 'short' })} ${d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
}

export function IdentityCard({ employee, enrollment, lastSeen, shift }) {
    const workDays = shift.work_days.map((w) => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][w]);
    const facts = [
        ['Employee code', employee.employee_code, true],
        ['Joined', employee.hired_date ? dayLabel(employee.hired_date).slice(4) + ` ${employee.hired_date.slice(0, 4)}` : 'Not recorded'],
        ['Shift', `${clock(shift.start_minutes)} – ${clock(shift.end_minutes)} · ${workDays[0]}–${workDays[workDays.length - 1]}`, true],
        ['Terminal privilege', employee.privilege ? employee.privilege.charAt(0).toUpperCase() + employee.privilege.slice(1) : 'Not recorded'],
    ];

    return (
        <Panel className="h-full">
            <div className="flex items-center gap-5">
                <div className="relative h-[104px] w-[104px] shrink-0">
                    <svg className="absolute inset-0" viewBox="0 0 104 104" aria-hidden="true">
                        <ellipse cx="52" cy="52" rx="50" ry="20" fill="none" stroke="#e2e8f0" strokeWidth="1.2" transform="rotate(-28 52 52)" />
                        <circle cx="93" cy="34" r="4.5" fill={ACCENT} />
                    </svg>
                    {employee.photo_url ? (
                        <img src={employee.photo_url} alt="" className="absolute inset-[16px] h-[72px] w-[72px] rounded-full object-cover ring-2 ring-violet-500" />
                    ) : (
                        <span className="absolute inset-[16px] flex h-[72px] w-[72px] items-center justify-center rounded-full bg-violet-50 text-xl font-semibold text-violet-700 ring-2 ring-violet-500">
                            {employee.name.split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase()}
                        </span>
                    )}
                </div>
                <div className="flex min-w-0 flex-col gap-1.5">
                    <span className={`inline-flex items-center gap-2 self-start rounded-full px-2.5 py-1 text-xs font-semibold ${employee.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>
                        <span className={`h-1.5 w-1.5 rounded-full ${employee.is_active ? 'bg-emerald-500' : 'bg-slate-400'}`} />{employee.is_active ? 'Active' : 'Inactive'}
                    </span>
                    <h1 className="text-2xl font-semibold leading-tight tracking-[-0.01em] text-slate-900 md:text-[28px]">{employee.name}</h1>
                    <span className="text-sm text-slate-600">{[employee.job_title, employee.department].filter(Boolean).join(' · ') || 'No job title or department recorded'}</span>
                </div>
            </div>

            <div className="flex items-center gap-3.5 rounded-2xl bg-violet-50/70 px-4 py-3.5">
                <span className="relative h-3 w-3 shrink-0">
                    {lastSeen && <span className="absolute inset-0 animate-ping rounded-full bg-violet-500 opacity-60" />}
                    <span className={`absolute inset-0 rounded-full ${lastSeen ? 'bg-violet-500' : 'bg-slate-300'}`} />
                </span>
                <div className="flex flex-col gap-0.5">
                    <span className="text-sm font-semibold text-slate-900">{lastSeen ? `Last seen ${dayLabel(lastSeen.date)}` : 'No punches yet'}</span>
                    <span className="text-[13px] text-slate-600">
                        {lastSeen ? [`In ${clock(lastSeen.first_minutes)}`, lastSeen.last_minutes != null ? `out ${clock(lastSeen.last_minutes)}` : 'no punch-out yet', lastSeen.device].filter(Boolean).join(' · ') : 'Nothing on any terminal for this person.'}
                    </span>
                </div>
            </div>

            <dl className="grid grid-cols-2 gap-x-6 gap-y-4">
                {facts.map(([label, value, mono]) => (
                    <div key={label} className="flex flex-col gap-1">
                        <dt className="text-xs uppercase tracking-[0.08em] text-slate-500">{label}</dt>
                        <dd className={`text-[15px] text-slate-900 ${mono ? 'font-mono' : ''}`}>{value}</dd>
                    </div>
                ))}
            </dl>

            <div className="flex flex-col gap-2.5 border-t border-slate-100 pt-5">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 className="text-[15px] font-semibold text-slate-900">What each terminal says</h2>
                    <span className="text-xs text-slate-500">read from the device, not assumed</span>
                </div>
                {enrollment.length === 0 && <p className="text-sm text-slate-600">Not on any terminal.</p>}
                {enrollment.map((e) => {
                    const row = STATE_ROW[e.state](e);
                    return (
                        <div key={e.device} className={`flex flex-wrap items-center gap-3 rounded-xl px-3.5 py-3 ${row.box}`}>
                            <StateIcon kind={row.icon} />
                            <span className="w-24 text-sm font-semibold text-slate-900">{e.device}</span>
                            <span className={`flex-1 text-sm ${e.state === 'never_read' ? 'text-slate-500' : 'text-slate-900'}`}>{row.text}</span>
                            {e.state !== 'never_read' && <span className="font-mono text-xs text-slate-500">{shortTime(e.synced_at)}</span>}
                        </div>
                    );
                })}
            </div>
        </Panel>
    );
}
