import { useEffect, useRef } from 'react';
import { Button } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';

/*
| Tick several employees, then act on them together (owner, 2026-11-15: "multiple select by tick
| to delete in employees"). Shared by EmployeesDesktop and EmployeesMobile so both behave the same
| (rule 5). Selection is held by Employees.jsx; these only render it.
|
| The row and the card open the actions menu on click, so a tick box stops its click from
| reaching them — ticking must never also open a menu.
*/

/** One person's tick box. `indeterminate` is for the "whole page" box when only some are ticked. */
export function SelectBox({ checked, indeterminate = false, onChange, label, className = '' }) {
    const ref = useRef(null);
    useEffect(() => {
        if (ref.current) ref.current.indeterminate = indeterminate && !checked;
    }, [indeterminate, checked]);

    return (
        <input
            ref={ref}
            type="checkbox"
            checked={checked}
            onChange={(e) => onChange(e.target.checked)}
            onClick={(e) => e.stopPropagation()}
            onKeyDown={(e) => e.stopPropagation()}
            aria-label={label}
            className={`h-4 w-4 cursor-pointer rounded border-slate-300 text-violet-600 ${FOCUS} ${className}`}
        />
    );
}

/**
 * What is ticked, and what can be done with it. Shown only while something is ticked: it is the
 * page's current state (rule 2's InlineAlert side), not the result of an action.
 */
export function SelectionBar({ count, pageCount, onSelectPage, onClear, onDelete, className = '' }) {
    if (count === 0) return null;

    return (
        <div
            className={`flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl bg-violet-50 px-4 py-3 text-sm ring-1 ring-violet-200 ${className}`}
            role="region"
            aria-label="Selected employees"
        >
            <span className="font-semibold text-violet-900">{count.toLocaleString()} selected</span>
            {count < pageCount && (
                <button type="button" onClick={onSelectPage} className={`font-medium text-violet-700 underline-offset-2 hover:underline ${FOCUS} rounded`}>
                    Select all {pageCount.toLocaleString()} on this page
                </button>
            )}
            <button type="button" onClick={onClear} className={`font-medium text-slate-600 underline-offset-2 hover:underline ${FOCUS} rounded`}>
                Clear
            </button>
            <span className="flex-1" />
            {onDelete && (
                <Button variant="danger" onClick={onDelete} className="min-h-[38px] px-4 text-sm">
                    Delete {count.toLocaleString()}…
                </Button>
            )}
        </div>
    );
}
