import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

// The dev server must be reachable from other machines on the LAN, because the
// Laravel server is bound to 0.0.0.0 and operators open the app from their own
// desks (see APP_URL). laravel-vite-plugin writes public/hot from, in order:
// server.hmr.host, then server.host, then whatever address Node resolved. Left
// alone that last branch wins and it is "[::1]" — IPv6 loopback — so every
// asset URL in the page is unreachable from anywhere but this box.
//
// So: bind every interface, and pin the *advertised* host to a real address.
// VITE_DEV_HOST overrides; otherwise the hostname from APP_URL is used, which
// is already the address operators type.
function devServerHost(mode) {
    const env = loadEnv(mode, process.cwd(), '');

    if (env.VITE_DEV_HOST) {
        return env.VITE_DEV_HOST;
    }

    try {
        return new URL(env.APP_URL).hostname;
    } catch {
        return 'localhost';
    }
}

export default defineConfig(({ mode }) => ({
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
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: devServerHost(mode),
        },
    },
}));
