import { useEffect, useRef, useState } from 'react';
import { api } from './api';
import { FOCUS } from './theme';

/*
| Pick one or more employees by typing (owner, 2026-10-19: export the Monthly Statement
| "based on selection … one person selection or multiple"). Searches the same
| `GET /employees?search=` as the command palette, so a name, code or phone finds them.
|
| value     [{ id, name, employee_code }] — the people chosen, shown as removable chips.
|           Kept as objects, not ids, so a chip still has a name after the search moves on.
| onChange  (next) => void
|
| Resigned and inactive people are found too: somebody who left mid-month still needs
| that month's statement.
*/
export default function EmployeePicker({ value, onChange, error, label = 'People' }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [loading, setLoading] = useState(false);
    const [searched, setSearched] = useState(false);
    const seq = useRef(0);

    useEffect(() => {
        const term = query.trim();
        if (!term) {
            setResults([]);
            setSearched(false);
            return undefined;
        }
        const mine = ++seq.current;
        const t = setTimeout(async () => {
            setLoading(true);
            try {
                const res = await api.get(`/employees?per_page=20&search=${encodeURIComponent(term)}`);
                if (mine === seq.current) setResults(res.data ?? []);
            } catch {
                if (mine === seq.current) setResults([]);
            } finally {
                if (mine === seq.current) {
                    setLoading(false);
                    setSearched(true);
                }
            }
        }, 250);
        return () => clearTimeout(t);
    }, [query]);

    const chosen = new Set(value.map((p) => p.id));

    function toggle(person) {
        onChange(chosen.has(person.id)
            ? value.filter((p) => p.id !== person.id)
            : [...value, { id: person.id, name: person.name, employee_code: person.employee_code }]);
    }

    return (
        <div>
            <label htmlFor="employee-picker" className="block text-sm font-medium text-slate-700">{label}</label>

            {value.length > 0 && (
                <ul className="mt-2 flex flex-wrap gap-1.5" aria-label="Chosen people">
                    {value.map((p) => (
                        <li key={p.id} className="inline-flex items-center gap-1 rounded-full bg-violet-50 py-0.5 pl-2.5 pr-1 text-xs font-medium text-violet-800">
                            {p.name}
                            <button
                                type="button"
                                onClick={() => toggle(p)}
                                aria-label={`Remove ${p.name}`}
                                className={`grid h-5 w-5 place-items-center rounded-full text-violet-700 hover:bg-violet-100 ${FOCUS}`}
                            >
                                <svg viewBox="0 0 20 20" fill="currentColor" className="h-3.5 w-3.5" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" /></svg>
                            </button>
                        </li>
                    ))}
                    <li>
                        <button type="button" onClick={() => onChange([])} className={`rounded-full px-2 py-0.5 text-xs font-medium text-slate-600 hover:bg-slate-100 ${FOCUS}`}>
                            Clear all
                        </button>
                    </li>
                </ul>
            )}

            <input
                id="employee-picker"
                type="search"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Search by name or code…"
                autoComplete="off"
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? 'employee-picker-error' : undefined}
                className={`mt-2 block w-full rounded-xl border-0 px-3.5 py-2.5 text-sm text-slate-900 ring-1 placeholder:text-slate-500 ${error ? 'ring-rose-300' : 'ring-slate-200'} ${FOCUS}`}
            />
            {error && <p id="employee-picker-error" className="mt-1 text-xs text-rose-700">{error}</p>}

            {query.trim() !== '' && (
                <ul className="mt-2 max-h-56 overflow-y-auto rounded-xl ring-1 ring-slate-200" aria-label="Search results" aria-busy={loading}>
                    {results.map((person) => {
                        const on = chosen.has(person.id);
                        const where = person.department_name ?? person.company?.name;
                        return (
                            <li key={person.id}>
                                <button
                                    type="button"
                                    role="checkbox"
                                    aria-checked={on}
                                    onClick={() => toggle(person)}
                                    className={`flex min-h-[44px] w-full items-center gap-3 px-3.5 py-2 text-left text-sm hover:bg-slate-50 ${on ? 'bg-violet-50/60' : ''} ${FOCUS}`}
                                >
                                    <span className={`grid h-4 w-4 shrink-0 place-items-center rounded border ${on ? 'border-violet-600 bg-violet-600 text-white' : 'border-slate-400'}`} aria-hidden="true">
                                        {on && <svg viewBox="0 0 20 20" fill="currentColor" className="h-3 w-3"><path fillRule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.58l7.3-7.3a1 1 0 0 1 1.4 0Z" clipRule="evenodd" /></svg>}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium text-slate-900">{person.name}</span>
                                        <span className="block truncate text-xs text-slate-500">
                                            #{person.employee_code}{where ? ` · ${where}` : ''}{person.is_active ? '' : ' · inactive'}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        );
                    })}
                    {searched && !loading && results.length === 0 && (
                        <li className="px-3.5 py-3 text-sm text-slate-500">Nobody matches “{query.trim()}”.</li>
                    )}
                    {loading && results.length === 0 && (
                        <li className="px-3.5 py-3 text-sm text-slate-500">Searching…</li>
                    )}
                </ul>
            )}
        </div>
    );
}
