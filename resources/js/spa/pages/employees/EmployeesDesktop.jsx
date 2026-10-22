import { ActionMenu, Button, EmptyState, Pager, PageHeader, TableShell, Thead } from '../../shared/ui';
import AutoPushToggle from './AutoPushToggle';
import { FOCUS } from '../../shared/theme';
import { CardCell, DeviceUserIdCell, FingerprintCell, TerminalsCell, syncedAgo } from './enrollmentCells';
import EmployeeFilters from './EmployeeFilters';
import DepartmentTree from './DepartmentTree';
import { Link } from 'react-router-dom';
import { employeeRowActions } from './rowActions';
import { EmploymentBadges, orgOf } from './employeeLabels';
import { useRef, useState } from 'react';

// Desktop employees: toolbar + wide data table.
export default function EmployeesDesktop({
    rows, search, onSearch, filters, onFilters, departments, unassignedCount, departmentsLoaded, devices,
    canCreate, canDelete, canEnroll, canUpdate, canReadDevice,
    readingDevice, total, loading, pagination, onPage,
    autoPush, savingAutoPush, canManageSettings, onToggleAutoPush,
    onAdd, onDelete, onEdit, onPush, onMerge, onScan, onEnroll, onReadFingerprints, onPushFingerprints, onView, onReadFromDevice, onReport,
}) {
    const searching = search.trim().length > 0;
    const filtering = Object.values(filters ?? {}).some((v) => v !== '' && v != null);
    // Not a count of the whole roster — only of what is on screen. Said that way in the
    // banner below, because "12 missing" would read as an estate-wide figure.
    const lastRead = syncedAgo(rows);

    /*
     | The row is the menu trigger (2026-10-05): tapping anywhere on an employee
     | drops their actions down, so there is no Actions button to aim at. One menu
     | at a time — `menuFor` is the employee id, and `menuAnchor` is that row's DOM
     | node for the panel to measure itself against. The click handler takes the
     | node from the event rather than a ref map: the element is already in hand,
     | and a map keyed by id has to be kept in step with the rows.
     */
    const [menuFor, setMenuFor] = useState(null);
    const [menuFocus, setMenuFocus] = useState('none');
    const [menuPoint, setMenuPoint] = useState(null);
    const menuAnchor = useRef(null);
    const menuRow = menuFor == null ? null : rows.find((r) => r.id === menuFor) ?? null;

    function toggleMenu(event, employee) {
        if (menuFor === employee.id) {
            setMenuFor(null);
            return;
        }
        // Where they clicked, in viewport coordinates, so the panel drops from the
        // pointer rather than from the row's far edge (2026-10-05).
        menuAnchor.current = event.currentTarget;
        setMenuPoint({ x: event.clientX, y: event.clientY });
        // Enter/Space leave the keyboard where it is, on the row (rule 7).
        setMenuFocus('none');
        setMenuFor(employee.id);
    }

    // Keyboard has no pointer, so the row's own box is the anchor instead.
    function openMenuFromKey(event, employee, focus) {
        if (menuFor === employee.id) return;
        menuAnchor.current = event.currentTarget;
        setMenuPoint(null);
        setMenuFocus(focus);
        setMenuFor(employee.id);
    }

    return (
        <div className="space-y-5">
            <PageHeader
                title="Employees"
                subtitle={
                    (searching
                        ? `${rows.length} matching`
                        : `${(total ?? rows.length).toLocaleString()} on the roster`)
                    + ' · tap a row for its actions'
                }
            >
                {/*
                  * No permission gate (2026-10-06, owner's request). Seeing that
                  * the roster holds one person twice is not a privileged fact,
                  * and the scan itself reads — it cannot write until the operator
                  * picks a merge and the route's update+delete checks agree.
                  */}
                <Button variant="secondary" onClick={() => onScan()}>Scan for duplicates</Button>
                {canReadDevice && (
                    <Button variant="secondary" loading={readingDevice} onClick={onReadFromDevice}>
                        {readingDevice ? 'Reading terminal…' : 'Read from device'}
                    </Button>
                )}
                {canCreate && <Button onClick={onAdd}>+ Add employee</Button>}
            </PageHeader>

            {canManageSettings && (
                <div className="flex justify-end">
                    <AutoPushToggle on={autoPush} busy={savingAutoPush} onToggle={onToggleAutoPush} />
                </div>
            )}

            {/*
              * Tree on the left, roster on the right (2026-10-11). The tree picks the
              * group; the bar above the table narrows within it. Sticky so a long
              * page keeps the grouping in reach; min-w-0 on the right column lets
              * the wide table scroll inside it instead of pushing the page sideways.
              */}
            <div className="grid grid-cols-[15rem_minmax(0,1fr)] items-start gap-5 xl:grid-cols-[17rem_minmax(0,1fr)]">
            <div className="sticky top-4 max-h-[calc(100vh-2rem)] overflow-y-auto">
                <DepartmentTree
                    departments={departments}
                    unassignedCount={unassignedCount}
                    loaded={departmentsLoaded}
                    value={filters?.department_id ?? ''}
                    onChange={(id) => onFilters({ ...(filters ?? {}), department_id: id })}
                />
            </div>

            <div className="min-w-0 space-y-5">
            {/* The endpoint paginates, so this table is one page of the roster. The
                pager below says so, with the real total — a banner alone was easy to
                read as "that is everybody" once it had been dismissed. */}
            <EmployeeFilters
                search={search}
                onSearch={onSearch}
                filters={filters ?? {}}
                onChange={onFilters}
                devices={devices}
            />

            <TableShell>
                <Thead>
                    <tr>
                        {/* Position in the list as shown, so it renumbers with the
                            filters rather than pretending to be a stable id. No font
                            of its own — Thead styles every header, and a column that
                            opts out of that stops looking like part of the table. */}
                        <th className="px-5 py-3 text-center" style={{ width: '1%' }}>No.</th>
                        <th className="px-5 py-3 text-left">Name</th>
                        <th className="px-5 py-3 text-left">Code</th>
                        <th className="px-5 py-3 text-left">Device user ID</th>
                        <th className="px-5 py-3 text-left">Company</th>
                        <th className="px-5 py-3 text-left">Department</th>
                        <th className="px-5 py-3 text-left">Job title</th>
                        <th className="px-5 py-3 text-left">Fingerprints</th>
                        <th className="px-5 py-3 text-left">Card number</th>
                        <th className="px-5 py-3 text-left">Enrolled on</th>
                        <th className="px-5 py-3 text-right">Punches</th>
                    </tr>
                </Thead>
                <tbody>
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={11}>
                                <EmptyState
                                    title={searching || filtering ? 'No matches' : 'No employees yet'}
                                    hint={searching
                                        ? `Nothing matches "${search.trim()}".`
                                        : filtering
                                            ? 'No employee matches these filters. Clear them to see the whole roster.'
                                            : 'Import the staff list with `php artisan staff:import`, or add one by hand.'}
                                    action={!searching && !filtering && (
                                        <div className="flex flex-wrap justify-center gap-2">
                                            {canCreate && <Button onClick={onAdd}>+ Add your first employee</Button>}
                                            {/* Lets the report be seen before anyone is on the roster. */}
                                            <Link to="/employees/sample/report" className={`inline-flex min-h-[40px] items-center rounded-xl px-4 text-sm font-semibold text-violet-700 ring-1 ring-violet-200 hover:bg-violet-50 ${FOCUS}`}>See a sample report</Link>
                                        </div>
                                    )}
                                />
                            </td>
                        </tr>
                    )}
                    {rows.map((e, i) => (
                        // The row IS the menu trigger: clicking or tapping anywhere on
                        // it drops this person's actions down. Enter/Space do the same,
                        // because a row that opens a menu has to answer to the keys a
                        // button would (rule 7) — they used to open the detail dialog.
                        <tr
                            key={e.id}
                            onClick={(event) => toggleMenu(event, e)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' || event.key === ' ') {
                                    event.preventDefault();
                                    toggleMenu(event, e);
                                } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                                    // Opens the menu with the first/last item focused,
                                    // matching the Actions button it replaces.
                                    event.preventDefault();
                                    openMenuFromKey(event, e, event.key === 'ArrowDown' ? 'first' : 'last');
                                }
                            }}
                            tabIndex={0}
                            aria-haspopup="menu"
                            aria-expanded={menuFor === e.id}
                            aria-label={`Actions for ${[e.name, e.last_name].filter(Boolean).join(' ')}`}
                            /*
                             * Zebra first, hover second, and the order matters: Tailwind
                             * emits `hover:` after `odd:`/`even:`, so the hover tint wins
                             * over whichever stripe the row happens to sit on. Without
                             * that the highlight vanishes on every other row.
                             *
                             * The stripe is a reading aid across a wide table; the hover
                             * is the "you are on this row" signal and is deliberately
                             * stronger than both stripes.
                             *
                             * `aria-selected`-style open state is carried by the tint too,
                             * so the row that owns the menu is obvious once it drops.
                             */
                            className={`cursor-pointer odd:bg-white even:bg-slate-50/70 transition-colors duration-150 hover:bg-violet-50 ${
                                menuFor === e.id ? 'bg-violet-50' : ''
                            } ${FOCUS}`}
                        >
                            <td className="px-5 py-3.5 text-center tabular-nums text-slate-600">{i + 1}</td>
                            <td className="px-5 py-3.5">
                                <span className="font-medium text-slate-900">{[e.name, e.last_name].filter(Boolean).join(' ')}</span>
                                <EmploymentBadges employee={e} className="ml-2" />
                            </td>
                            <td className="px-5 py-3.5 font-mono text-xs text-slate-600">{e.employee_code}</td>
                            <td className="px-5 py-3.5">
                                <DeviceUserIdCell employee={e} />
                            </td>
                            <td className="px-5 py-3.5 text-slate-600">{orgOf(e).company || '—'}</td>
                            <td className="px-5 py-3.5 text-slate-600">{orgOf(e).department || '—'}</td>
                            <td className="max-w-[14rem] truncate px-5 py-3.5 text-slate-600" title={e.job_title || undefined}>{e.job_title || '—'}</td>
                            <td className="px-5 py-3.5">
                                <FingerprintCell employee={e} />
                            </td>
                            <td className="px-5 py-3.5">
                                <CardCell employee={e} />
                            </td>
                            <td className="px-5 py-3.5">
                                <TerminalsCell employee={e} />
                            </td>
                            <td className="px-5 py-3.5 text-right font-medium tabular-nums text-slate-700">
                                {(e.attendance_logs_count ?? 0).toLocaleString()}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </TableShell>

            {/*
              * One menu for the whole table, portalled to <body> and anchored to the
              * row that opened it. Keyed on the employee so reopening the same person
              * starts the keyboard back at the top of the list.
              */}
            {menuRow && (
                <ActionMenu
                    key={menuRow.id}
                    label={`Actions for ${menuRow.name}`}
                    // Anchored to the click, not the row: the panel belongs to where
                    // the pointer is. `align` only matters to the keyboard fallback.
                    align="left"
                    hideTrigger
                    anchorRef={menuAnchor}
                    anchorPoint={menuPoint}
                    open
                    initialFocus={menuFocus}
                    onOpenChange={(next) => !next && setMenuFor(null)}
                    items={employeeRowActions(menuRow, { canUpdate, canEnroll, canDelete, onReport, onView, onEdit, onPush, onEnroll, onReadFingerprints, onPushFingerprints, onMerge, onScan, onDelete })}
                />
            )}

            <Pager pagination={pagination} onPage={onPage} loading={loading} noun="employees" />
            </div>
            </div>

            <p className="text-xs text-slate-500">
                Enrolled fingers and card numbers come from the terminal's own user and template tables, as of
                the last read{lastRead ? ` (${lastRead})` : ''}. Hover a hand for the exact slots; a ZKTeco user
                record holds one card field, so nobody can have more than one.
            </p>
        </div>
    );
}
