import { useMemo, useState } from 'react';
import {
    Combobox as HCombobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions,
} from '@headlessui/react';

/*
| A dropdown you can type into to filter (2026-10-12, HR's request for Company,
| Department and Country). Built on Headless UI's Combobox, already a dependency of
| the Inertia app, which supplies the listbox semantics, arrow keys, Enter and
| Escape. Escape closes the list WITHOUT closing the dialog around it: Headless UI
| stops the event before it reaches the dialog's useEscapeKey listener.
|
| The list is anchored and portalled to <body>, so a dialog's scrolling body cannot
| clip it; z-[60] sits above the z-50 dialogs.
|
| options   [{ value, label, icon?, detail?, search? }] — `search` is extra text to
|           match on (a calling code, an ISO code) that is not shown as the label.
| onCreate  optional (query) => Promise<option|null>: offers "Add “query”" when
|           nothing matches exactly, and selects what it returns.
*/
export default function Combobox({
    id, options, value, onChange,
    placeholder = 'Search…', error, disabled = false,
    onCreate = null, createLabel = (q) => `Add “${q}”`,
    emptyText = 'Nothing matches.',
    // Emptying the box and leaving it clears the value (onChange(null)).
    allowClear = false,
    // No chevron, tighter padding and no border of its own — for a picker that sits
    // inside another control (the phone field's calling code).
    bare = false,
    className = '', inputClassName = '', listClassName = '',
    'aria-describedby': describedBy,
}) {
    const [query, setQuery] = useState('');
    const [creating, setCreating] = useState(false);
    const selected = options.find((o) => o.value === value) ?? null;

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return options;
        return options.filter((o) => `${o.label} ${o.search ?? ''}`.toLowerCase().includes(q));
    }, [options, query]);

    const trimmed = query.trim();
    const offerCreate = onCreate && trimmed !== ''
        && !options.some((o) => o.label.toLowerCase() === trimmed.toLowerCase());

    async function pick(option) {
        // Headless UI hands back null when the box is emptied.
        if (option == null) {
            if (allowClear) onChange(null);
            return;
        }
        if (option.__create) {
            setCreating(true);
            try {
                const made = await onCreate(option.label);
                if (made) onChange(made.value);
            } finally {
                setCreating(false);
            }
            return;
        }
        onChange(option.value);
    }

    return (
        <HCombobox
            value={selected}
            onChange={pick}
            onClose={() => setQuery('')}
            by={(a, b) => a?.value === b?.value}
            disabled={disabled || creating}
            immediate
        >
            <div className={`relative ${className}`}>
                {selected?.icon && query === '' && (
                    <span className={`pointer-events-none absolute inset-y-0 flex items-center ${bare ? 'left-2' : 'left-3'}`}>{selected.icon}</span>
                )}
                <ComboboxInput
                    id={id}
                    aria-invalid={error ? 'true' : undefined}
                    aria-describedby={describedBy}
                    autoComplete="off"
                    placeholder={placeholder}
                    displayValue={(o) => o?.label ?? ''}
                    onChange={(e) => setQuery(e.target.value)}
                    onFocus={(e) => e.target.select()}
                    className={bare
                        ? `block w-full rounded-l-xl border-0 bg-transparent py-2.5 pr-1 text-sm text-slate-900 placeholder:text-slate-500 focus:outline-none focus:ring-0 ${selected?.icon && query === '' ? 'pl-8' : 'pl-2.5'} ${inputClassName}`
                        : `block w-full rounded-xl border bg-slate-50/70 py-2.5 pr-9 text-sm text-slate-900 transition-colors duration-150 placeholder:text-slate-500 focus:bg-white focus:outline-none focus:ring-2 disabled:opacity-50 ${
                            selected?.icon && query === '' ? 'pl-10' : 'pl-3.5'
                        } ${
                            error
                                ? 'border-rose-300 focus:border-rose-400 focus:ring-rose-100'
                                : 'border-slate-200 focus:border-violet-400 focus:ring-violet-100'
                        } ${inputClassName}`}
                />
                {!bare && <ComboboxButton
                    aria-label="Show the list"
                    className="absolute inset-y-0 right-0 flex items-center rounded-r-xl px-2.5 text-slate-400 focus:outline-none"
                >
                    <svg viewBox="0 0 20 20" fill="currentColor" className="h-4 w-4" aria-hidden="true">
                        <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clipRule="evenodd" />
                    </svg>
                </ComboboxButton>}
            </div>
            <ComboboxOptions
                anchor="bottom start"
                className={`z-[60] max-h-64 w-[var(--input-width)] min-w-[14rem] overflow-y-auto rounded-xl bg-white p-1 text-sm shadow-lg ring-1 ring-slate-200 [--anchor-gap:4px] ${listClassName}`}
            >
                {filtered.map((o) => (
                    <ComboboxOption
                        key={o.value}
                        value={o}
                        className="group flex cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-2 text-slate-800 data-[focus]:bg-violet-50 data-[selected]:font-semibold"
                    >
                        {o.icon}
                        <span className="min-w-0 flex-1 truncate">{o.label}</span>
                        {o.detail && <span className="shrink-0 text-xs text-slate-500">{o.detail}</span>}
                    </ComboboxOption>
                ))}
                {offerCreate && (
                    <ComboboxOption
                        value={{ __create: true, value: `__create:${trimmed}`, label: trimmed }}
                        className="flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 font-medium text-violet-700 data-[focus]:bg-violet-50"
                    >
                        <span aria-hidden="true">+</span> {createLabel(trimmed)}
                    </ComboboxOption>
                )}
                {filtered.length === 0 && !offerCreate && (
                    <div className="px-2.5 py-2 text-slate-500">{emptyText}</div>
                )}
            </ComboboxOptions>
            {error && <span role="alert" className="mt-1 block text-xs font-medium text-rose-600">{error}</span>}
        </HCombobox>
    );
}
