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

import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ELEVATION, FOCUS, TREND } from './theme';
import { useEscapeKey } from './useEscapeKey';

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

/*
| `result` (2026-10-18, owner: "when we change something I need an animated indicator
| that my request was successfully done or failed"): 'success' | 'error' | null. For a
| moment after the outcome is KNOWN — the server answered, or a queued device command
| finished — the button turns green with a drawn tick or red with a cross, then goes
| back. useActionResult() sets and clears it. The toast still says what happened
| (rule 2); this says WHICH button it was about.
*/
export function Button({ children, variant = 'primary', className = '', loading = false, result = null, disabled, ...props }) {
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
            className={`inline-flex min-h-[42px] items-center justify-center gap-2 rounded-xl px-4 text-sm font-semibold transition-colors duration-300 disabled:pointer-events-none disabled:opacity-45 ${variants[variant]} ${result ? RESULT_TINT[result] : ''} ${FOCUS} ${className}`}
            {...props}
        >
            {loading ? <Spinner className="h-4 w-4" /> : result && <ResultIcon result={result} className="h-4 w-4" />}
            {children}
        </button>
    );
}

// `!` because the tint has to beat every variant's own background, hover included.
const RESULT_TINT = {
    success: '!border-emerald-600 !bg-emerald-600 !text-white',
    error: '!border-rose-600 !bg-rose-600 !text-white',
};

/** A tick drawn in, or a cross, popping in — the outcome of the action a control started. */
export function ResultIcon({ result, className = 'h-4 w-4' }) {
    return (
        <svg
            className={`shrink-0 ${className}`}
            style={{ animation: 'result-pop 320ms cubic-bezier(0.34, 1.56, 0.64, 1) both' }}
            viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"
            strokeLinecap="round" strokeLinejoin="round"
            role="img" aria-label={result === 'success' ? 'Done' : 'Failed'}
        >
            {result === 'success' ? (
                <path d="M5 12.5l4.5 4.5L19 7.5" pathLength="1" style={{ strokeDasharray: 1, animation: 'result-draw 380ms 120ms ease-out both' }} />
            ) : (
                <>
                    <path d="M6.5 6.5l11 11" pathLength="1" style={{ strokeDasharray: 1, animation: 'result-draw 220ms 100ms ease-out both' }} />
                    <path d="M17.5 6.5l-11 11" pathLength="1" style={{ strokeDasharray: 1, animation: 'result-draw 220ms 260ms ease-out both' }} />
                </>
            )}
        </svg>
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

/*
| ActionMenu — one trigger and a list, for rows that had grown a strip of small
| competing buttons. Six of them side by side forces every label down to a size
| that cannot be read, and it parks Delete a pixel away from Sync punches.
|
| Why it is PORTALLED to the body: TableShell clips (`overflow-hidden` on the
| card, `overflow-x-auto` on the scroller), so a panel positioned inside a cell
| is cut off at the table edge. Fixed coordinates taken from the trigger's rect
| are the only thing that survives that.
|
| Why the trigger changes: collapsing the strip loses the per-button spinner, and
| the operator must still be able to see WHICH command is in flight. That is page
| state, not an event, so it is content and not a toast (rule 2).
|
| Items: { key, label, description?, onSelect, tone?: 'default'|'primary'|'danger',
|          loading?, disabled?, hidden? } or { separator: true, key }.
| Falsy entries are dropped, so a permission flag can gate an item inline.
*/
const MENU_WIDTH = 268;

/*
| `anchorRef` + `hideTrigger` make the CALLER the trigger (2026-10-05, owner's
| request: tapping anywhere on an employee row drops the menu down).
|
| Without them a table row cannot be the trigger, because a <button> cannot be
| nested inside a <tr> as its own action cell without a second tap target. The
| panel is portalled to <body> anyway, so the row owns open state and this
| renders only the panel, anchored to whatever element the row's ref points at.
| Uncontrolled (the default) keeps the self-contained button for callers that
| have nothing to anchor to.
*/
export function ActionMenu({
    label = 'Actions',
    items = [],
    align = 'right',
    className = '',
    anchorRef,
    hideTrigger = false,
    anchorPoint = null,
    open: controlledOpen,
    onOpenChange,
    initialFocus = 'none',
    // { label, result: 'success'|'error' } while an action from this menu has just
    // finished — the trigger shows it the way Button does (see Button's `result`).
    outcome = null,
}) {
    const entries = items.filter(Boolean).filter((i) => !i.hidden);
    const navCount = entries.filter((i) => !i.separator).length;
    const busy = entries.find((i) => i.loading);

    // Controlled when the caller says `open`, so two rows cannot both believe
    // they own the menu — whichever one is open is the one the pointer is on.
    const isControlled = controlledOpen !== undefined;
    const [uncontrolledOpen, setUncontrolledOpen] = useState(false);
    const open = isControlled ? controlledOpen : uncontrolledOpen;
    const setOpen = useCallback((next) => {
        if (isControlled) onOpenChange?.(next);
        else setUncontrolledOpen(next);
    }, [isControlled, onOpenChange]);

    const [box, setBox] = useState(null);
    /*
     | `initialFocus` is read once, at mount: 'first'/'last' put the keyboard on
     | an item, which is what ArrowDown/ArrowUp did when the trigger was the
     | button inside this component. A trigger the caller owns cannot call
     | onTriggerKey, so the caller states the intent instead. 'none' leaves focus
     | on the trigger, which is what Enter and Space should do (rule 7).
     */
    const [active, setActive] = useState(
        initialFocus === 'first' ? 0 : initialFocus === 'last' ? Math.max(0, navCount - 1) : -1,
    );
    const triggerRef = useRef(null);
    const panelRef = useRef(null);
    const itemRefs = useRef([]);

    // The element the menu is measured against and returns focus to. An anchor
    // the caller supplied wins, because the caller's element IS the trigger.
    const anchor = useCallback(() => anchorRef?.current ?? triggerRef.current, [anchorRef]);

    const close = useCallback((focusTrigger = true) => {
        setOpen(false);
        setActive(-1);
        if (focusTrigger) anchor()?.focus?.();
    }, [anchor, setOpen]);

    useEscapeKey(close, open);

    // Measured rather than guessed, and re-measured on scroll/resize so the
    // panel cannot drift away from the row it belongs to.
    //
    // `anchorPoint` wins over the element: the owner asked for the menu to open
    // WHERE THEY CLICKED, not where the row happens to sit (2026-10-05). It is
    // synthesised as a zero-size rect at the pointer, so every flip and clamp
    // below still applies and the panel can never open off-screen.
    const place = useCallback(() => {
        const rect = anchorPoint
            ? { left: anchorPoint.x, right: anchorPoint.x, top: anchorPoint.y, bottom: anchorPoint.y }
            : anchor()?.getBoundingClientRect();
        if (!rect) return;

        const below = window.innerHeight - rect.bottom - 16;
        const above = rect.top - 16;
        const dropUp = below < 240 && above > below;
        const left = align === 'right' ? rect.right - MENU_WIDTH : rect.left;

        setBox({
            left: Math.max(12, Math.min(left, window.innerWidth - MENU_WIDTH - 12)),
            top: dropUp ? undefined : rect.bottom + 8,
            bottom: dropUp ? window.innerHeight - rect.top + 8 : undefined,
            maxHeight: Math.max(180, dropUp ? above : below),
        });
    }, [align, anchor, anchorPoint]);

    useLayoutEffect(() => {
        if (!open) return undefined;
        place();
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);
        return () => {
            window.removeEventListener('scroll', place, true);
            window.removeEventListener('resize', place);
        };
    }, [open, place]);

    useEffect(() => {
        if (!open) return undefined;
        function onDown(e) {
            // A click on the trigger is the toggle's business, not a dismissal —
            // checked against whichever element is currently the trigger.
            if (anchor()?.contains(e.target)) return;
            if (panelRef.current?.contains(e.target)) return;
            close(false);
        }
        document.addEventListener('mousedown', onDown);
        return () => document.removeEventListener('mousedown', onDown);
    }, [open, close, anchor]);

    useEffect(() => {
        if (open && active >= 0) itemRefs.current[active]?.focus();
    }, [open, active]);

    function move(delta) {
        if (navCount === 0) return;
        setActive((cur) => {
            const base = cur < 0 ? (delta > 0 ? -1 : 0) : cur;
            return (base + delta + navCount) % navCount;
        });
    }

    function onTriggerKey(e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            setOpen(true);
            setActive(e.key === 'ArrowDown' ? 0 : navCount - 1);
        }
    }

    // Arrow keys, Home/End and Tab are what a menu is expected to answer to;
    // Enter and Space are the buttons' own (rule 7).
    function onPanelKey(e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
        else if (e.key === 'Home') { e.preventDefault(); setActive(0); }
        else if (e.key === 'End') { e.preventDefault(); setActive(navCount - 1); }
        else if (e.key === 'Tab') close();
    }

    function select(item) {
        if (item.disabled || item.loading) return;
        // Focus goes home first so a dialog opened by the action can take it
        // from there, and a plain action leaves the keyboard where it started.
        close();
        item.onSelect?.();
    }

    const tones = {
        default: 'text-slate-700 hover:bg-slate-50 focus-visible:bg-slate-50',
        primary: 'text-violet-700 hover:bg-violet-50 focus-visible:bg-violet-50',
        danger: 'text-rose-600 hover:bg-rose-50 focus-visible:bg-rose-50',
    };

    let navIndex = -1;

    return (
        <>
            {!hideTrigger && (
            <button
                ref={triggerRef}
                type="button"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label={busy ? `${busy.label} in progress` : undefined}
                onClick={() => (open ? close() : setOpen(true))}
                onKeyDown={onTriggerKey}
                className={`inline-flex min-h-[34px] items-center gap-2 rounded-xl border px-3 text-xs font-semibold transition-colors duration-300 ${
                    busy
                        ? 'border-violet-200 bg-violet-50 text-violet-700'
                        : outcome
                            ? RESULT_TINT[outcome.result]
                            : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50 active:bg-slate-100'
                } ${FOCUS} ${className}`}
            >
                {busy ? (
                    <>
                        <Spinner className="h-3.5 w-3.5" />
                        {busy.label}
                    </>
                ) : outcome ? (
                    <>
                        <ResultIcon result={outcome.result} className="h-3.5 w-3.5" />
                        {outcome.label}
                    </>
                ) : (
                    <>
                        {label}
                        <svg
                            className={`h-3 w-3 text-slate-500 transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"
                        >
                            <path d="M6 9l6 6 6-6" />
                        </svg>
                    </>
                )}
            </button>
            )}

            {open && box && createPortal(
                <div
                    ref={panelRef}
                    role="menu"
                    aria-label={label}
                    onKeyDown={onPanelKey}
                    style={{
                        position: 'fixed',
                        left: box.left,
                        top: box.top,
                        bottom: box.bottom,
                        width: MENU_WIDTH,
                        maxHeight: box.maxHeight,
                        animation: 'menu-in 160ms cubic-bezier(0.16, 1, 0.3, 1)',
                    }}
                    className={`z-50 overflow-y-auto rounded-2xl bg-white p-1.5 ${ELEVATION.menu}`}
                >
                    {entries.map((item) => {
                        if (item.separator) {
                            return <div key={item.key} role="separator" className="my-1 h-px bg-slate-100" />;
                        }

                        navIndex += 1;
                        const index = navIndex;
                        const blocked = !!item.disabled || !!item.loading;

                        return (
                            <button
                                key={item.key}
                                ref={(el) => { itemRefs.current[index] = el; }}
                                type="button"
                                role="menuitem"
                                // aria-disabled rather than disabled: a disabled button
                                // drops out of the focus order and breaks arrow-key
                                // travel through the menu.
                                aria-disabled={blocked || undefined}
                                tabIndex={-1}
                                onClick={() => select(item)}
                                onMouseEnter={() => setActive(index)}
                                className={`flex w-full items-start gap-2 rounded-xl px-3 py-2 text-left transition-colors duration-150 ${
                                    tones[item.tone] || tones.default
                                } ${blocked ? 'cursor-not-allowed opacity-45' : ''} ${FOCUS}`}
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-semibold">{item.label}</span>
                                    {item.description && (
                                        <span className="mt-0.5 block text-xs font-normal leading-snug text-slate-500">
                                            {item.description}
                                        </span>
                                    )}
                                </span>
                                {item.loading && <Spinner className="mt-0.5 h-4 w-4 shrink-0" />}
                            </button>
                        );
                    })}
                </div>,
                document.body,
            )}
        </>
    );
}

/*
| Prev/Next pager driven by the API's meta.pagination block.
|
| Moved here from attendance/parts.jsx (2026-10-05): the employee roster needed
| one too, and a second pager is exactly the desktop/mobile drift rule 5 exists
| to prevent. `noun` names what is being paged, so the same control reads
| "362 employees" on one screen and "1,204 punches" on another.
|
| Returns null for a single page, so a caller can render it unconditionally.
*/
export function Pager({ pagination, onPage, loading, noun = 'results' }) {
    if (!pagination || (pagination.last_page ?? 1) <= 1) return null;

    const { current_page: current, last_page: last, total } = pagination;

    return (
        <div className="flex items-center justify-between gap-3">
            <span className="text-xs text-slate-500">
                Page {current} of {last} · {(total ?? 0).toLocaleString()} {noun}
            </span>
            <div className="flex gap-2">
                <Button variant="secondary" className="min-h-[36px] px-3 text-xs" disabled={loading || current <= 1} onClick={() => onPage(current - 1)}>
                    Previous
                </Button>
                <Button variant="secondary" className="min-h-[36px] px-3 text-xs" disabled={loading || current >= last} onClick={() => onPage(current + 1)}>
                    Next
                </Button>
            </div>
        </div>
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

/**
 * A determinate-or-indeterminate progress bar.
 *
 * `percent` of NULL is not 0 — it means the device never declared a total, and the
 * bar goes indeterminate rather than sitting at zero pretending to know. That
 * distinction is the same one rule 10 draws for fingerprint counts: "we were not
 * told" and "none" lead to opposite conclusions, so they must never render alike.
 *
 * Elevation is declared once (a ring, no shadow) per rule 7. The number is repeated
 * as text beside the bar because a bar alone is unreadable to a screen reader and
 * imprecise to everyone else.
 */
export function ProgressBar({ percent = null, label, detail, tone = 'violet', className = '' }) {
    const indeterminate = percent === null || percent === undefined;
    const clamped = indeterminate ? 0 : Math.max(0, Math.min(100, percent));

    const tones = {
        violet: 'bg-violet-600',
        emerald: 'bg-emerald-600',
        amber: 'bg-amber-500',
    };

    return (
        <div className={className}>
            {(label || detail) && (
                <div className="mb-1.5 flex items-baseline justify-between gap-3 text-xs">
                    {label && <span className="font-medium text-slate-700">{label}</span>}
                    {detail && <span className="tabular-nums text-slate-500">{detail}</span>}
                </div>
            )}

            <div
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={100}
                // Omitted entirely when indeterminate — that is what tells assistive
                // tech "in progress, extent unknown" instead of "0 percent".
                aria-valuenow={indeterminate ? undefined : clamped}
                aria-label={label || 'Progress'}
                className="h-1.5 w-full overflow-hidden rounded-full bg-slate-200"
            >
                {indeterminate ? (
                    <div className={`h-full w-1/3 rounded-full ${tones[tone]} motion-safe:animate-[progress-slide_1.4s_ease-in-out_infinite]`} />
                ) : (
                    <div
                        className={`h-full rounded-full transition-[width] duration-500 ease-out ${tones[tone]}`}
                        style={{ width: `${clamped}%` }}
                    />
                )}
            </div>
        </div>
    );
}

export function Badge({ children, tone = 'slate', className = '', title }) {
    const tones = {
        slate: 'bg-slate-100 text-slate-700',
        emerald: 'bg-emerald-50 text-emerald-700',
        amber: 'bg-amber-50 text-amber-800',
        rose: 'bg-rose-50 text-rose-700',
        violet: 'bg-violet-50 text-violet-700',
        blue: 'bg-blue-50 text-blue-700',
    };
    return (
        <span
            className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${tones[tone]} ${className}`}
            title={title}
        >
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
