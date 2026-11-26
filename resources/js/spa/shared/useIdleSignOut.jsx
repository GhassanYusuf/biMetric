import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from './api';
import { useAuth } from './auth';
import { Button, Card } from './ui';
import { useEscapeKey } from './useEscapeKey';

/*
| Website sign-ins end after 15 minutes without the person doing anything, and 8 hours at most
| (owner, 2026-11-11; the SERVER enforces both: App\Support\WebSession). This is the page's half:
| a warning two minutes before the idle limit, with "Stay signed in", and a clean sign-out at
| the limit, so nobody meets it as a failed click.
|
| Activity is shared between tabs (localStorage): working in one tab keeps the others signed in.
| Background polls (live updates, unread count, sync progress) are sent with X-Background and do
| not count on the server either.
*/
export const IDLE_MS = 15 * 60 * 1000;
const WARN_MS = 2 * 60 * 1000;
const KEY = 'session.lastActivity';
export const SIGNED_OUT_KEY = 'session.signedOutReason';

const now = () => Date.now();
const read = () => { try { return Number(localStorage.getItem(KEY)) || now(); } catch { return now(); } };
const write = (t) => { try { localStorage.setItem(KEY, String(t)); } catch { /* private window: this tab only */ } };

export function useIdleSignOut() {
    const { user, logout } = useAuth();
    const [left, setLeft] = useState(null); // ms until sign-out while warning, else null
    const lastWrite = useRef(0);
    const lastPing = useRef(now());

    const activity = useCallback(() => {
        const t = now();
        if (t - lastWrite.current > 15000) { lastWrite.current = t; write(t); }
        /*
         * Typing into a form sends nothing to the server, which counts only requests — so 15 minutes spent
         * filling in a long form signed the person out on the server while this page still thought them
         * active, and Save met "Unauthenticated." (2026-11-26). While they are actually working, tell the
         * server at most every 4 minutes. A request the person made (no X-Background), so it counts.
         */
        if (t - lastPing.current > 4 * 60 * 1000) {
            lastPing.current = t;
            api.get('/auth/me').catch(() => { /* a 401 here signs out with a reason */ });
        }
    }, []);

    const stay = useCallback(async () => {
        write(now());
        setLeft(null);
        // A request the person made: the server marks the sign-in active again.
        try { await api.get('/auth/me'); } catch { /* the 401 path signs out */ }
    }, []);

    useEffect(() => {
        if (!user) return undefined;
        write(now());
        const events = ['pointerdown', 'keydown', 'wheel', 'touchstart', 'scroll'];
        events.forEach((e) => window.addEventListener(e, activity, { passive: true }));
        const tick = setInterval(() => {
            const idle = now() - read();
            if (idle >= IDLE_MS) {
                clearInterval(tick);
                try { sessionStorage.setItem(SIGNED_OUT_KEY, 'You were signed out after 15 minutes without activity. Sign in again to continue.'); } catch { /* fine */ }
                logout();
            } else if (idle >= IDLE_MS - WARN_MS) {
                setLeft(IDLE_MS - idle);
            } else {
                setLeft(null);
            }
        }, 1000);
        return () => { clearInterval(tick); events.forEach((e) => window.removeEventListener(e, activity)); };
    }, [user, activity, logout]);

    const dialog = left === null ? null : <IdleWarning left={left} onStay={stay} onSignOut={logout} />;
    return dialog;
}

function IdleWarning({ left, onStay, onSignOut }) {
    useEscapeKey(onStay);
    const s = Math.max(0, Math.ceil(left / 1000));
    return (
        <div className="fixed inset-0 z-[80] flex items-end justify-center bg-slate-900/50 p-0 sm:items-center sm:p-4" role="presentation">
            <Card className="w-full max-w-sm rounded-b-none p-5 sm:rounded-2xl" role="alertdialog" aria-modal="true" aria-labelledby="idle-title" aria-describedby="idle-body">
                <h2 id="idle-title" className="text-base font-semibold text-slate-900">Still there?</h2>
                <p id="idle-body" className="mt-1 text-sm text-slate-600" aria-live="polite">
                    {`You'll be signed out in ${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')} because nothing has been done for a while.`}
                </p>
                <div className="mt-4 flex justify-end gap-2">
                    <Button variant="secondary" onClick={onSignOut}>Sign out now</Button>
                    <Button onClick={onStay} autoFocus>Stay signed in</Button>
                </div>
            </Card>
        </div>
    );
}
