import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { ConfirmProvider } from '@/Components/ConfirmDialog';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        // Wraps every page so any of them can await a real confirmation dialog.
        // Native window.confirm/alert/prompt are banned project-wide (CLAUDE.md).
        root.render(
            <ConfirmProvider>
                <App {...props} />
            </ConfirmProvider>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});
