import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import DangerButton from '@/Components/DangerButton';
import SecondaryButton from '@/Components/SecondaryButton';

/*
| Promise-based confirmation for the legacy Inertia app, mirroring the SPA's
| shared/confirm.jsx API so both halves of the project read the same way.
|
| NEVER use window.confirm / alert / prompt (see CLAUDE.md). Built on the existing
| headlessui Modal so it inherits this app's focus trap and transitions.
|
|     if (!(await confirm({ title: 'Remove device?', danger: true }))) return;
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

    const paragraphs = request?.body == null
        ? []
        : Array.isArray(request.body) ? request.body.filter(Boolean) : [request.body];

    const Action = request?.danger ? DangerButton : PrimaryButton;

    return (
        <ConfirmContext.Provider value={value}>
            {children}

            <Modal show={!!request} maxWidth="md" onClose={() => settle(false)}>
                {request && (
                    <div className="p-6">
                        <h2 className="text-lg font-semibold text-gray-900">{request.title}</h2>

                        {paragraphs.length > 0 && (
                            <div className="mt-2 space-y-2">
                                {paragraphs.map((text, i) => (
                                    <p key={i} className="text-sm leading-relaxed text-gray-600">{text}</p>
                                ))}
                            </div>
                        )}

                        <div className="mt-6 flex justify-end gap-3">
                            <SecondaryButton onClick={() => settle(false)}>
                                {request.cancelLabel ?? 'Cancel'}
                            </SecondaryButton>
                            <Action onClick={() => settle(true)}>
                                {request.confirmLabel ?? 'Confirm'}
                            </Action>
                        </div>
                    </div>
                )}
            </Modal>
        </ConfirmContext.Provider>
    );
}

export function useConfirm() {
    const ctx = useContext(ConfirmContext);

    if (!ctx) {
        throw new Error('useConfirm must be used within a ConfirmProvider');
    }

    return ctx.confirm;
}
