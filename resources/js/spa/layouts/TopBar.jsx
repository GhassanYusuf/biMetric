import { useEffect, useRef, useState } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import { api } from '../shared/api';
import { useAuth } from '../shared/auth';
import { useLastSync, shortAgo } from '../shared/useLastSync';
import { TopBarSlot } from '../shared/topbar';
import { ELEVATION, FOCUS } from '../shared/theme';
import { Spinner } from '../shared/ui';
import { Icon } from './nav';

/*
| The shell's top bar. Each control is its own white pill card floating on the
| canvas, matching the reference's rhythm — but every one is wired to something
| real:
|
|   greeting      time-aware, names the active workspace
|   search        opens the ⌘K palette
|   last sync     newest device sync across the workspace, live over MQTT
|   [page slot]   controls the current page owns (e.g. the dashboard range)
|   sync now      runs a sync on every active device (devices.sync only)
|   alerts        unread notification count
|   account       name, handle, sign out
*/

const PILL = `flex items-center gap-2.5 rounded-2xl bg-white px-3.5 py-2.5 ${ELEVATION.card}`;

export default function TopBar({ unread, onOpenPalette }) {
    const { user, logout, can } = useAuth();
    const sync = useLastSync();

    const firstName = user?.name?.split(' ')[0] ?? '';
    const initials = (user?.name || '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
    const handle = user?.email?.split('@')[0] || (user?.roles || [])[0] || 'member';

    const hour = new Date().getHours();
    const greeting = hour < 12 ? 'Good Morning' : hour < 18 ? 'Good Afternoon' : 'Good Evening';

    return (
        <header className="flex flex-wrap items-center gap-3 px-8 py-4">
            <div className={`${PILL} min-w-0`}>
                <div className="min-w-0 leading-tight">
                    <div className="truncate text-sm font-semibold tracking-[-0.01em] text-slate-900">
                        {greeting}{firstName && `, ${firstName}`}
                    </div>
                    <div className="truncate text-xs text-slate-500">
                        {user?.tenant?.name ? `${user.tenant.name} workspace` : 'Your latest updates here'}
                    </div>
                </div>
            </div>

            <SearchTrigger onClick={onOpenPalette} />

            {sync.available && <LastSyncPill sync={sync} />}

            {/* Whatever the current page registered — unregistered elsewhere. */}
            <TopBarSlot />

            <div className="ml-auto flex items-center gap-2">
                {can('devices.sync') && !sync.empty && <SyncNowButton onDone={sync.refresh} />}

                <NavLink
                    to="/notifications"
                    aria-label={unread.count > 0 ? `Alerts, ${unread.count} unread` : 'Alerts'}
                    className={`relative inline-flex h-10 w-10 items-center justify-center rounded-full bg-white text-slate-600 transition-colors duration-150 hover:text-violet-700 ${ELEVATION.card} ${FOCUS}`}
                >
                    <Icon name="bell" className="h-[18px] w-[18px]" />
                    {unread.count > 0 && (
                        <span className="absolute -right-0.5 -top-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white ring-2 ring-[#f6f7fb]">
                            {unread.count > 99 ? '99+' : unread.count}
                        </span>
                    )}
                </NavLink>

                <AccountMenu
                    name={user?.name}
                    handle={handle}
                    initials={initials}
                    role={(user?.roles || [])[0]}
                    onLogout={logout}
                />
            </div>
        </header>
    );
}

function SearchTrigger({ onClick }) {
    // The real shortcut is registered globally; this only advertises it.
    const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || '');

    return (
        <button
            onClick={onClick}
            className={`group hidden items-center gap-2.5 rounded-2xl bg-white px-3.5 py-2.5 text-left transition-colors duration-150 lg:flex ${ELEVATION.card} ${FOCUS}`}
        >
            <Icon name="search" className="h-[18px] w-[18px] text-slate-400 transition-colors group-hover:text-violet-600" />
            <span className="w-40 text-sm text-slate-500">Search…</span>
            <kbd className="rounded-md border border-slate-200 px-1.5 py-0.5 text-[11px] font-medium text-slate-500">
                {isMac ? '⌘' : 'Ctrl'}K
            </kbd>
        </button>
    );
}

function LastSyncPill({ sync }) {
    const navigate = useNavigate();

    // Three honest states: nothing configured, something failing, or a real
    // timestamp. "Never synced" is not an error and is not coloured like one.
    const errored = sync.errored > 0;
    const label = sync.empty ? 'No devices' : errored ? `${sync.errored} device${sync.errored > 1 ? 's' : ''} failing` : shortAgo(sync.at);
    const dot = sync.empty ? 'bg-slate-300' : errored ? 'bg-rose-500' : sync.at ? 'bg-emerald-500' : 'bg-slate-300';

    return (
        <button
            onClick={() => navigate('/devices')}
            className={`hidden ${PILL} transition-colors duration-150 hover:text-violet-700 sm:flex ${FOCUS}`}
            title={sync.at ? `Most recent device sync: ${sync.at.toLocaleString()}` : 'No successful sync recorded yet'}
        >
            <Icon name="refresh" className="h-[18px] w-[18px] shrink-0 text-slate-400" />
            <span className="leading-tight">
                <span className="block text-sm font-semibold tracking-[-0.01em] text-slate-900">Last sync</span>
                <span className="flex items-center gap-1.5 text-xs text-slate-500">
                    {label}
                    <span className={`h-1.5 w-1.5 rounded-full ${dot}`} aria-hidden="true" />
                </span>
            </span>
        </button>
    );
}

/**
 * Runs a sync across every active device, sequentially so a rack of terminals
 * isn't hit in parallel. Reports how many succeeded and how many punches came
 * in — never a bare "done".
 */
function SyncNowButton({ onDone }) {
    const [state, setState] = useState({ running: false, message: null, tone: 'info' });

    async function run() {
        setState({ running: true, message: null, tone: 'info' });
        try {
            const { data: devices } = await api.get('/devices');
            const active = (devices ?? []).filter((d) => d.is_active);

            if (active.length === 0) {
                setState({ running: false, message: 'No active devices to sync.', tone: 'info' });
                return;
            }

            let imported = 0;
            let failed = 0;
            for (const device of active) {
                try {
                    const res = await api.post(`/devices/${device.id}/sync-attendance`);
                    imported += res.data?.imported ?? 0;
                } catch {
                    failed += 1;
                }
            }

            setState({
                running: false,
                tone: failed ? 'error' : 'success',
                message: failed
                    ? `${imported} punch(es) imported · ${failed} of ${active.length} device(s) unreachable`
                    : `${imported} punch(es) imported from ${active.length} device(s)`,
            });
            onDone?.();
        } catch {
            setState({ running: false, message: 'Could not read the device list.', tone: 'error' });
        }
    }

    // Auto-clear the readout so the chrome doesn't hold a stale message.
    useEffect(() => {
        if (!state.message) return undefined;
        const id = setTimeout(() => setState((s) => ({ ...s, message: null })), 6000);
        return () => clearTimeout(id);
    }, [state.message]);

    const toneClass = state.tone === 'error' ? 'text-rose-700' : state.tone === 'success' ? 'text-emerald-700' : 'text-slate-600';

    return (
        <div className="relative">
            <button
                onClick={run}
                disabled={state.running}
                aria-label="Sync all active devices now"
                className={`inline-flex h-10 items-center gap-2 rounded-full bg-white px-3.5 text-sm font-semibold text-slate-700 transition-colors duration-150 hover:text-violet-700 disabled:opacity-60 ${ELEVATION.card} ${FOCUS}`}
            >
                {state.running ? <Spinner className="h-4 w-4 text-violet-600" /> : <Icon name="bolt" className="h-[18px] w-[18px] text-violet-600" />}
                <span className="hidden xl:inline">{state.running ? 'Syncing…' : 'Sync now'}</span>
            </button>

            {state.message && (
                <div
                    role="status"
                    className={`absolute right-0 top-12 z-30 w-72 rounded-xl bg-white p-3 text-xs ${toneClass} ${ELEVATION.raised}`}
                >
                    {state.message}
                </div>
            )}
        </div>
    );
}

function AccountMenu({ name, handle, initials, role, onLogout }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (!open) return undefined;
        function onDown(e) {
            if (!ref.current?.contains(e.target)) setOpen(false);
        }
        function onKey(e) {
            if (e.key === 'Escape') setOpen(false);
        }
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <div className="relative" ref={ref}>
            <button
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                aria-haspopup="menu"
                className={`flex items-center gap-2.5 rounded-full bg-white py-1.5 pl-1.5 pr-3 transition-colors duration-150 ${ELEVATION.card} ${FOCUS}`}
            >
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-violet-600 text-xs font-bold text-white">
                    {initials}
                </span>
                <span className="hidden leading-tight sm:block">
                    <span className="block max-w-[10rem] truncate text-xs font-semibold text-slate-900">{name}</span>
                    <span className="block text-[11px] text-slate-500">@{handle}</span>
                </span>
                <Icon name="chevron" className={`h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform duration-200 ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div
                    role="menu"
                    className={`absolute right-0 top-12 z-30 w-56 overflow-hidden rounded-2xl bg-white p-1.5 ${ELEVATION.overlay}`}
                    style={{ animation: 'menu-in 160ms cubic-bezier(0.16, 1, 0.3, 1)' }}
                >
                    <div className="px-3 py-2.5">
                        <div className="truncate text-sm font-semibold text-slate-900">{name}</div>
                        <div className="truncate text-xs text-slate-500">@{handle}</div>
                        {role && (
                            <div className="mt-2 inline-flex rounded-full bg-violet-50 px-2 py-0.5 text-[11px] font-medium capitalize text-violet-700">
                                {role.replace(/-/g, ' ')}
                            </div>
                        )}
                    </div>
                    <div className="my-1 h-px bg-slate-100" />
                    <button
                        role="menuitem"
                        onClick={onLogout}
                        className={`flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-slate-700 transition-colors duration-150 hover:bg-rose-50 hover:text-rose-700 ${FOCUS}`}
                    >
                        <Icon name="logout" className="h-[18px] w-[18px]" />
                        Sign out
                    </button>
                </div>
            )}
        </div>
    );
}
