import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from './api';
import { useAuth } from './auth';
import { ELEVATION } from './theme';
import { Spinner } from './ui';
import { Icon, NAV_ITEMS } from '../layouts/nav';

/*
| ⌘K / Ctrl-K palette: jump to a page, an employee, or a device without
| navigating there first. Searching hits the same permission-gated endpoints the
| pages use, so results never exceed what the user may see.
|
| Rendered from the app shell so it escapes every overflow container.
*/
export default function CommandPalette({ open, onClose }) {
    const navigate = useNavigate();
    const { can } = useAuth();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState({ employees: [], devices: [] });
    const [searching, setSearching] = useState(false);
    const [active, setActive] = useState(0);
    const inputRef = useRef(null);
    const debounce = useRef(null);
    const listRef = useRef(null);

    const pages = useMemo(
        () => NAV_ITEMS.filter((i) => !i.permission || can(i.permission)),
        [can],
    );

    // Flat, ordered list of everything selectable — keyboard nav walks this.
    const items = useMemo(() => {
        const term = query.trim().toLowerCase();
        const matchedPages = term
            ? pages.filter((p) => p.label.toLowerCase().includes(term))
            : pages;

        return [
            ...matchedPages.map((p) => ({ kind: 'page', id: `page-${p.to}`, label: p.label, icon: p.icon, to: p.to })),
            ...results.employees.map((e) => ({
                kind: 'employee', id: `emp-${e.id}`, label: e.name,
                meta: [e.employee_code, e.department].filter(Boolean).join(' · '),
                icon: 'users', to: '/employees',
            })),
            ...results.devices.map((d) => ({
                kind: 'device', id: `dev-${d.id}`, label: d.name,
                meta: `${d.ip_address}:${d.port}`, icon: 'device', to: '/devices',
            })),
        ];
    }, [query, pages, results]);

    // Reset per opening so the palette never reopens mid-search.
    useEffect(() => {
        if (!open) return;
        setQuery('');
        setResults({ employees: [], devices: [] });
        setActive(0);
        const id = requestAnimationFrame(() => inputRef.current?.focus());
        return () => cancelAnimationFrame(id);
    }, [open]);

    // Debounced remote search. Failures are swallowed per-source: a 403 on
    // devices must not wipe employee results.
    useEffect(() => {
        if (!open) return undefined;
        const term = query.trim();

        clearTimeout(debounce.current);
        if (term.length < 2) {
            setResults({ employees: [], devices: [] });
            setSearching(false);
            return undefined;
        }

        setSearching(true);
        debounce.current = setTimeout(async () => {
            const [employees, devices] = await Promise.all([
                can('employees.view')
                    ? api.get(`/employees?per_page=5&search=${encodeURIComponent(term)}`).then((r) => r.data).catch(() => [])
                    : Promise.resolve([]),
                can('devices.view')
                    ? api.get('/devices').then((r) =>
                        (r.data ?? []).filter((d) =>
                            `${d.name} ${d.ip_address} ${d.location ?? ''}`.toLowerCase().includes(term.toLowerCase()),
                        ).slice(0, 5),
                    ).catch(() => [])
                    : Promise.resolve([]),
            ]);
            setResults({ employees, devices });
            setSearching(false);
            setActive(0);
        }, 250);

        return () => clearTimeout(debounce.current);
    }, [query, open, can]);

    const choose = useCallback((item) => {
        if (!item) return;
        navigate(item.to);
        onClose();
    }, [navigate, onClose]);

    function onKeyDown(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            onClose();
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((i) => Math.min(i + 1, items.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => Math.max(i - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            choose(items[active]);
        }
    }

    // Keep the highlighted row in view during keyboard traversal.
    useEffect(() => {
        listRef.current?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    if (!open) return null;

    const groups = [
        { key: 'page', title: 'Go to' },
        { key: 'employee', title: 'Employees' },
        { key: 'device', title: 'Devices' },
    ];

    return (
        <div
            className="fixed inset-0 z-[60] flex items-start justify-center bg-slate-900/40 p-4 pt-[12vh] backdrop-blur-sm"
            onClick={onClose}
            role="presentation"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label="Command palette"
                onClick={(e) => e.stopPropagation()}
                onKeyDown={onKeyDown}
                className={`w-full max-w-xl overflow-hidden rounded-2xl bg-white ${ELEVATION.overlay}`}
                style={{ animation: 'palette-in 200ms cubic-bezier(0.16, 1, 0.3, 1)' }}
            >
                <div className="flex items-center gap-3 border-b border-slate-100 px-4">
                    <Icon name="search" className="h-[18px] w-[18px] shrink-0 text-slate-400" />
                    <input
                        ref={inputRef}
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search pages, employees, devices…"
                        aria-label="Search"
                        className="min-w-0 flex-1 border-0 bg-transparent py-4 text-[15px] text-slate-900 placeholder:text-slate-500 focus:outline-none focus:ring-0"
                    />
                    {searching && <Spinner className="h-4 w-4 shrink-0 text-violet-600" />}
                    <kbd className="hidden shrink-0 rounded-md border border-slate-200 px-1.5 py-0.5 text-[11px] font-medium text-slate-500 sm:block">esc</kbd>
                </div>

                <div ref={listRef} className="max-h-[52vh] overflow-y-auto p-2" role="listbox">
                    {items.length === 0 ? (
                        <p className="px-3 py-10 text-center text-sm text-slate-500">
                            {query.trim().length < 2 ? 'Type at least two characters to search.' : `Nothing matches "${query.trim()}".`}
                        </p>
                    ) : (
                        groups.map((group) => {
                            const rows = items.filter((i) => i.kind === group.key);
                            if (rows.length === 0) return null;

                            return (
                                <div key={group.key} className="mb-1 last:mb-0">
                                    <div className="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                                        {group.title}
                                    </div>
                                    {rows.map((item) => {
                                        const index = items.indexOf(item);
                                        const isActive = index === active;
                                        return (
                                            <button
                                                key={item.id}
                                                role="option"
                                                aria-selected={isActive}
                                                data-active={isActive}
                                                onMouseEnter={() => setActive(index)}
                                                onClick={() => choose(item)}
                                                className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm transition-colors duration-100 ${
                                                    isActive ? 'bg-violet-50 text-violet-900' : 'text-slate-700'
                                                }`}
                                            >
                                                <Icon name={item.icon} className={`h-[18px] w-[18px] shrink-0 ${isActive ? 'text-violet-600' : 'text-slate-400'}`} />
                                                <span className="min-w-0 flex-1 truncate font-medium">{item.label}</span>
                                                {item.meta && <span className="shrink-0 truncate text-xs text-slate-500">{item.meta}</span>}
                                                {isActive && <span className="shrink-0 text-xs text-violet-500" aria-hidden="true">↵</span>}
                                            </button>
                                        );
                                    })}
                                </div>
                            );
                        })
                    )}
                </div>
            </div>
        </div>
    );
}

/** Registers the ⌘K / Ctrl-K shortcut and returns the palette's open state. */
export function useCommandPalette() {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        function onKey(e) {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setOpen((v) => !v);
            }
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    return { open, setOpen };
}
