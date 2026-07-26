// Sidebar navigation, grouped into labelled sections like the reference
// ("MENU", "WORKFORCE"). Shared by both mobile and desktop chrome. `permission`
// (when set) gates the item against the user's effective permissions.
//
// Sections are collapsible. An item may also carry `children` — a nested list
// rendered as an indented, collapsible tree (the reference's "Ticket
// Configuration" pattern). Devices uses this for its four supporting screens.
// Add `children: [{ to, label, permission }]` to an item and it renders as a tree.
// `mobileBar: true` promotes an item into the mobile bottom tab bar. Only four
// fit comfortably beside the "More" tab; everything else stays reachable there.
export const NAV_SECTIONS = [
    {
        label: 'Menu',
        items: [
            { to: '/dashboard', label: 'Dashboard', icon: 'grid', mobileBar: true },
            { to: '/notifications', label: 'Alerts', icon: 'bell' },
        ],
    },
    {
        label: 'Workforce',
        items: [
            { to: '/employees', label: 'Employees', icon: 'users', permission: 'employees.view', mobileBar: true },
            { to: '/attendance', label: 'Attendance', icon: 'clock', permission: 'attendance.view', mobileBar: true },
            { to: '/holidays', label: 'Holidays', icon: 'calendar', permission: 'holidays.view' },
        ],
    },
    {
        label: 'Hardware',
        items: [
            {
                to: '/devices',
                label: 'Devices',
                icon: 'device',
                permission: 'devices.view',
                mobileBar: true,
                /*
                 * The device layer's four supporting screens. Nested rather than
                 * promoted to top level: each one only makes sense in the context of
                 * the terminals, and a flat sidebar of five hardware entries buries
                 * the workforce sections that most users open far more often.
                 */
                children: [
                    { to: '/device-groups', label: 'Groups', icon: 'sliders', permission: 'devices.view' },
                    { to: '/device-sync-runs', label: 'Sync history', icon: 'refresh', permission: 'devices.view' },
                    { to: '/enrollment-sessions', label: 'Enrollments', icon: 'bolt', permission: 'employees.view' },
                    { to: '/reconciliation', label: 'Reconciliation', icon: 'search', permission: 'devices.view' },
                ],
            },
        ],
    },
];

// Flat list of every top-level destination.
export const NAV_ITEMS = NAV_SECTIONS.flatMap((s) => s.items);

// The four bottom-bar tabs.
export const MOBILE_BAR_ITEMS = NAV_ITEMS.filter((i) => i.mobileBar);

/*
| Everything the "More" sheet lists.
|
| Nested children are FLATTENED in here rather than skipped. The mobile chrome has no
| tree affordance, so a child that only appears in the desktop sidebar would be
| unreachable on a phone — a whole screen that exists but cannot be opened.
*/
export const MOBILE_MORE_ITEMS = NAV_ITEMS.flatMap((item) => [
    ...(item.mobileBar ? [] : [item]),
    ...(item.children ?? []),
]);

export function Icon({ name, className = 'h-[18px] w-[18px]' }) {
    const paths = {
        grid: 'M4 4h6v6H4zM14 4h6v6h-6zM14 14h6v6h-6zM4 14h6v6H4z',
        users: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z',
        bell: 'M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1',
        search: 'M11 4a7 7 0 105.2 11.7L21 20M11 4a7 7 0 015.2 11.7',
        globe: 'M12 3a9 9 0 100 18 9 9 0 000-18zM3 12h18M12 3c2.5 2.5 3.5 6 3.5 9s-1 6.5-3.5 9c-2.5-2.5-3.5-6-3.5-9s1-6.5 3.5-9z',
        chevron: 'M6 9l6 6 6-6',
        clock: 'M12 3a9 9 0 100 18 9 9 0 000-18zM12 7v5l3.5 2',
        calendar: 'M7 3v3M17 3v3M4 8h16M5 6h14a1 1 0 011 1v12a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1z',
        device: 'M7 3h10a1 1 0 011 1v16a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zM9 7h6M9 11h6M10 17h4',
        more: 'M5 12h.01M12 12h.01M19 12h.01',
        refresh: 'M20 12a8 8 0 11-2.34-5.66M20 4v4h-4',
        bolt: 'M13 3L5 14h5l-1 7 8-11h-5l1-7z',
        logout: 'M15 17l5-5-5-5M20 12H9M12 3H6a1 1 0 00-1 1v16a1 1 0 001 1h6',
        sliders: 'M4 8h10M18 8h2M4 16h4M12 16h8M15 5v6M9 13v6',
    };
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
            <path d={paths[name] || paths.grid} />
        </svg>
    );
}

// The violet mark used as the logo lockup.
export function Logo({ compact = false }) {
    return (
        <div className="flex items-center gap-2">
            <div className="flex h-8 w-8 items-center justify-center rounded-xl bg-violet-600 text-sm font-bold text-white shadow-sm shadow-violet-600/30">b</div>
            {!compact && (
                <div className="text-[15px] font-bold tracking-tight text-slate-900">
                    biMetric<span className="text-violet-500">.</span>
                </div>
            )}
        </div>
    );
}
