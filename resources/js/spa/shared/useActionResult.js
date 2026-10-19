import { useCallback, useEffect, useRef, useState } from 'react';

/*
| The other half of Button's `result` prop (2026-10-18): which control just finished,
| and how. `flash(key, 'success' | 'error', label?)` shows it on that control for a
| moment and then clears it; `resultOf(key)` is what the control reads.
|
| Keys are the caller's — `${deviceId}:sync`, 'push', a finger slot. One hook per page
| or dialog; flashing a key again restarts its timer rather than stacking.
*/
const HOLD_MS = 2200;

export function useActionResult(holdMs = HOLD_MS) {
    const [results, setResults] = useState({});
    const timers = useRef({});

    useEffect(() => () => Object.values(timers.current).forEach(clearTimeout), []);

    const flash = useCallback((key, result, label) => {
        clearTimeout(timers.current[key]);
        setResults((r) => ({ ...r, [key]: { result, label } }));
        timers.current[key] = setTimeout(() => {
            delete timers.current[key];
            setResults((r) => {
                const next = { ...r };
                delete next[key];
                return next;
            });
        }, holdMs);
    }, [holdMs]);

    const resultOf = useCallback((key) => results[key]?.result ?? null, [results]);
    const outcomeOf = useCallback((key) => results[key] ?? null, [results]);

    return { flash, resultOf, outcomeOf };
}
