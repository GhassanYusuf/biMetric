// The design system: soft 16px white cards on a cool light canvas, violet
// primary, generous spacing. Every screen is built from these so the look stays
// in one system.
//
// Two rules hold the surface together:
//  1. Elevation is declared ONCE per surface — shadow OR border, never both.
//  2. Every interactive element ships default / hover / focus-visible /
//     disabled, and where relevant loading and error. No half-built controls.
//
// Body and secondary text sit at slate-500 or darker (4.76:1 on white).
// slate-400 fails AA at body size and is reserved for icons and hairlines.

import { ELEVATION, FOCUS, TREND } from './theme';

export function Card({ children, className = '', as: Tag = 'div', ...props }) {
    return (
        <Tag className={`rounded-2xl bg-white ${ELEVATION.card} ${className}`} {...props}>
            {children}
        </Tag>
    );
}

// A titled content card with an optional right-aligned action (filter, button).
// More space above the title than below it, per the type rhythm.
export function SectionCard({ title, subtitle, action, children, className = '', bodyClass = 'p-5' }) {
    return (
        <Card className={className}>
            {(title || action) && (
                <div className="flex items-start justify-between gap-4 px-5 pt-5">
                    <div className="min-w-0">
                        {title && <h2 className="text-base font-semibold tracking-[-0.01em] text-slate-900">{title}</h2>}
                        {subtitle && <p className="mt-0.5 text-xs text-slate-500">{subtitle}</p>}
                    </div>
                    {action}
                </div>
            )}
            <div className={bodyClass}>{children}</div>
        </Card>
    );
}

export function Button({ children, variant = 'primary', className = '', loading = false, disabled, ...props }) {
    const variants = {
        primary: 'bg-violet-600 text-white shadow-sm shadow-violet-600/20 hover:bg-violet-500 active:bg-violet-700',
        secondary: 'border border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50 active:bg-slate-100',
        soft: 'bg-violet-50 text-violet-700 hover:bg-violet-100 active:bg-violet-200',
        danger: 'bg-rose-600 text-white hover:bg-rose-500 active:bg-rose-700',
        ghost: 'text-slate-600 hover:bg-slate-100 active:bg-slate-200',
    };
    return (
        <button
            disabled={disabled || loading}
            aria-busy={loading || undefined}
            className={`inline-flex min-h-[42px] items-center justify-center gap-2 rounded-xl px-4 text-sm font-semibold transition-colors duration-150 disabled:pointer-events-none disabled:opacity-45 ${variants[variant]} ${FOCUS} ${className}`}
            {...props}
        >
            {loading && <Spinner className="h-4 w-4" />}
            {children}
        </button>
    );
}

// Small round icon button — top-bar controls, the KPI arrow, dismiss affordances.
export function CircleButton({ children, tone = 'slate', className = '', label, ...props }) {
    const tones = {
        slate: 'border border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50',
        green: 'bg-emerald-600 text-white hover:bg-emerald-500 shadow-sm shadow-emerald-600/25',
        violet: 'bg-violet-600 text-white hover:bg-violet-500',
    };
    return (
        <button
            aria-label={label}
            className={`inline-flex h-9 w-9 items-center justify-center rounded-full transition-colors duration-150 disabled:pointer-events-none disabled:opacity-45 ${tones[tone]} ${FOCUS} ${className}`}
            {...props}
        >
            {children}
        </button>
    );
}

export function TextField({ label, error, hint, className = '', id, ...props }) {
    const inputId = id || (label ? `f-${label.replace(/\W+/g, '-').toLowerCase()}` : undefined);
    return (
        <div className="block">
            {label && (
                <label htmlFor={inputId} className="mb-1.5 block text-sm font-medium text-slate-700">{label}</label>
            )}
            <input
                id={inputId}
                aria-invalid={error ? 'true' : undefined}
                className={`block w-full rounded-xl border bg-slate-50/70 px-3.5 py-2.5 text-sm text-slate-900 transition-colors duration-150 placeholder:text-slate-500 focus:bg-white focus:outline-none focus:ring-2 disabled:opacity-50 ${
                    error
                        ? 'border-rose-300 focus:border-rose-400 focus:ring-rose-100'
                        : 'border-slate-200 focus:border-violet-400 focus:ring-violet-100'
                } ${className}`}
                {...props}
            />
            {error ? (
                <span role="alert" className="mt-1 block text-xs font-medium text-rose-600">{error}</span>
            ) : hint ? (
                <span className="mt-1 block text-xs text-slate-500">{hint}</span>
            ) : null}
        </div>
    );
}

export function SelectField({ label, error, children, className = '', id, ...props }) {
    const selectId = id || (label ? `s-${label.replace(/\W+/g, '-').toLowerCase()}` : undefined);
    return (
        <div className="block">
            {label && (
                <label htmlFor={selectId} className="mb-1.5 block text-sm font-medium text-slate-700">{label}</label>
            )}
            <select
                id={selectId}
                aria-invalid={error ? 'true' : undefined}
                className={`block w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2.5 text-sm text-slate-900 transition-colors duration-150 focus:border-violet-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-violet-100 disabled:opacity-50 ${className}`}
                {...props}
            >
                {children}
            </select>
            {error && <span role="alert" className="mt-1 block text-xs font-medium text-rose-600">{error}</span>}
        </div>
    );
}

// Inline result banner — device action outcomes, which report real success or
// failure text straight from the hardware.
export function InlineAlert({ tone = 'info', children, onDismiss }) {
    const tones = {
        info: 'bg-sky-50 text-sky-900 ring-sky-200',
        success: 'bg-emerald-50 text-emerald-900 ring-emerald-200',
        error: 'bg-rose-50 text-rose-800 ring-rose-200',
    };
    return (
        <div className={`flex items-start gap-3 rounded-xl px-4 py-3 text-sm ring-1 ${tones[tone]}`} role="status">
            <span className="flex-1">{children}</span>
            {onDismiss && (
                <button onClick={onDismiss} className={`-m-1 shrink-0 rounded p-1 opacity-70 hover:opacity-100 ${FOCUS}`} aria-label="Dismiss">
                    <svg className="h-3.5 w-3.5" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                        <path d="M3 3l8 8M11 3l-8 8" />
                    </svg>
                </button>
            )}
        </div>
    );
}

export function Badge({ children, tone = 'slate', className = '' }) {
    const tones = {
        slate: 'bg-slate-100 text-slate-700',
        emerald: 'bg-emerald-50 text-emerald-700',
        amber: 'bg-amber-50 text-amber-800',
        rose: 'bg-rose-50 text-rose-700',
        violet: 'bg-violet-50 text-violet-700',
        blue: 'bg-blue-50 text-blue-700',
    };
    return (
        <span className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${tones[tone]} ${className}`}>
            {children}
        </span>
    );
}

// A small "4% ↑" delta chip. Direction drives both colour and glyph, so the
// meaning survives without colour vision.
export function TrendPill({ direction = 'flat', children }) {
    const color = TREND[direction] ?? TREND.flat;
    const arrow = direction === 'up' ? '↑' : direction === 'down' ? '↓' : '→';
    return (
        <span className="inline-flex items-center gap-0.5 text-xs font-semibold tabular-nums" style={{ color }}>
            {children} <span aria-hidden="true">{arrow}</span>
        </span>
    );
}

// KPI card: big number, label, caption, trend, and a circular drill-in action.
// Hover lifts to `raised` — the one elevation change on the surface.
export function KpiCard({ value, label, caption, trend, trendDirection = 'up', onAction, actionLabel }) {
    return (
        <Card className="group relative p-5 transition-shadow duration-200 hover:shadow-[0_2px_4px_rgba(16,24,40,0.06),0_12px_28px_-12px_rgba(16,24,40,0.16)]">
            <div className="flex items-start justify-between gap-2">
                <span className="text-3xl font-bold tabular-nums tracking-[-0.02em] text-slate-900">{value}</span>
                {trend && <TrendPill direction={trendDirection}>{trend}</TrendPill>}
            </div>
            <div className="mt-1 text-sm font-semibold text-slate-700">{label}</div>
            {caption && <div className="mt-3 pr-12 text-xs text-slate-500">{caption}</div>}
            {onAction && (
                <div className="absolute bottom-4 right-4">
                    <CircleButton tone="green" onClick={onAction} label={actionLabel || `${label} details`}>
                        <svg className="h-4 w-4 -rotate-45" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M5 12h14M13 6l6 6-6 6" />
                        </svg>
                    </CircleButton>
                </div>
            )}
        </Card>
    );
}

// One page-title rhythm across every screen: title, optional subtitle, actions
// on the right. Sizes shift with device class; the structure does not.
export function PageHeader({ title, subtitle, children, compact = false }) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
                <h1 className={`font-bold tracking-[-0.02em] text-slate-900 ${compact ? 'text-xl' : 'text-2xl'}`}>
                    {title}
                </h1>
                {subtitle && <p className="mt-0.5 text-sm text-slate-500">{subtitle}</p>}
            </div>
            {children && <div className="flex shrink-0 items-center gap-2">{children}</div>}
        </div>
    );
}

/**
 * Consistent chrome for every data table: the card clips the corners, the
 * wrapper owns horizontal overflow so a wide table scrolls inside its own card
 * instead of the page, and the header row shares one treatment everywhere.
 */
export function TableShell({ children, className = '' }) {
    return (
        <Card className={`overflow-hidden ${className}`}>
            <div className="overflow-x-auto">
                <table className="min-w-full text-sm">{children}</table>
            </div>
        </Card>
    );
}

export function Thead({ children }) {
    return (
        <thead className="border-b border-slate-100 bg-slate-50/80 text-xs font-semibold uppercase tracking-wide text-slate-500">
            {children}
        </thead>
    );
}

export function Spinner({ className = 'h-5 w-5' }) {
    return (
        <svg className={`animate-spin text-current ${className}`} viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle className="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-80" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
        </svg>
    );
}

/*
| Skeletons, not centred spinners. Content-shaped placeholders keep the layout
| from jumping when data lands and tell the user what is coming.
*/
export function Skeleton({ className = '', ...props }) {
    return <div className={`animate-pulse rounded-md bg-slate-200/70 ${className}`} aria-hidden="true" {...props} />;
}

export function SkeletonTable({ rows = 6, cols = 5 }) {
    return (
        <Card className="overflow-hidden" aria-busy="true" aria-label="Loading">
            <div className="flex gap-4 border-b border-slate-100 bg-slate-50 px-5 py-3">
                {Array.from({ length: cols }, (_, i) => (
                    <Skeleton key={i} className="h-3 flex-1" />
                ))}
            </div>
            <div className="divide-y divide-slate-100">
                {Array.from({ length: rows }, (_, r) => (
                    <div key={r} className="flex items-center gap-4 px-5 py-4">
                        {Array.from({ length: cols }, (_, c) => (
                            <Skeleton key={c} className={`h-3.5 ${c === 0 ? 'flex-[1.6]' : 'flex-1'}`} />
                        ))}
                    </div>
                ))}
            </div>
        </Card>
    );
}

export function SkeletonCards({ count = 4, height = 'h-28' }) {
    return (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-busy="true" aria-label="Loading">
            {Array.from({ length: count }, (_, i) => (
                <Card key={i} className={`${height} p-5`}>
                    <Skeleton className="h-7 w-16" />
                    <Skeleton className="mt-3 h-3 w-24" />
                </Card>
            ))}
        </div>
    );
}

// Empty states teach the interface rather than announcing absence, so they take
// an action when there is one obvious next step.
export function EmptyState({ title, hint, action }) {
    return (
        <div className="flex flex-col items-center gap-1 px-6 py-16 text-center">
            <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                <svg className="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
                    <path d="M3 7h18v13H3zM3 7l2-3h14l2 3" strokeLinejoin="round" />
                </svg>
            </div>
            <p className="font-semibold text-slate-800">{title}</p>
            {hint && <p className="max-w-sm text-sm text-slate-500">{hint}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}
