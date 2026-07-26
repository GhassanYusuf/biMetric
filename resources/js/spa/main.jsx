import '../../css/app.css';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { AuthProvider } from './shared/auth';
import { ConfirmProvider } from './shared/confirm';
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
createRoot(document.getElementById('spa-root')).render(
    <BrowserRouter basename="/app">
        <ToastProvider>
            <ConfirmProvider>
                <AuthProvider>
                    <App />
                </AuthProvider>
            </ConfirmProvider>
        </ToastProvider>
    </BrowserRouter>,
);
