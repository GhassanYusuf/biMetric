import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';
import { Button, Card } from './ui';
import { ELEVATION } from './theme';
import { useEscapeKey } from './useEscapeKey';

/*
| Promise-based confirmation dialog.
|
| NEVER use window.confirm / alert / prompt. They are unstyled, untranslatable,
| unthemeable, block the whole JS thread, cannot show rich content, and are
| suppressible by the browser — a suppressed confirm() returns false, which would
| silently cancel a destructive action with no explanation.
|
| The API deliberately mirrors the shape it replaces so call sites stay readable:
|
|     if (!(await confirm({ title: 'Delete Gassan?', danger: true }))) return;
|
| `body` takes a string or an array of paragraphs. `danger` styles the primary
| action as destructive. Resolve false on cancel, Escape, or backdrop click.
*/

const ConfirmContext = createContext(null);

export function ConfirmProvider({ children }) {
    const [request, setRequest] = useState(null);
    const resolver = useRef(null);

    const confirm = useCallback((options) => {
        return new Promise((resolve) => {
            resolver.current = resolve;
            setRequest(typeof options === 'string' ? { title: options } : options);
        });
    }, []);

    const settle = useCallback((result) => {
        setRequest(null);
        resolver.current?.(result);
        resolver.current = null;
    }, []);

    const value = useMemo(() => ({ confirm }), [confirm]);

    return (
        <ConfirmContext.Provider value={value}>
            {children}
            {request && <ConfirmDialog request={request} onSettle={settle} />}
        </ConfirmContext.Provider>
    );
}

/** Returns `confirm(options) => Promise<boolean>`. */
export function useConfirm() {
    const ctx = useContext(ConfirmContext);

    if (!ctx) {
        throw new Error('useConfirm must be used within a ConfirmProvider');
    }

    return ctx.confirm;
}

function ConfirmDialog({ request, onSettle }) {
    const {
        title,
        body,
        confirmLabel = 'Confirm',
        cancelLabel = 'Cancel',
        danger = false,
    } = request;

    const paragraphs = body == null ? [] : Array.isArray(body) ? body.filter(Boolean) : [body];

    useEscapeKey(() => onSettle(false));

    return (
        <div
            className="fixed inset-0 z-[70] flex items-end justify-center overflow-y-auto bg-slate-900/50 p-0 backdrop-blur-sm sm:items-center sm:p-4"
            onClick={() => onSettle(false)}
            role="presentation"
        >
            <Card
                className={`w-full max-w-md rounded-b-none sm:rounded-2xl ${ELEVATION.overlay}`}
                onClick={(e) => e.stopPropagation()}
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="confirm-title"
                style={{ animation: 'menu-in 160ms cubic-bezier(0.16, 1, 0.3, 1)' }}
            >
                <div className="p-5">
                    <div className="flex items-start gap-3.5">
                        <span
                            className={`mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${
                                danger ? 'bg-rose-50 text-rose-600' : 'bg-violet-50 text-violet-600'
                            }`}
                            aria-hidden="true"
                        >
                            {danger ? (
                                <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M12 9v5M12 17.5h.01M10.3 3.9 2.4 17.4A2 2 0 0 0 4.1 20.5h15.8a2 2 0 0 0 1.7-3.1L13.7 3.9a2 2 0 0 0-3.4 0z" />
                                </svg>
                            ) : (
                                <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M12 16v-4M12 8h.01" />
                                    <circle cx="12" cy="12" r="9" />
                                </svg>
                            )}
                        </span>

                        <div className="min-w-0 flex-1">
                            <h2 id="confirm-title" className="text-base font-semibold tracking-[-0.01em] text-slate-900">
                                {title}
                            </h2>
                            {paragraphs.length > 0 && (
                                <div className="mt-2 space-y-2">
                                    {paragraphs.map((text, i) => (
                                        <p key={i} className="text-sm leading-relaxed text-slate-600">{text}</p>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button variant="secondary" onClick={() => onSettle(false)}>{cancelLabel}</Button>
                        {/* Focused on open so Enter confirms and Escape cancels, the
                            same reflexes the native dialog trained people on. */}
                        <Button
                            variant={danger ? 'danger' : 'primary'}
                            onClick={() => onSettle(true)}
                            autoFocus
                        >
                            {confirmLabel}
                        </Button>
                    </div>
                </div>
            </Card>
        </div>
    );
}
