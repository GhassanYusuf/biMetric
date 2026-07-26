import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

/*
| Lets a page contribute its own controls to the shell's top bar.
|
| The alternative was a permanent global range/filter control, but a control
| that only affects one screen must not sit in the chrome of every screen. A
| page registers what it owns; when you navigate away it unregisters, so the
| bar never shows an affordance that does nothing here.
*/

const TopBarContext = createContext(null);

export function TopBarProvider({ children }) {
    const [slot, setSlot] = useState(null);
    const value = useMemo(() => ({ slot, setSlot }), [slot]);

    return <TopBarContext.Provider value={value}>{children}</TopBarContext.Provider>;
}

/**
 * Registers `render` — a function returning the controls — for as long as the
 * calling page is mounted. `deps` behaves like a useEffect dep list; keep it
 * to the values the controls actually read.
 */
export function useTopBarSlot(render, deps = []) {
    const ctx = useContext(TopBarContext);
    const setSlot = ctx?.setSlot;

    // eslint-disable-next-line react-hooks/exhaustive-deps
    const stable = useCallback(render, deps);

    useEffect(() => {
        if (!setSlot) return undefined;
        setSlot(stable);
        return () => setSlot(null);
    }, [setSlot, stable]);
}

/** Renders whatever the current page registered. */
export function TopBarSlot() {
    const ctx = useContext(TopBarContext);
    const render = ctx?.slot;

    return typeof render === 'function' ? render() : (render ?? null);
}
