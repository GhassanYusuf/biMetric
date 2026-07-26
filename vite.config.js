import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            // Two entries during cut-over: the legacy Inertia app (app.jsx) and
            // the new API-first SPA (spa/main.jsx). The SPA is the target
            // architecture; the Inertia app is retired module by module.
            input: ['resources/js/app.jsx', 'resources/js/spa/main.jsx'],
            refresh: true,
        }),
        react(),
    ],
});
