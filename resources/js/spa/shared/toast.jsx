import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { ELEVATION, FOCUS } from './theme';

/*
| Animated toasts — the ONLY way to report the outcome of an action.
|
| Inline banners are banned for results (see CLAUDE.md): they shove the page down
| when they appear, sit there after they stop being true, and duplicate the same
| message in different places on different screens. A toast is transient by
| construction, stacks, and never reflows the content behind it.
|
|     const toast = useToast();
|     toast.success('21 employee records read from IT Room.');
|     toast.error(err.message);
|
| Inline banners remain correct for PERSISTENT state that is part of the page —
| "this employee is not on the terminal", "showing the first 100 of 300". Those
| are content, not events.
*/

const ToastContext = createContext(null);

const DEFAULT_MS = { success: 4500, info: 5000, error: 8000 };

export function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);
    const nextId = useRef(1);

    const dismiss = useCallback((id) => {
        // Mark leaving first so the exit animation can play, then unmount.
        setToasts((list) => list.map((t) => (t.id === id ? { ...t, leaving: true } : t)));
        setTimeout(() => setToasts((list) => list.filter((t) => t.id !== id)), 200);
    }, []);

    const push = useCallback((tone, message, options = {}) => {
        if (!message) return null;

        const id = nextId.current++;
        const duration = options.duration ?? DEFAULT_MS[tone] ?? 5000;

        setToasts((list) => [
            // Cap the stack so a burst of failures can't cover the screen.
            ...list.slice(-3),
            { id, tone, message, title: options.title, duration, leaving: false },
        ]);

        return id;
    }, []);

    const api = useMemo(() => ({
        success: (message, options) => push('success', message, options),
        error: (message, options) => push('error', message, options),
        info: (message, options) => push('info', message, options),
        dismiss,
    }), [push, dismiss]);

    return (
        <ToastContext.Provider value={api}>
            {children}
            <ToastViewport toasts={toasts} onDismiss={dismiss} />
        </ToastContext.Provider>
    );
}

export function useToast() {
    const ctx = useContext(ToastContext);

    if (!ctx) {
        throw new Error('useToast must be used within a ToastProvider');
    }

    return ctx;
}

function ToastViewport({ toasts, onDismiss }) {
    if (toasts.length === 0) return null;

    return (
        <div
            // Bottom-centre on mobile (thumb reach, clear of the tab bar), top-right
            // on desktop where it can't cover the action that produced it.
            className="pointer-events-none fixed inset-x-0 bottom-24 z-[80] flex flex-col items-center gap-2 px-4 sm:inset-x-auto sm:bottom-auto sm:right-6 sm:top-6 sm:items-end sm:px-0"
            role="region"
            aria-label="Notifications"
        >
            {toasts.map((toast) => (
                <Toast key={toast.id} toast={toast} onDismiss={onDismiss} />
            ))}
        </div>
    );
}

const TONES = {
    success: {
        shell: 'bg-white ring-emerald-200/70',
        icon: 'bg-emerald-500 text-white',
        path: 'M4 10.5l4 4 8-9',
    },
    error: {
        shell: 'bg-white ring-rose-200/70',
        icon: 'bg-rose-500 text-white',
        path: 'M10 5.5v6M10 14.5h.01',
    },
    info: {
        shell: 'bg-white ring-sky-200/70',
        icon: 'bg-sky-500 text-white',
        path: 'M10 9v5.5M10 6h.01',
    },
};

function Toast({ toast, onDismiss }) {
    const { id, tone, message, title, duration, leaving } = toast;
    const [paused, setPaused] = useState(false);
    const tokens = TONES[tone] ?? TONES.info;

    // Auto-dismiss, but not while the pointer is over it — otherwise a message
    // vanishes exactly as someone leans in to read it.
    useEffect(() => {
        if (paused || leaving) return undefined;
        const timer = setTimeout(() => onDismiss(id), duration);
        return () => clearTimeout(timer);
    }, [id, duration, paused, leaving, onDismiss]);

    return (
        <div
            className={`pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-2xl px-4 py-3.5 ring-1 ${tokens.shell} ${ELEVATION.overlay}`}
            style={{ animation: `${leaving ? 'toast-out' : 'toast-in'} 200ms cubic-bezier(0.16, 1, 0.3, 1) both` }}
            role={tone === 'error' ? 'alert' : 'status'}
            onMouseEnter={() => setPaused(true)}
            onMouseLeave={() => setPaused(false)}
        >
            <span className={`mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full ${tokens.icon}`} aria-hidden="true">
                <svg className="h-3 w-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                    <path d={tokens.path} />
                </svg>
            </span>

            <div className="min-w-0 flex-1">
                {title && <div className="text-sm font-semibold text-slate-900">{title}</div>}
                <div className={`text-sm ${title ? 'mt-0.5 text-slate-600' : 'text-slate-800'}`}>{message}</div>
            </div>

            <button
                onClick={() => onDismiss(id)}
                aria-label="Dismiss notification"
                className={`-m-1 shrink-0 rounded-lg p-1 text-slate-400 transition-colors hover:text-slate-700 ${FOCUS}`}
            >
                <svg className="h-3.5 w-3.5" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                    <path d="M3 3l8 8M11 3l-8 8" />
                </svg>
            </button>
        </div>
    );
}
