// Browser-side error catching.
//
// Three things live here, and they exist because a thrown render error used to
// blank the entire SPA to a white page with nothing but a console message:
//
//   reportClientError()  — ships one crash to POST /api/client-errors
//   <ErrorBoundary>      — catches render/lifecycle throws, shows a recovery screen
//   installGlobalErrorHandlers() — catches what React cannot see
//
// A boundary only catches errors thrown while React renders. An `await api.post()`
// in a click handler that rejects, or a throw inside a setTimeout, never reaches
// it — those are what the global handlers pick up. You need both.

import { Component } from 'react';
import { getToken } from './api';
import { Button } from './ui';

const REPORT_PATH = '/client-errors';

// A crashing component can re-throw on every frame. Reporting each one would turn
// one bug into a request flood, so identical messages are only sent once per load.
const alreadySent = new Set();

/**
 * Ships a crash to the server. Never throws and never rejects — the caller is
 * already in a failure path and a reporter that can itself fail is a trap.
 */
export async function reportClientError(error, { kind = 'render', componentStack } = {}) {
    const message = String(error?.message || error || 'Unknown error');
    const key = `${kind}:${message}`;

    if (alreadySent.has(key)) return;
    alreadySent.add(key);

    // Always leave it in the console too. The server copy is for whoever debugs it
    // later; the console is for whoever is standing at the screen right now.
    console.error('[client-error]', kind, error);

    const token = getToken();
    if (!token) return; // unauthenticated — the endpoint would 401

    const config = window.__APP_CONFIG__ || { apiBase: '/api' };

    try {
        await fetch(`${config.apiBase}${REPORT_PATH}`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
            // keepalive lets the report survive the navigation that often follows
            // a crash, when a normal fetch would be cancelled mid-flight.
            keepalive: true,
            body: JSON.stringify({
                kind,
                message,
                stack: error?.stack ? String(error.stack) : null,
                component_stack: componentStack || null,
                url: window.location.href,
            }),
        });
    } catch {
        // Offline, throttled (429), or blocked. Nothing useful to do: the console
        // already has it, and retrying an error report is how crash loops start.
    }
}

/**
 * Catches a render/lifecycle throw and shows a recovery screen instead of a blank
 * page.
 *
 * Class component because getDerivedStateFromError / componentDidCatch have no
 * hook equivalent — this is the one place a class is still required in React 18.
 *
 * `resetKey` lets a caller clear the error when the route changes, so a crash on
 * one page does not leave the whole app wedged after the user navigates away.
 */
export class ErrorBoundary extends Component {
    constructor(props) {
        super(props);
        this.state = { error: null };
    }

    static getDerivedStateFromError(error) {
        return { error };
    }

    componentDidCatch(error, info) {
        reportClientError(error, { kind: 'render', componentStack: info?.componentStack });
    }

    componentDidUpdate(prev) {
        // Navigating away from a broken screen should recover, not stay stuck.
        if (this.state.error && prev.resetKey !== this.props.resetKey) {
            this.setState({ error: null });
        }
    }

    render() {
        if (!this.state.error) return this.props.children;

        if (this.props.fallback) {
            return this.props.fallback(this.state.error, () => this.setState({ error: null }));
        }

        return <CrashScreen error={this.state.error} onRetry={() => this.setState({ error: null })} />;
    }
}

/**
 * The recovery screen.
 *
 * Not a toast: a toast is for something that HAPPENED and then stops being true
 * (CLAUDE.md rule 2), whereas this screen IS the current state of the page — the
 * content is gone and it stays gone until the user acts. So it is content.
 *
 * It offers two exits because they fail differently: "Try again" re-renders the
 * same subtree and is enough for a transient data shape problem, while a reload
 * is what recovers a genuinely corrupt client state.
 */
function CrashScreen({ error, onRetry }) {
    return (
        <div className="flex min-h-[60vh] items-center justify-center p-6">
            <div className="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-8 text-center">
                <div
                    aria-hidden="true"
                    className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-rose-50 text-2xl"
                >
                    ⚠️
                </div>

                <h1 className="mt-4 text-lg font-semibold text-slate-900">This screen stopped working</h1>

                <p className="mt-2 text-sm text-slate-600">
                    Something went wrong while drawing this page. The rest of the app is fine —
                    the problem has been reported automatically.
                </p>

                {/* The raw message is shown deliberately. Operators here report
                    problems to a developer directly, and "it broke" costs a
                    round-trip that one line of text saves. */}
                <p className="mt-4 break-words rounded-xl bg-slate-50 px-4 py-3 text-left text-xs text-slate-600">
                    {String(error?.message || error)}
                </p>

                <div className="mt-6 flex justify-center gap-3">
                    <Button variant="secondary" onClick={onRetry}>
                        Try again
                    </Button>
                    <Button onClick={() => window.location.reload()}>Reload the app</Button>
                </div>
            </div>
        </div>
    );
}

/**
 * Catches what the boundary structurally cannot: rejected promises with no
 * .catch(), and throws from outside the React render cycle.
 *
 * This is the case the operator describes as "I clicked it and nothing happened" —
 * an await that rejected into the void, leaving no toast, no error, no clue.
 *
 * Call once, at boot.
 */
export function installGlobalErrorHandlers({ onError } = {}) {
    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason;

        // A 401 is not a crash: api.js already cleared the token and the
        // AuthProvider is about to bounce the user to login. Reporting it would
        // bury the real errors under every expired session.
        if (reason?.name === 'ApiError' && reason.status === 401) return;

        reportClientError(reason, { kind: 'unhandledrejection' });
        onError?.(reason);
    });

    window.addEventListener('error', (event) => {
        // Resource load failures (a 404 image) also fire this event but carry no
        // Error object. They are not application crashes.
        if (!event.error) return;

        reportClientError(event.error, { kind: 'window.onerror' });
        onError?.(event.error);
    });
}
