import { ActionMenu, Button, EmptyState, InlineAlert, PageHeader, TableShell, Thead } from '../../shared/ui';
import AutoPushToggle from './AutoPushToggle';
import { FOCUS } from '../../shared/theme';
import { CardCell, FingerprintCell, TerminalsCell, syncedAgo } from './enrollmentCells';
import EmployeeFilters from './EmployeeFilters';
import NationalIdCell from './NationalIdCell';
import { employeeRowActions } from './rowActions';

// Desktop employees: toolbar + wide data table.
export default function EmployeesDesktop({
    rows, search, onSearch, filters, onFilters, departments, devices,
    canCreate, canDelete, canEnroll, canUpdate, canReadDevice, canSetNationalId,
    readingDevice, total, perPage,
    autoPush, savingAutoPush, canManageSettings, onToggleAutoPush,
    onAdd, onDelete, onEdit, onPush, onMerge, onEnroll, onReadFingerprints, onView, onReadFromDevice, onSaveNationalId,
}) {
    const searching = search.trim().length > 0;
    const filtering = Object.values(filters ?? {}).some((v) => v !== '' && v != null);
    // Not a count of the whole roster — only of what is on screen. Said that way in the
    // banner below, because "12 missing" would read as an estate-wide figure.
    const missingHere = rows.filter((e) => !e.has_national_id).length;
    const showActions = canEnroll || canUpdate || canDelete;
    const lastRead = syncedAgo(rows);
    const truncated = total != null && total > rows.length;

    return (
        <div className="space-y-5">
            <PageHeader
                title="Employees"
                subtitle={
                    (searching
                        ? `${rows.length} matching`
                        : `${(total ?? rows.length).toLocaleString()} on the roster`)
                    + ' · select a row to see their fingerprints and card'
                }
            >
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

            {/* The endpoint paginates; never let a partial list read as the whole roster. */}
            {truncated && (
                <InlineAlert tone="info">
                    Showing the first {rows.length} of {total.toLocaleString()} employees. Use search to find
                    someone outside this page.
                </InlineAlert>
            )}

            <EmployeeFilters
                search={search}
                onSearch={onSearch}
                filters={filters ?? {}}
                onChange={onFilters}
                departments={departments}
                devices={devices}
            />

            {/* State, not an event, so it stays put while it is true (rule 2). */}
            {canSetNationalId && missingHere > 0 && (
                <InlineAlert tone="info">
                    {missingHere} of the {rows.length} shown {missingHere === 1 ? 'has' : 'have'} no national ID, so
                    their attendance cannot be exported to payroll. Click the column to add one — Enter saves and
                    moves to the next.
                </InlineAlert>
            )}

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
                        <th className="px-5 py-3 text-left">Department</th>
                        <th className="px-5 py-3 text-left">National ID</th>
                        <th className="px-5 py-3 text-left">Fingerprints</th>
                        <th className="px-5 py-3 text-left">Card number</th>
                        <th className="px-5 py-3 text-left">Enrolled on</th>
                        <th className="px-5 py-3 text-right">Punches</th>
                        {showActions && <th className="px-5 py-3" />}
                    </tr>
                </Thead>
                <tbody>
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={10}>
                                <EmptyState
                                    title={searching || filtering ? 'No matches' : 'No employees yet'}
                                    hint={searching
                                        ? `Nothing matches "${search.trim()}".`
                                        : filtering
                                            ? 'No employee matches these filters. Clear them to see the whole roster.'
                                            : 'Import the staff list with `php artisan staff:import`, or add one by hand.'}
                                    action={!searching && !filtering && canCreate && <Button onClick={onAdd}>+ Add your first employee</Button>}
                                />
                            </td>
                        </tr>
                    )}
                    {rows.map((e, i) => (
                        // The row itself opens the enrollment detail; the action
                        // buttons stop propagation so they still do their own job.
                        <tr
                            key={e.id}
                            onClick={() => onView(e)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' || event.key === ' ') {
                                    event.preventDefault();
                                    onView(e);
                                }
                            }}
                            tabIndex={0}
                            aria-label={`View ${e.name}'s enrollment`}
                            /*
                             * Zebra first, hover second, and the order matters: Tailwind
                             * emits `hover:` after `odd:`/`even:`, so the hover tint wins
                             * over whichever stripe the row happens to sit on. Without
                             * that the highlight vanishes on every other row.
                             *
                             * The stripe is a reading aid across a wide table; the hover
                             * is the "you are on this row" signal and is deliberately
                             * stronger than both stripes.
                             */
                            className={`cursor-pointer odd:bg-white even:bg-slate-50/70 transition-colors duration-150 hover:bg-violet-50 ${FOCUS}`}
                        >
                            <td className="px-5 py-3.5 text-center tabular-nums text-slate-600">{i + 1}</td>
                            <td className="px-5 py-3.5 font-medium text-slate-900">{e.name}</td>
                            <td className="px-5 py-3.5 font-mono text-xs text-slate-600">{e.employee_code}</td>
                            <td className="px-5 py-3.5 text-slate-600">{e.department || '—'}</td>
                            {/* Stops the row's own click handler opening the detail
                                modal the moment the operator aims at the field. */}
                            <td className="px-5 py-3.5" onClick={(event) => event.stopPropagation()}>
                                <NationalIdCell
                                    employee={e}
                                    canEdit={canSetNationalId}
                                    onSave={onSaveNationalId}
                                />
                            </td>
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
                            {showActions && (
                                <td
                                    className="px-5 py-3.5"
                                    onClick={(event) => event.stopPropagation()}
                                    // The row opens the employee on Enter/Space and cancels
                                    // the key's default. The menu's panel is portalled but
                                    // React still bubbles its keys here, so without this a
                                    // keyboard user choosing "Delete" would open the detail.
                                    onKeyDown={(event) => event.stopPropagation()}
                                >
                                    <div className="flex justify-end">
                                        <ActionMenu
                                            label="Actions"
                                            items={employeeRowActions(e, { canUpdate, canEnroll, canDelete, onEdit, onPush, onEnroll, onReadFingerprints, onMerge, onDelete })}
                                        />
                                    </div>
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </TableShell>

            <p className="text-xs text-slate-500">
                Enrolled fingers and card numbers come from the terminal's own user and template tables, as of
                the last read{lastRead ? ` (${lastRead})` : ''}. Hover a hand for the exact slots; a ZKTeco user
                record holds one card field, so nobody can have more than one.
            </p>
        </div>
    );
}
