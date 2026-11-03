import { useState } from 'react';
import { FOCUS } from '../../shared/theme';

/*
| "Highlight people with no punches" (owner, 2026-11-03): with it on, a person whose
| attendance_logs_count is 0 gets an amber row and a "No punches" label (the label, so the
| colour is not the only signal, rule 7). One component for desktop and mobile (rule 5).
|
| The choice is this viewer's own convenience, so it lives in this browser only; storage
| that is blocked or missing just means it starts off.
*/
const KEY = 'employees.highlightNoPunches';

export function useNoPunchHighlight() {
    const [on, setOn] = useState(() => {
        try {
            return localStorage.getItem(KEY) === '1';
        } catch {
            return false;
        }
    });
    const toggle = (next) => {
        setOn(next);
        try {
            localStorage.setItem(KEY, next ? '1' : '0');
        } catch {
            // Not remembered; it still works for this visit.
        }
    };

    return [on, toggle];
}

export const hasNoPunches = (employee) => (employee.attendance_logs_count ?? 0) === 0;

export function NoPunchHighlightToggle({ on, onToggle }) {
    return (
        <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg px-1 py-1 text-sm text-slate-700">
            <input
                type="checkbox"
                checked={on}
                onChange={(event) => onToggle(event.target.checked)}
                className={`h-4 w-4 rounded border-slate-300 text-amber-600 ${FOCUS}`}
            />
            <span>Highlight people with no punches</span>
        </label>
    );
}

export function NoPunchesBadge() {
    return (
        <span className="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
            No punches
        </span>
    );
}
