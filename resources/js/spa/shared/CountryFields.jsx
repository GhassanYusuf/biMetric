import { useMemo, useState } from 'react';
import Combobox from './Combobox';
import { COUNTRIES, DEFAULT_COUNTRY, countryByCode, findCountry, joinPhone, splitPhone } from './countries';

/*
| The country picker and the phone field (2026-10-12, HR's request): a searchable
| list with each country's flag, and a phone number as calling-code picker + number.
| Data and storage formats are in countries.js.
*/

/** A country's flag: an SVG from public/flags, decorative (the name sits beside it). */
export function Flag({ code, className = '' }) {
    if (!code) return null;
    return (
        <img
            src={`/flags/4x3/${code.toLowerCase()}.svg`}
            alt=""
            width={20}
            height={15}
            loading="lazy"
            className={`h-[15px] w-5 shrink-0 rounded-[2px] object-cover ring-1 ring-slate-900/10 ${className}`}
        />
    );
}

const COUNTRY_OPTIONS = COUNTRIES.map((c) => ({
    value: c.name,
    label: c.name,
    icon: <Flag code={c.code} />,
    search: c.code,
}));

/**
 * The employee's country, stored as its English name. A stored value that is not on
 * the list (free text typed before the picker existed, or imported) is kept and
 * offered as it is, so opening the form never changes what is saved.
 */
export function CountrySelect({ id, value, onChange, error }) {
    const known = findCountry(value);
    const options = useMemo(() => (
        value && !known
            ? [{ value, label: value, detail: 'not on the list' }, ...COUNTRY_OPTIONS]
            : COUNTRY_OPTIONS
    ), [value, known]);

    return (
        <Combobox
            id={id}
            options={options}
            // A stored ISO code or odd casing still selects the right row.
            value={known ? known.name : (value || null)}
            onChange={(v) => onChange(v ?? '')}
            placeholder="Search countries…"
            emptyText="No country matches."
            error={error}
            allowClear
        />
    );
}

const DIAL_OPTIONS = COUNTRIES.map((c) => ({
    value: c.code,
    label: `+${c.dial}`,
    icon: <Flag code={c.code} />,
    detail: c.name,
    // Typing "bahrain", "bh", "973" or "+973" all find it.
    search: `${c.name} ${c.code} ${c.dial}`,
}));

/**
 * A phone number as calling code + number, stored as "+973 33445566". `fallback` is
 * the code an empty field opens on — the employee's own country when one is chosen.
 */
export function PhoneField({ id, value, onChange, error, fallback = null }) {
    const parsed = splitPhone(value);
    const [chosen, setChosen] = useState(parsed.country?.code ?? null);
    const country = (chosen && countryByCode(chosen))
        ?? parsed.country
        ?? findCountry(fallback)
        ?? countryByCode(DEFAULT_COUNTRY);

    function setNumber(text) {
        // A pasted "+973 3344 5566" picks its own code.
        if (text.trim().startsWith('+')) {
            const split = splitPhone(text);
            if (split.country) {
                setChosen(split.country.code);
                onChange(joinPhone(split.country, split.number));
                return;
            }
        }
        onChange(joinPhone(country, text));
    }

    function setCountry(code) {
        if (!code) return;
        setChosen(code);
        onChange(joinPhone(countryByCode(code), parsed.number));
    }

    /*
     * ONE box: the calling code on the left, the number filling the rest. Two boxes
     * side by side left the number a sliver in the form's narrow columns (2026-10-12).
     */
    return (
        <div>
            <div className={`flex items-stretch rounded-xl border bg-slate-50/70 transition-colors duration-150 focus-within:bg-white focus-within:ring-2 ${
                error
                    ? 'border-rose-300 focus-within:border-rose-400 focus-within:ring-rose-100'
                    : 'border-slate-200 focus-within:border-violet-400 focus-within:ring-violet-100'
            }`}>
                <Combobox
                    options={DIAL_OPTIONS}
                    value={country.code}
                    onChange={setCountry}
                    placeholder="+…"
                    emptyText="No country matches."
                    className="w-[5.25rem] shrink-0 border-r border-slate-200"
                    listClassName="min-w-[18rem]"
                    aria-describedby={id ? `${id}-hint` : undefined}
                    bare
                />
                <input
                    id={id}
                    type="tel"
                    inputMode="tel"
                    autoComplete="tel-national"
                    value={parsed.number}
                    onChange={(e) => setNumber(e.target.value)}
                    aria-invalid={error ? 'true' : undefined}
                    placeholder="Number"
                    className="block w-full min-w-0 rounded-r-xl border-0 bg-transparent px-2.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-500 focus:outline-none focus:ring-0"
                />
            </div>
            <span id={id ? `${id}-hint` : undefined} className="sr-only">Country calling code</span>
            {error && <span role="alert" className="mt-1 block text-xs font-medium text-rose-600">{error}</span>}
        </div>
    );
}

/** Caught before saving. The server re-checks the characters, not the digit count. */
export function phoneError(value) {
    const { number } = splitPhone(value);
    if (!number) return null;
    if (!/^[\d\s()-]+$/.test(number)) return 'Use digits only — the country code is picked on the left.';
    const digits = number.replace(/\D/g, '').length;
    if (digits < 4 || digits > 15) return 'Enter a phone number of 4 to 15 digits.';
    return null;
}
