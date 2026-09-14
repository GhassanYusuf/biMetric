// Polls a device's sync progress while a sync is running.
//
// Polling rather than MQTT deliberately: services.mqtt.ws_url is unset in this
// deployment, so the browser has no broker and an MQTT-only bar would never move.
// If a broker is added later this hook is the single place to switch.

import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from './api';

/** How often to ask while a sync is in flight. */
const INTERVAL_MS = 1000;

/**
 * Consecutive failed polls tolerated before giving up.
 *
 * Not one: the endpoint competes with the sync for the same PHP process, and a
 * single timeout is normal rather than a reason to blank the bar an operator is
 * watching.
 */
const MAX_FAILURES = 5;

/**
 * @param {number|null} deviceId  device to watch
 * @param {boolean} active        poll only while this is true (a sync is running)
 * @returns {{progress: object|null, counts: object|null, status: string|null, stalled: boolean}}
 */
export function useSyncProgress(deviceId, active) {
    const [state, setState] = useState({ progress: null, counts: null, status: null, stalled: false });
    const failures = useRef(0);

    const reset = useCallback(() => {
        failures.current = 0;
        setState({ progress: null, counts: null, status: null, stalled: false });
    }, []);

    useEffect(() => {
        if (!active || !deviceId) return undefined;

        let cancelled = false;
        failures.current = 0;

        async function poll() {
            try {
                const { data } = await api.get(`/devices/${deviceId}/sync-progress`);
                if (cancelled) return;

                failures.current = 0;
                setState({
                    progress: data?.progress ?? null,
                    counts: data?.counts ?? null,
                    status: data?.status ?? null,
                    stalled: false,
                });
            } catch {
                if (cancelled) return;

                failures.current += 1;

                // Say "we lost contact with the progress feed", not "the sync
                // failed". The sync is a different request and may be fine; a bar
                // that claims failure it cannot see would be a rule 3 violation in
                // miniature.
                if (failures.current >= MAX_FAILURES) {
                    setState((s) => ({ ...s, stalled: true }));
                }
            }
        }

        poll();
        const id = setInterval(poll, INTERVAL_MS);

        return () => {
            cancelled = true;
            clearInterval(id);
        };
    }, [deviceId, active]);

    // Clear when the caller stops watching, so the next sync starts from nothing
    // rather than flashing the previous run's numbers.
    useEffect(() => {
        if (!active) reset();
    }, [active, reset]);

    return state;
}

/**
 * Turns a progress payload into the two strings a bar shows.
 *
 * Kept here rather than in a component so the desktop and mobile presentations
 * cannot drift apart (rule 5) — they render the same words from the same source.
 */
export function describeProgress(progress, { deviceName } = {}) {
    if (!progress) {
        return { label: deviceName ? `Syncing ${deviceName}…` : 'Syncing…', detail: null, percent: null };
    }

    const { phase, read, total, unit, percent, attempt } = progress;

    const noun = unit === 'bytes' ? 'bytes' : 'records';
    const verb = phase === 'importing' ? 'Importing' : 'Reading';
    const where = deviceName ? ` from ${deviceName}` : '';

    // A retry restarts the transfer from zero. Saying so stops a bar that visibly
    // runs backwards from looking like a bug.
    const retry = attempt > 1 ? ` · retry ${attempt}` : '';

    return {
        percent: percent ?? null,
        label: `${verb}${phase === 'importing' ? '' : where}…${retry}`,
        detail: total
            ? `${read.toLocaleString()} of ${total.toLocaleString()} ${noun}`
            : `${read.toLocaleString()} ${noun} so far`,
    };
}
