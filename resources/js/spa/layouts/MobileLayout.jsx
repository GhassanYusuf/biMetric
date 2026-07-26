import { useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../shared/auth';
import { useLastSync, shortAgo } from '../shared/useLastSync';
import { TopBarSlot } from '../shared/topbar';
import { ELEVATION, FOCUS } from '../shared/theme';
import { Icon, Logo, MOBILE_BAR_ITEMS, MOBILE_MORE_ITEMS } from './nav';

// Mobile chrome: compact top bar + fixed bottom tab bar with large tap targets
// and the violet active state. Mobile-first priority experience.
//
// The bar holds four destinations plus a "More" tab; six flex-1 tabs would
// squeeze the 11px labels past legibility, so the overflow lives in a sheet.
export default function MobileLayout({ unread, onOpenPalette }) {
    const { user, logout, can } = useAuth();
    const { pathname } = useLocation();
    const [moreOpen, setMoreOpen] = useState(false);
    const sync = useLastSync();

    const allowed = (list) => list.filter((i) => !i.permission || can(i.permission));
    const items = allowed(MOBILE_BAR_ITEMS);
    const moreItems = allowed(MOBILE_MORE_ITEMS);
    const moreActive = moreItems.some((i) => i.to === pathname);

    const firstName = user?.name?.split(' ')[0] ?? '';
    const hour = new Date().getHours();
    const greeting = hour < 12 ? 'Morning' : hour < 18 ? 'Afternoon' : 'Evening';

    return (
        <div className="flex h-full flex-col bg-[#f6f7fb]">
            {/* Same information as the desktop bar, stacked and condensed: identity
                and sync freshness on one line, page controls beneath. */}
            <header className="px-4 pb-1 pt-3.5">
                <div className="flex items-center gap-2">
                    <Logo compact />
                    <div className="min-w-0 flex-1 leading-tight">
                        <div className="truncate text-sm font-semibold tracking-[-0.01em] text-slate-900">
                            {greeting}{firstName && `, ${firstName}`}
                        </div>
                        {sync.available && (
                            <div className="flex items-center gap-1.5 text-[11px] text-slate-500">
                                <span
                                    className={`h-1.5 w-1.5 rounded-full ${
                                        sync.empty ? 'bg-slate-300' : sync.errored > 0 ? 'bg-rose-500' : sync.at ? 'bg-emerald-500' : 'bg-slate-300'
                                    }`}
                                    aria-hidden="true"
                                />
                                {sync.empty ? 'No devices' : sync.errored > 0 ? `${sync.errored} failing` : `Synced ${shortAgo(sync.at)}`}
                            </div>
                        )}
                    </div>

                    <button
                        onClick={onOpenPalette}
                        aria-label="Search"
                        className={`inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-slate-600 ${ELEVATION.card} ${FOCUS}`}
                    >
                        <Icon name="search" className="h-[18px] w-[18px]" />
                    </button>

                    <NavLink
                        to="/notifications"
                        aria-label={unread.count > 0 ? `Alerts, ${unread.count} unread` : 'Alerts'}
                        className={`relative inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-slate-600 ${ELEVATION.card} ${FOCUS}`}
                    >
                        <Icon name="bell" className="h-[18px] w-[18px]" />
                        {unread.count > 0 && (
                            <span className="absolute -right-0.5 -top-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white ring-2 ring-[#f6f7fb]">
                                {unread.count > 99 ? '99+' : unread.count}
                            </span>
                        )}
                    </NavLink>
                </div>

                <div className="mt-2 flex flex-wrap items-center gap-2 empty:mt-0">
                    <TopBarSlot />
                </div>
            </header>

            <main className="flex-1 overflow-y-auto px-4 pb-24 pt-1">
                <Outlet />
            </main>

            <nav className="fixed inset-x-0 bottom-0 z-10 flex border-t border-slate-200 bg-white/95 backdrop-blur pb-[env(safe-area-inset-bottom)]">
                {items.map((item) => (
                    <NavLink
                        key={item.to}
                        to={item.to}
                        className={({ isActive }) =>
                            `relative flex flex-1 flex-col items-center gap-0.5 py-2.5 text-[11px] font-medium ${
                                isActive ? 'text-violet-600' : 'text-slate-400'
                            }`
                        }
                    >
                        {({ isActive }) => (
                            <>
                                <span className={`flex h-8 w-12 items-center justify-center rounded-full ${isActive ? 'bg-violet-50' : ''}`}>
                                    <Icon name={item.icon} className="h-[18px] w-[18px]" />
                                </span>
                                <span>{item.label}</span>
                                {item.to === '/notifications' && unread.count > 0 && (
                                    <span className="absolute right-1/4 top-1 rounded-full bg-rose-500 px-1.5 text-[10px] font-semibold text-white">
                                        {unread.count}
                                    </span>
                                )}
                            </>
                        )}
                    </NavLink>
                ))}

                {moreItems.length > 0 && (
                    <button
                        onClick={() => setMoreOpen(true)}
                        aria-expanded={moreOpen}
                        className={`relative flex flex-1 flex-col items-center gap-0.5 py-2.5 text-[11px] font-medium ${
                            moreActive ? 'text-violet-600' : 'text-slate-400'
                        }`}
                    >
                        <span className={`flex h-8 w-12 items-center justify-center rounded-full ${moreActive ? 'bg-violet-50' : ''}`}>
                            <Icon name="more" className="h-[18px] w-[18px]" />
                        </span>
                        <span>More</span>
                        {unread.count > 0 && <span className="absolute right-1/4 top-1 h-2 w-2 rounded-full bg-rose-500" />}
                    </button>
                )}
            </nav>

            {moreOpen && (
                <div className="fixed inset-0 z-20 flex items-end bg-slate-900/40" onClick={() => setMoreOpen(false)}>
                    <div
                        className="w-full rounded-t-2xl bg-white pb-[env(safe-area-inset-bottom)]"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="mx-auto mt-2.5 h-1 w-10 rounded-full bg-slate-200" />
                        <div className="p-3">
                            {moreItems.map((item) => (
                                <NavLink
                                    key={item.to}
                                    to={item.to}
                                    onClick={() => setMoreOpen(false)}
                                    className={({ isActive }) =>
                                        `flex items-center gap-3 rounded-xl px-3 py-3.5 text-sm font-medium ${
                                            isActive ? 'bg-violet-50 text-violet-700' : 'text-slate-600'
                                        }`
                                    }
                                >
                                    <Icon name={item.icon} />
                                    <span className="flex-1">{item.label}</span>
                                    {item.to === '/notifications' && unread.count > 0 && (
                                        <span className="rounded-full bg-rose-500 px-1.5 text-[11px] font-semibold text-white">
                                            {unread.count}
                                        </span>
                                    )}
                                </NavLink>
                            ))}

                            <div className="my-1.5 h-px bg-slate-100" />

                            <div className="px-3 pb-1 pt-1.5">
                                <div className="truncate text-sm font-semibold text-slate-900">{user?.name}</div>
                                <div className="truncate text-xs text-slate-500">
                                    {user?.email}{user?.tenant?.name ? ` · ${user.tenant.name}` : ''}
                                </div>
                            </div>
                            <button
                                onClick={logout}
                                className={`flex w-full items-center gap-3 rounded-xl px-3 py-3.5 text-left text-sm font-medium text-slate-700 hover:bg-rose-50 hover:text-rose-700 ${FOCUS}`}
                            >
                                <Icon name="logout" />
                                Sign out
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
