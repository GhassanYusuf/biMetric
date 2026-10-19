import { SelectField, TextField } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';

/*
| The employee filter bar, shared by the desktop and mobile presentations.
|
| Shared rather than duplicated because rule 5 requires the two to behave
| identically — a filter that exists on one and not the other is the drift the rule
| is there to stop. Only the LAYOUT differs, and that is the `stacked` prop.
*/
export default function EmployeeFilters({
    search, onSearch,
    filters, onChange,
    devices = [],
    counts = null,
    stacked = false,
}) {
    // A filter that is on should be visible as on, and clearable in one action.
    const active = Object.entries(filters).filter(([, v]) => v !== '' && v != null);

    function set(key, value) {
        onChange({ ...filters, [key]: value });
    }

    return (
        <div className="space-y-3">
            <div className={stacked ? 'space-y-3' : 'flex flex-wrap items-end gap-3'}>
                <TextField
                    placeholder="Search by name, code, department, job title or email…"
                    aria-label="Search employees"
                    value={search}
                    onChange={(e) => onSearch(e.target.value)}
                    className={stacked ? '' : 'min-w-[16rem]'}
                />

                {/* Department is not here: the Company › Department tree beside
                    the roster picks it (DepartmentTree.jsx, 2026-10-11). It is still
                    `filters.department_id`, so "Clear filters" clears it too. */}
                {devices.length > 0 && (
                    <SelectField
                        label="Terminal"
                        aria-label="Filter by terminal"
                        value={filters.device ?? ''}
                        onChange={(e) => set('device', e.target.value)}
                        className={stacked ? '' : 'w-48'}
                    >
                        <option value="">Any</option>
                        {/* Distinct from a device id: "nowhere" is a real state — they
                            cannot clock in at all — and is not expressible as a device. */}
                        <option value="none">On no terminal</option>
                        {devices.map((d) => (
                            <option key={d.id} value={d.id}>{d.name}</option>
                        ))}
                    </SelectField>
                )}

                {/* Resigned people are hidden until asked for (2026-10-12): they are off
                    the terminals and no longer part of the roster anyone works from. */}
                <SelectField
                    label="Status"
                    aria-label="Filter by status"
                    value={filters.status ?? ''}
                    onChange={(e) => set('status', e.target.value)}
                    className={stacked ? '' : 'w-44'}
                >
                    <option value="">Current staff</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="resigned">Resigned</option>
                    <option value="all">Everyone</option>
                </SelectField>
            </div>

            {active.length > 0 && (
                <button
                    type="button"
                    onClick={() => onChange({})}
                    className={`rounded-lg px-1 py-0.5 text-xs font-medium text-violet-700 underline underline-offset-2 ${FOCUS}`}
                >
                    Clear {active.length} filter{active.length === 1 ? '' : 's'}
                </button>
            )}
        </div>
    );
}
