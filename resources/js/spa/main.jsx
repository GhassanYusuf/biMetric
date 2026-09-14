import '../../css/app.css';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { AuthProvider } from './shared/auth';
import { ConfirmProvider } from './shared/confirm';
import { ErrorBoundary } from './shared/errors';
import { ToastProvider } from './shared/toast';
import App from './App';

// Bootstraps the API-first SPA. The whole app is client-rendered here; the
// Laravel web route only shipped the shell. basename '/app' keeps client
// routing under /app so it coexists with the legacy Inertia app during cut-over.
//
// ToastProvider and ConfirmProvider sit above everything so any screen can report
// an outcome or await a real dialog. Native window.confirm/alert/prompt and inline
// result banners are both banned project-wide (see CLAUDE.md); a test enforces the
// dialog half.
//
// The OUTER ErrorBoundary is the last resort, and is separate from the one inside
// App on purpose. This one catches a throw in the providers themselves — an
// AuthProvider that chokes on a malformed /auth/me, a ToastProvider that fails to
// mount — which sit ABOVE App and so are invisible to the boundary within it.
// Without this, exactly those crashes still produce the white screen.
//
// It cannot use the toast (the provider it would need may be the thing that
// broke), so it only renders its recovery screen. The inner boundary handles
// everything below, and re-keys on the route so navigation recovers.
createRoot(document.getElementById('spa-root')).render(
    <ErrorBoundary>
        <BrowserRouter basename="/app">
            <ToastProvider>
                <ConfirmProvider>
                    <AuthProvider>
                        <App />
                    </AuthProvider>
                </ConfirmProvider>
            </ToastProvider>
        </BrowserRouter>
    </ErrorBoundary>,
);
