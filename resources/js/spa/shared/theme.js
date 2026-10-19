// Design tokens for the "Panze-style" help-desk SaaS look: violet primary,
// airy light surfaces, soft rounded cards, rainbow categorical charts, green
// positive trends. Centralised so every screen and chart stays in one system.

export const BRAND = {
    primary: '#7c3aed', // violet-600
    primarySoft: '#f5f3ff', // violet-50
};

// Rainbow categorical palette (matches the reference donut/bars order).
export const CATEGORY_COLORS = [
    '#22c55e', // green
    '#3b82f6', // blue
    '#8b5cf6', // violet
    '#ec4899', // pink
    '#eab308', // yellow
    '#f97316', // orange
    '#06b6d4', // cyan
    '#f43f5e', // rose
];

// Attendance status → colour, reused by dashboard donut + legends.
export const STATUS_COLORS = {
    present: '#22c55e',
    early: '#22c55e',
    come_late: '#f97316',
    late: '#f97316',
    leave_on_time: '#3b82f6',
    leave_late: '#8b5cf6',
    absent: '#f43f5e',
    excused: '#eab308',
    missed_punch_out: '#94a3b8',
};

export const TREND = {
    up: '#16a34a',
    down: '#e11d48',
    flat: '#64748b',
};

/*
| Elevation is declared ONCE per surface — a shadow OR a border, never both, so
| nothing reads as a ghost card. Each level carries a real offset and blur.
|
| card     resting surfaces
| raised   hover / active surfaces and popovers
| overlay  command palette, dropdowns, sheets
|
| `menu` breaks the rule on purpose. The rule exists so a RESTING card cannot
| read as a ghost. A dropdown floating over content is the opposite case: there
| is nothing behind it saying where it stops. Dropped over the employee table —
| itself white, with a hover tint on the row underneath — a shadow alone left
| the panel unreadable (2026-10-05). The hairline gives it an edge; the shadow
| lifts it off the row.
*/
export const ELEVATION = {
    card: 'shadow-[0_1px_2px_rgba(16,24,40,0.04),0_4px_16px_-8px_rgba(16,24,40,0.08)]',
    raised: 'shadow-[0_2px_4px_rgba(16,24,40,0.06),0_12px_28px_-12px_rgba(16,24,40,0.16)]',
    overlay: 'shadow-[0_8px_16px_rgba(16,24,40,0.08),0_32px_64px_-24px_rgba(16,24,40,0.28)]',
    menu: 'ring-1 ring-slate-900/10 shadow-[0_10px_20px_-6px_rgba(16,24,40,0.18),0_28px_56px_-20px_rgba(16,24,40,0.32)]',
};

// Cards sit at 16px; only small controls get pill/12px radii.
export const RADIUS = { card: 'rounded-2xl', control: 'rounded-xl', pill: 'rounded-full' };

// Focus is keyboard-only so pointer users never see a ring, but it is always
// present and always the same shape.
export const FOCUS = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-500/40 focus-visible:ring-offset-2 focus-visible:ring-offset-[#f6f7fb]';

// Trend windows the dashboard range control offers. Mirrors DashboardService::RANGES.
export const RANGES = [
    { days: 7, label: 'Last 7 days', short: '7 days' },
    { days: 30, label: 'Last 30 days', short: '30 days' },
    { days: 90, label: 'Last 90 days', short: '90 days' },
];
