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
                    `filters.org` (a company or department key), so "Clear filters"
                    clears it too. */}
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

                {/* Who is administrator, and where (owner, 2026-11-10). With a terminal chosen
                    it means "on that terminal"; otherwise on any. The badge in the Terminals
                    column marks each terminal where they are administrator. */}
                {devices.length > 0 && (
                    <SelectField
                        label="Terminal role"
                        aria-label="Filter by terminal role"
                        value={filters.role ?? ''}
                        onChange={(e) => set('role', e.target.value)}
                        disabled={filters.device === 'none'}
                        className={stacked ? '' : 'w-44'}
                    >
                        <option value="">Any</option>
                        <option value="admin">Administrator</option>
                        <option value="user">Normal user</option>
                    </SelectField>
                )}

                {/* Punches (owner, 2026-11-16): nobody's punch from any terminal, or at least
                    one. The same count as the Punches column. */}
                <SelectField
                    label="Punches"
                    aria-label="Filter by punches"
                    value={filters.punches ?? ''}
                    onChange={(e) => set('punches', e.target.value)}
                    className={stacked ? '' : 'w-40'}
                >
                    <option value="">Any</option>
                    <option value="none">No punches</option>
                    <option value="some">Has punches</option>
                </SelectField>

                {/* Terminal user ID against Personnel ID (owner, 2026-11-16). On the chosen
                    terminal when one is chosen, otherwise on every terminal they are on. */}
                {devices.length > 0 && (
                    <SelectField
                        label="Terminal ID"
                        aria-label="Filter by terminal user ID against Personnel ID"
                        value={filters.idmatch ?? ''}
                        onChange={(e) => set('idmatch', e.target.value)}
                        disabled={filters.device === 'none'}
                        className={stacked ? '' : 'w-52'}
                    >
                        <option value="">Any</option>
                        <option value="same">Same as Personnel ID</option>
                        <option value="different">Different from Personnel ID</option>
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
