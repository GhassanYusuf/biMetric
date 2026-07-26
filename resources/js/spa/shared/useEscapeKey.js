import { useEffect } from 'react';

/**
 * Closes an overlay on Escape.
 *
 * Every dialog in the app needs this and each one was doing it differently — or
 * not at all, which trapped keyboard users in a modal they could only leave with
 * the mouse. `enabled` lets a dialog refuse dismissal while a write is in
 * flight, matching how the backdrop click is already guarded.
 */
export function useEscapeKey(onEscape, enabled = true) {
    useEffect(() => {
        if (!enabled) return undefined;

        function onKey(event) {
            if (event.key === 'Escape') {
                event.stopPropagation();
                onEscape();
            }
        }

        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onEscape, enabled]);
}
