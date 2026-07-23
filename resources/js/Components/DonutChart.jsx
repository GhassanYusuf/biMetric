import { useEffect, useState } from 'react';

const CX = 100;
const CY = 100;
const R_OUTER = 92;
const R_INNER = 56;
const HOVER_OFFSET = 8;

function polarToCartesian(cx, cy, r, angleDeg) {
    const rad = ((angleDeg - 90) * Math.PI) / 180;
    return { x: cx + r * Math.cos(rad), y: cy + r * Math.sin(rad) };
}

/** SVG path for one ring segment (an annular sector), from startAngle to endAngle (degrees, 0 = 12 o'clock). */
function describeDonutSlice(cx, cy, rOuter, rInner, startAngle, endAngle) {
    const largeArc = endAngle - startAngle > 180 ? 1 : 0;
    const startOuter = polarToCartesian(cx, cy, rOuter, endAngle);
    const endOuter = polarToCartesian(cx, cy, rOuter, startAngle);
    const startInner = polarToCartesian(cx, cy, rInner, endAngle);
    const endInner = polarToCartesian(cx, cy, rInner, startAngle);

    return [
        `M ${startOuter.x} ${startOuter.y}`,
        `A ${rOuter} ${rOuter} 0 ${largeArc} 0 ${endOuter.x} ${endOuter.y}`,
        `L ${endInner.x} ${endInner.y}`,
        `A ${rInner} ${rInner} 0 ${largeArc} 1 ${startInner.x} ${startInner.y}`,
        'Z',
    ].join(' ');
}

/**
 * Hand-rolled animated donut chart (no charting dependency). Each segment is
 * a real filled wedge path (not a thin dashed stroke), so the entire slice -
 * not just a hairline - is clickable. Slices pop outward on hover/selection
 * and the whole chart sweeps in on mount/whenever the data changes.
 *
 * Pass `selected` + `onSelect(key | null)` to let the caller drill into the
 * days behind a segment; clicking the active segment again clears it.
 */
export default function DonutChart({ segments, size = 240, selected = null, onSelect }) {
    const [drawn, setDrawn] = useState(false);
    const [hovered, setHovered] = useState(null);
    const total = segments.reduce((sum, s) => sum + s.value, 0);

    useEffect(() => {
        setDrawn(false);
        const id = requestAnimationFrame(() => setDrawn(true));
        return () => cancelAnimationFrame(id);
    }, [JSON.stringify(segments.map((s) => s.value))]);

    let cumulative = 0;
    const arcs = segments
        .filter((s) => s.value > 0)
        .map((segment) => {
            const sweep = total > 0 ? (segment.value / total) * 360 : 0;
            const startAngle = cumulative;
            const endAngle = cumulative + sweep;
            cumulative = endAngle;
            return { ...segment, startAngle, endAngle, mid: (startAngle + endAngle) / 2 };
        });

    const toggle = (key) => onSelect?.(selected === key ? null : key);
    const activeKey = selected ?? hovered;
    const activeSegment = activeKey ? segments.find((s) => s.key === activeKey) : null;
    const activeValue = activeSegment ? activeSegment.value : total;
    const activeLabel = activeSegment ? activeSegment.shortLabel ?? activeSegment.label : 'All days';

    return (
        <div className="flex flex-col items-center gap-5">
            <div className="relative shrink-0" style={{ width: size, height: size }}>
                <svg viewBox="0 0 200 200" className="h-full w-full overflow-visible">
                    <circle cx={CX} cy={CY} r={(R_OUTER + R_INNER) / 2} fill="none" stroke="#f8fafc" strokeWidth={R_OUTER - R_INNER + 4} />

                    {arcs.map((arc, i) => {
                        const isActive = activeKey === arc.key;
                        const isDimmed = activeKey !== null && !isActive;
                        const mid = polarToCartesian(0, 0, isActive ? HOVER_OFFSET : 0, arc.mid);

                        return (
                            <path
                                key={arc.key}
                                d={describeDonutSlice(CX, CY, R_OUTER, R_INNER, arc.startAngle, arc.endAngle)}
                                fill={arc.color}
                                stroke="white"
                                strokeWidth="2"
                                onClick={() => toggle(arc.key)}
                                onMouseEnter={() => setHovered(arc.key)}
                                onMouseLeave={() => setHovered(null)}
                                style={{
                                    cursor: 'pointer',
                                    opacity: drawn ? (isDimmed ? 0.35 : 1) : 0,
                                    transformBox: 'fill-box',
                                    transformOrigin: 'center',
                                    transform: `translate(${mid.x}px, ${mid.y}px) scale(${drawn ? 1 : 0.85})`,
                                    transition:
                                        `opacity 0.5s ease ${i * 0.06}s, ` +
                                        'transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1)',
                                }}
                            />
                        );
                    })}
                </svg>

                <div key={activeKey ?? 'total'} className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center px-3 [animation:fade-slide-in_0.25s_ease]">
                    <span className="text-3xl font-bold text-gray-800">{activeValue}</span>
                    <span className="max-w-full whitespace-nowrap text-center text-[11px] font-medium uppercase leading-tight tracking-wide text-gray-400">
                        {activeLabel}
                    </span>
                </div>
            </div>

            <div className="flex w-full flex-col gap-1.5">
                {segments.map((s) => (
                    <button
                        key={s.key}
                        type="button"
                        onClick={() => toggle(s.key)}
                        onMouseEnter={() => setHovered(s.key)}
                        onMouseLeave={() => setHovered(null)}
                        className={`flex items-center justify-between gap-3 rounded-md px-2.5 py-2 text-left text-sm transition-all duration-150 ${
                            selected === s.key ? 'bg-gray-100 ring-1 ring-inset ring-gray-200' : 'hover:bg-gray-50'
                        } ${activeKey && activeKey !== s.key ? 'opacity-40' : ''}`}
                    >
                        <span className="flex items-center gap-2 text-gray-600">
                            <span
                                className="h-2.5 w-2.5 shrink-0 rounded-full transition-transform"
                                style={{
                                    backgroundColor: s.color,
                                    transform: activeKey === s.key ? 'scale(1.3)' : 'scale(1)',
                                }}
                            />
                            {s.label}
                        </span>
                        <span className="font-semibold tabular-nums text-gray-800">
                            {s.value}
                            <span className="ml-1 text-xs font-normal text-gray-400">
                                ({total > 0 ? Math.round((s.value / total) * 100) : 0}%)
                            </span>
                        </span>
                    </button>
                ))}
            </div>
        </div>
    );
}
