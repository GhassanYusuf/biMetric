import { useEffect, useState } from 'react';

// Returns 'mobile' or 'desktop' from the viewport width and keeps it in sync on
// resize. This is what lets each screen pick its SEPARATE mobile/desktop
// presentation file (per the standards) instead of one tangled responsive file.
const BREAKPOINT = 768; // < md => mobile-first layout

export function useDeviceClass() {
    const [deviceClass, setDeviceClass] = useState(() =>
        typeof window !== 'undefined' && window.innerWidth >= BREAKPOINT ? 'desktop' : 'mobile',
    );

    useEffect(() => {
        const mq = window.matchMedia(`(min-width: ${BREAKPOINT}px)`);
        const onChange = () => setDeviceClass(mq.matches ? 'desktop' : 'mobile');
        onChange();
        mq.addEventListener('change', onChange);
        return () => mq.removeEventListener('change', onChange);
    }, []);

    return deviceClass;
}
