import { Badge } from '../../shared/ui';

/*
| How an employee's company, department and employment read on screen, shared by the
| desktop and mobile lists and the detail dialog so they cannot drift (rule 5).
*/

/**
 * Company and department from the tree (2026-10-12). `department_id` is the
 * department when one is set, or the company itself — then there is no department
 * yet. Falls back to the old free-text column for a row loaded without the tree.
 */
export function orgOf(e) {
    if (e.company) {
        return {
            company: e.company.name,
            // Number(): SQL Server returns department_id as a string ("4").
            department: e.department_id && Number(e.department_id) !== Number(e.company.id) ? (e.department_name ?? null) : null,
        };
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

/** "Resigned" and "Part-time" beside a name. Full-time and still employed is the norm and shows nothing. */
export function EmploymentBadges({ employee, className = '' }) {
    const resigned = !!employee.resigned_at;
    const partTime = employee.employment_type === 'part_time';
    if (!resigned && !partTime) return null;
    return (
        <span className={`inline-flex flex-wrap gap-1 align-middle ${className}`}>
            {resigned && <Badge tone="rose" title={`Resigned on ${formatDay(employee.resigned_at)}`}>Resigned</Badge>}
            {partTime && <Badge tone="slate">Part-time</Badge>}
        </span>
    );
}
