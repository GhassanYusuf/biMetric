import { Badge } from '../../shared/ui';

/*
| How an employee's company, department and employment read on screen, shared by the
| desktop and mobile lists and the detail dialog so they cannot drift (rule 5).
*/

/**
 * Company and department (two columns since the companies table, 2026-11-03):
 * `company` is the company or null, `department_name` the department or null when
 * they sit on the company alone. Falls back to the old free-text column for someone
 * in no company.
 */
export function orgOf(e) {
    if (e.company) {
        return { company: e.company.name, department: e.department_id != null ? (e.department_name ?? null) : null };
    }
    return { company: e.department || null, department: null };
}

export const EMPLOYMENT_LABELS = { full_time: 'Full-time', part_time: 'Part-time' };

/** "12 Oct 2026", for a resignation date stored as YYYY-MM-DD. */
export function formatDay(day) {
    if (!day) return '';
    const [y, m, d] = day.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

/** Today at the office as YYYY-MM-DD, for comparing with a resignation date. */
function todayLocal() {
    return new Date().toLocaleDateString('en-CA');
}

/** Resigned AS OF TODAY (2026-11-17): a future resignation date is not yet a resignation. */
export function isResignedNow(employee) {
    if (typeof employee.resigned === 'boolean') return employee.resigned;
    return !!employee.resigned_at && employee.resigned_at <= todayLocal();
}

/**
 * "Resigned", "Resigns 29 Nov", "Access ended" and "Part-time" beside a name. Full-time and still
 * employed is the norm and shows nothing.
 */
export function EmploymentBadges({ employee, className = '' }) {
    const resigned = isResignedNow(employee);
    const resignsLater = !resigned && !!employee.resigned_at;
    const accessEnded = !resigned && employee.access_ended === true;
    const partTime = employee.employment_type === 'part_time';
    if (!resigned && !resignsLater && !accessEnded && !partTime) return null;
    return (
        <span className={`inline-flex flex-wrap gap-1 align-middle ${className}`}>
            {resigned && <Badge tone="rose" title={`Resigned on ${formatDay(employee.resigned_at)}`}>Resigned</Badge>}
            {resignsLater && (
                <Badge tone="amber" title={`Keeps access until ${formatDay(employee.resigned_at)}; taken off the terminals that day.`}>
                    Resigns {formatDay(employee.resigned_at)}
                </Badge>
            )}
            {accessEnded && <Badge tone="amber" title="Their valid period has ended; they are kept off the terminals.">Access ended</Badge>}
            {partTime && <Badge tone="slate">Part-time</Badge>}
        </span>
    );
}
