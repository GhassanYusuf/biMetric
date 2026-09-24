import { api } from './api';

/*
| The live fallback for CLAUDE.md rule 18: when MQTT is not available, ONE poller per
| tab asks GET /api/live for a stamp per module and tells subscribers whose module's
| stamp moved. useMqtt routes to this automatically, so a page subscribes the same way
| whichever carrier is running.
|
| - Polls only while someone is subscribed and the tab is VISIBLE; checks at once when
|   the tab comes back, so a return to the tab is never stale.
| - The first answer only records the baseline — nobody is told "changed" on load.
| - A failed poll is silent and changes nothing: a network blip must not trigger a
|   storm of refetches.
*/
const INTERVAL_MS = 4000;

const subscribers = new Map(); // id -> { module, onChange }
let nextId = 1;
let stamps = null;
let timer = null;
let inFlight = false;

/** Which /api/live module a topic belongs to, or null if none does. */
export function moduleOfTopic(topic) {
    if (/\/notifications$/.test(topic)) return 'notifications';
    const m = topic.match(/\/module\/([a-z_]+)/);
    return m ? m[1] : null;
}

async function poll() {
    if (inFlight || document.visibilityState !== 'visible' || subscribers.size === 0) return;
    inFlight = true;
    try {
        const res = await api.get('/live');
        const next = res.data ?? {};
        if (stamps !== null) {
            const changed = Object.keys(next).filter((k) => next[k] !== stamps[k]);
            if (changed.length > 0) {
                subscribers.forEach((s) => { if (changed.includes(s.module)) s.onChange(); });
            }
        }
        stamps = next;
    } catch {
        /* keep the last stamps; try again next tick */
    } finally {
        inFlight = false;
    }
}

function onVisible() {
    if (document.visibilityState === 'visible') poll();
}

function start() {
    if (timer) return;
    timer = setInterval(poll, INTERVAL_MS);
    document.addEventListener('visibilitychange', onVisible);
    poll();
}

function stop() {
    clearInterval(timer);
    timer = null;
    document.removeEventListener('visibilitychange', onVisible);
    stamps = null;
}

/** Subscribe to a module; returns an unsubscribe function. */
export function subscribeLive(module, onChange) {
    const id = nextId++;
    subscribers.set(id, { module, onChange });
    start();
    return () => {
        subscribers.delete(id);
        if (subscribers.size === 0) stop();
    };
}
