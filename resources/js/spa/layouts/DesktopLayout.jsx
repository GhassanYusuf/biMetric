import { useState } from 'react';
import { NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../shared/auth';
import { FOCUS } from '../shared/theme';
import TopBar from './TopBar';
import { Icon, Logo, NAV_SECTIONS } from './nav';

const ITEM_BASE = `flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors duration-150 ${FOCUS}`;
// The active pill carries a violet left marker as well as the tint, so the
// current page survives a greyscale render.
const ITEM_ACTIVE = 'bg-violet-50 text-violet-700';
const ITEM_IDLE = 'text-slate-600 hover:bg-slate-50 hover:text-slate-900';

function Chevron({ open }) {
    return (
        <Icon
            name="chevron"
            className={`h-4 w-4 shrink-0 transition-transform duration-200 ${open ? '' : '-rotate-90'}`}
        />
    );
}

/*
| One sidebar row: icon, label, violet active marker, and the alerts count.
|
| Every item is one of these. The nav has no nesting (see nav.jsx): Devices used to
| render instead as a disclosure <button> holding the other hardware screens, which
| made the one page it pointed at unreachable from the sidebar and left it
| unhighlighted while you were standing on it. Four plain siblings cost one row each
| and have neither problem.
*/
function NavItem({ item, unread }) {
    return (
        <NavLink
            to={item.to}
            className={({ isActive }) => `relative ${ITEM_BASE} ${isActive ? ITEM_ACTIVE : ITEM_IDLE}`}
        >
            {({ isActive }) => (
                <>
                    {isActive && (
                        <span className="absolute left-0 top-1/2 h-5 w-[3px] -translate-y-1/2 rounded-r-full bg-violet-600" aria-hidden="true" />
                    )}
                    <Icon name={item.icon} />
                    <span>{item.label}</span>
                    {item.to === '/notifications' && unread.count > 0 && (
                        <span className="ml-auto rounded-full bg-rose-500 px-1.5 text-[11px] font-semibold text-white">
                            {unread.count}
                        </span>
                    )}
                </>
            )}
        </NavLink>
    );
}

// Collapsible section ("MENU", "WORKFORCE") — the header itself is the toggle.
function NavSection({ section, unread, can }) {
    const items = section.items.filter((i) => !i.permission || can(i.permission));
    const [open, setOpen] = useState(true);

    if (!items.length) return null;

    return (
        <div className="mb-6">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                className={`flex w-full items-center gap-1.5 rounded-lg px-3 pb-2 pt-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500 transition-colors duration-150 hover:text-slate-700 ${FOCUS}`}
            >
                <span className="flex-1 text-left">{section.label}</span>
                <Chevron open={open} />
            </button>
            {open && (
                <div className="space-y-1">
                    {items.map((item) => (
                        <NavItem key={item.to || item.label} item={item} unread={unread} />
                    ))}
                </div>
            )}
        </div>
    );
}

// Desktop chrome: white left sidebar with collapsible labelled sections and a
// violet active pill, plus the pill-card top bar.
export default function DesktopLayout({ unread, onOpenPalette }) {
    const { can } = useAuth();

    return (
        <div className="flex h-full bg-[#f6f7fb]">
            {/* Sidebar — a second, cooler neutral layer against the content canvas. */}
            <aside className="flex w-64 shrink-0 flex-col border-r border-slate-200/70 bg-white">
                <div className="px-6 py-5">
                    <Logo />
                </div>
                <nav className="flex-1 overflow-y-auto px-4 pb-4">
                    {NAV_SECTIONS.map((section) => (
                        <NavSection key={section.label} section={section} unread={unread} can={can} />
                    ))}
                </nav>
            </aside>

            {/* Main column */}
            <div className="flex min-w-0 flex-1 flex-col">
                <TopBar unread={unread} onOpenPalette={onOpenPalette} />

                {/* Page content always spans the full available width — no centred
                    max-width wrapper. Wide tables get the whole viewport. */}
                <main className="flex-1 overflow-y-auto px-8 pb-10">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
