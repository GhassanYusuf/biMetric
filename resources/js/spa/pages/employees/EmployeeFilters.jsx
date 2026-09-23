import { SelectField, TextField } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';

/*
| The employee filter bar, shared by the desktop and mobile presentations.
|
| Shared rather than duplicated because rule 5 requires the two to behave
| identically — a filter that exists on one and not the other is the drift the rule
| is there to stop. Only the LAYOUT differs, and that is the `stacked` prop.
|
| `national_id: '0'` is the one that does real work. After importing a roster almost
| nobody has a national id, and without it nothing can be exported to the HRMS — so
| "who is still missing one" is the working queue, not a refinement.
*/
export default function EmployeeFilters({
    search, onSearch,
    filters, onChange,
    departments = [], devices = [],
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
                    placeholder="Search by name, code, department or email…"
                    aria-label="Search employees"
                    value={search}
                    onChange={(e) => onSearch(e.target.value)}
                    className={stacked ? '' : 'min-w-[16rem]'}
                />

                <SelectField
                    label="National ID"
                    aria-label="Filter by national ID"
                    value={filters.national_id ?? ''}
                    onChange={(e) => set('national_id', e.target.value)}
                    className={stacked ? '' : 'w-44'}
                >
                    <option value="">Any</option>
                    <option value="0">
                        Missing{counts?.missing_national_id != null ? ` (${counts.missing_national_id})` : ''}
                    </option>
                    <option value="1">Has one</option>
                </SelectField>

                {departments.length > 0 && (
                    <SelectField
                        label="Department"
                        aria-label="Filter by department"
                        value={filters.department_id ?? ''}
                        onChange={(e) => set('department_id', e.target.value)}
                        className={stacked ? '' : 'w-48'}
                    >
                        <option value="">Any</option>
                        {departments.map((d) => (
                            <option key={d.id} value={d.id}>{d.name}</option>
                        ))}
                    </SelectField>
                )}

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

                <SelectField
                    label="Status"
                    aria-label="Filter by status"
                    value={filters.is_active ?? ''}
                    onChange={(e) => set('is_active', e.target.value)}
                    className={stacked ? '' : 'w-36'}
                >
                    <option value="">Any</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
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
