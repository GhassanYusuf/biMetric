import { useCallback, useEffect, useRef, useState } from 'react';

/*
| Has a newer build been deployed since this tab loaded?
|
| WHY (2026-09-24): three times in one day an operator worked in a tab opened before a
| deploy and got the OLD behaviour — a push without the ID change, a switch that did
| not exist, a save that never reached the server. The SPA never reloads on its own,
| so nothing told them.
|
| How: the Vite manifest names the current entry file (`main-<hash>.js`); the tab
| knows the one it loaded from its own <script src>. Different hash = newer build.
| Read with no-store so a cached manifest cannot hide a deploy. Any failure reads as
| "not newer" — a check that cannot run must never force a reload.
*/
const ENTRY = 'resources/js/spa/main.jsx';

function loadedEntry() {
    const script = document.querySelector('script[type="module"][src*="/build/assets/main-"]');
    return script ? script.getAttribute('src').split('/').pop() : null;
}

async function deployedEntry() {
    const res = await fetch(`/build/manifest.json?t=${Date.now()}`, { cache: 'no-store' });
    if (!res.ok) return null;
    const manifest = await res.json();
    return manifest?.[ENTRY]?.file?.split('/').pop() ?? null;
}

export function useDeployedVersion({ intervalMs = 5 * 60 * 1000 } = {}) {
    const [newer, setNewer] = useState(false);
    const lastCheck = useRef(0);
    const loaded = useRef(loadedEntry());

    const check = useCallback(async () => {
        // Dev server (no built entry) or checked in the last 30 s: nothing to do.
        if (!loaded.current || Date.now() - lastCheck.current < 30000) return newer;
        lastCheck.current = Date.now();
        try {
            const deployed = await deployedEntry();
            const isNewer = deployed !== null && deployed !== loaded.current;
            setNewer(isNewer);
            return isNewer;
        } catch {
            return false;
        }
    }, [newer]);

    useEffect(() => {
        const onFocus = () => { check(); };
        window.addEventListener('focus', onFocus);
        const timer = setInterval(check, intervalMs);
        check();
        return () => {
            window.removeEventListener('focus', onFocus);
            clearInterval(timer);
        };
    }, [check, intervalMs]);

    return { newer, check };
}
