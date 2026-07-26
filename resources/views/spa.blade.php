<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#4f46e5">
    <title>{{ config('app.name', 'Payroll') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

    {{-- Runtime config the SPA reads (API base + MQTT-over-WebSocket URL). --}}
    <script>
        window.__APP_CONFIG__ = {
            apiBase: '/api',
            mqttUrl: @json(config('services.mqtt.ws_url')),
        };
    </script>

    @viteReactRefresh
    @vite('resources/js/spa/main.jsx')
</head>
<body class="h-full bg-[#f6f7fb] font-sans text-slate-900 antialiased">
    {{-- The whole app renders here; the web route only ships this shell. --}}
    <div id="spa-root" class="h-full"></div>
</body>
</html>
