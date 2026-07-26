import { useEffect, useRef, useState } from 'react';
import { ELEVATION, FOCUS, RANGES } from './theme';
import { Icon } from '../layouts/nav';

/*
| The top bar's range control — the reference's "Sort By / All-Time" slot, wired
| to something real: it re-queries the dashboard trend series.
|
| It says "Trend" rather than a bare range because the KPI tiles beside it are
| always today's figures. A control implying it filtered the whole page while
| the tiles stayed on today would be a lie about the data.
*/
export default function RangeControl({ value, onChange, label = 'Trend' }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);
    const current = RANGES.find((r) => r.days === value) ?? RANGES[0];

    useEffect(() => {
        if (!open) return undefined;
        function onDown(e) {
            if (!ref.current?.contains(e.target)) setOpen(false);
        }
        function onKey(e) {
            if (e.key === 'Escape') setOpen(false);
        }
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <div className="relative" ref={ref}>
            <button
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                aria-haspopup="listbox"
                className={`flex items-center gap-2.5 rounded-2xl bg-white px-3.5 py-2.5 text-left transition-colors duration-150 hover:text-violet-700 ${ELEVATION.card} ${FOCUS}`}
            >
                <Icon name="sliders" className="h-[18px] w-[18px] shrink-0 text-slate-400" />
                <span className="leading-tight">
                    <span className="block text-sm font-semibold tracking-[-0.01em] text-slate-900">{label}</span>
                    <span className="block text-xs text-slate-500">{current.short}</span>
                </span>
                <Icon name="chevron" className={`h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform duration-200 ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div
                    role="listbox"
                    className={`absolute left-0 top-[calc(100%+0.5rem)] z-30 w-52 overflow-hidden rounded-2xl bg-white p-1.5 ${ELEVATION.overlay}`}
                    style={{ animation: 'menu-in 160ms cubic-bezier(0.16, 1, 0.3, 1)' }}
                >
                    {RANGES.map((range) => {
                        const selected = range.days === value;
                        return (
                            <button
                                key={range.days}
                                role="option"
                                aria-selected={selected}
                                onClick={() => {
                                    onChange(range.days);
                                    setOpen(false);
                                }}
                                className={`flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-left text-sm font-medium transition-colors duration-150 ${
                                    selected ? 'bg-violet-50 text-violet-800' : 'text-slate-700 hover:bg-slate-50'
                                } ${FOCUS}`}
                            >
                                {range.label}
                                {selected && (
                                    <svg className="h-4 w-4 text-violet-600" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                        <path d="M4 10.5l4 4 8-9" />
                                    </svg>
                                )}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
