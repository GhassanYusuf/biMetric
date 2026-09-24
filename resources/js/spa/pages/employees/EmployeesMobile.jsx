import { ActionMenu, Badge, Button, Card, EmptyState, InlineAlert, PageHeader } from '../../shared/ui';
import AutoPushToggle from './AutoPushToggle';
import { FOCUS } from '../../shared/theme';
import { CardCell, FingerprintCell, TerminalsCell } from './enrollmentCells';
import EmployeeFilters from './EmployeeFilters';
import NationalIdCell from './NationalIdCell';
import { employeeRowActions } from './rowActions';

/*
| Mobile employees: search + stacked tap-friendly cards (no wide table).
|
| Each card is three stacked bands rather than one flex row: identity, enrollment,
| actions. The single-row version overflowed at 360px once the hand diagram and
| card number joined the department badge and both buttons.
*/
export default function EmployeesMobile({
    rows, search, onSearch, filters, onFilters, departments, devices,
    canCreate, canDelete, canEnroll, canUpdate, canReadDevice, canSetNationalId,
    readingDevice, total, perPage,
    autoPush, savingAutoPush, canManageSettings, onToggleAutoPush,
    onAdd, onDelete, onEdit, onPush, onMerge, onEnroll, onReadFingerprints, onView, onReadFromDevice, onSaveNationalId,
}) {
    const searching = search.trim().length > 0;
    const filtering = Object.values(filters ?? {}).some((v) => v !== '' && v != null);
    const missingHere = rows.filter((e) => !e.has_national_id).length;
    const truncated = total != null && total > rows.length;

    return (
        <div className="space-y-4">
            <PageHeader
                title="Employees"
                subtitle={searching ? `${rows.length} matching` : `${(total ?? rows.length).toLocaleString()} on the roster`}
                compact
            >
                {canCreate && <Button onClick={onAdd} className="px-3">+ Add</Button>}
            </PageHeader>

            {canManageSettings && (
                <div className="flex justify-end">
                    <AutoPushToggle on={autoPush} busy={savingAutoPush} onToggle={onToggleAutoPush} />
                </div>
            )}

            {/* Rule 5: the same filters as desktop, stacked rather than in a row. */}
            <EmployeeFilters
                search={search}
                onSearch={onSearch}
                filters={filters ?? {}}
                onChange={onFilters}
                departments={departments}
                devices={devices}
                stacked
            />

            {canSetNationalId && missingHere > 0 && (
                <InlineAlert tone="info">
                    {missingHere} of the {rows.length} shown {missingHere === 1 ? 'has' : 'have'} no national ID, so
                    their attendance cannot be exported to payroll.
                </InlineAlert>
            )}

            {canReadDevice && (
                <Button variant="secondary" className="w-full" loading={readingDevice} onClick={onReadFromDevice}>
                    {readingDevice ? 'Reading terminal…' : 'Read enrollment from device'}
                </Button>
            )}

            {truncated && (
                <InlineAlert tone="info">
                    Showing the first {rows.length} of {total.toLocaleString()} employees. Search to find someone
                    outside this page.
                </InlineAlert>
            )}

            {rows.length === 0 ? (
                <Card>
                    <EmptyState
                        title={searching || filtering ? 'No matches' : 'No employees yet'}
                        hint={searching
                            ? `Nothing matches "${search.trim()}".`
                            : filtering
                                ? 'No employee matches these filters. Clear them to see the whole roster.'
                                : 'Import the staff list with `php artisan staff:import`, or add one by hand.'}
                        action={!searching && !filtering && canCreate && <Button onClick={onAdd}>+ Add your first employee</Button>}
                    />
                </Card>
            ) : (
                <div className="space-y-2">
                    {rows.map((e, i) => (
                        <Card
                            key={e.id}
                            // Matches the desktop row: the card opens the enrollment
                            // detail, and is reachable by keyboard too.
                            role="button"
                            tabIndex={0}
                            aria-label={`View ${e.name}'s enrollment`}
                            onClick={() => onView(e)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' || event.key === ' ') {
                                    event.preventDefault();
                                    onView(e);
                                }
                            }}
                            className={`cursor-pointer p-4 active:bg-slate-50 ${FOCUS}`}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex min-w-0 items-baseline gap-2.5">
                                    {/* Rule 5: the desktop "No." column, same number.
                                        Cards are already visually discrete, so they get
                                        the number but not the stripe — and `active:` is
                                        the touch equivalent of the desktop hover. */}
                                    <span className="shrink-0 tabular-nums text-slate-600">{i + 1}</span>
                                    <div className="min-w-0">
                                        <div className="truncate font-semibold text-slate-900">{e.name}</div>
                                        <div className="font-mono text-xs text-slate-500">{e.employee_code}</div>
                                    </div>
                                </div>
                                <div className="shrink-0 text-right text-xs tabular-nums text-slate-500">
                                    {(e.attendance_logs_count ?? 0).toLocaleString()} punches
                                </div>
                            </div>

                            {e.department && (
                                <div className="mt-2.5">
                                    <Badge tone="violet">{e.department}</Badge>
                                </div>
                            )}

                            {/* Rule 5: the desktop "National ID" column, same editor. */}
                            <div
                                className="mt-3 flex items-center gap-2 border-t border-slate-100 pt-3"
                                onClick={(event) => event.stopPropagation()}
                            >
                                <span className="text-xs font-medium text-slate-500">National ID</span>
                                <NationalIdCell
                                    employee={e}
                                    canEdit={canSetNationalId}
                                    onSave={onSaveNationalId}
                                />
                            </div>

                            <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-slate-100 pt-3">
                                <FingerprintCell employee={e} />
                                <CardCell employee={e} />
                            </div>

                            {/* Rule 5: the desktop "Enrolled on" column, same data, stacked. */}
                            <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                <TerminalsCell employee={e} />
                            </div>

                            {(canEnroll || canUpdate || canDelete) && (
                                <div
                                    className="mt-3 flex justify-end border-t border-slate-100 pt-3"
                                    onClick={(event) => event.stopPropagation()}
                                    // Same reason as the desktop cell: the card's Enter/Space
                                    // opens the employee, and the menu's keys bubble to it.
                                    onKeyDown={(event) => event.stopPropagation()}
                                >
                                    {/* The same list as the desktop row — rule 5. */}
                                    <ActionMenu
                                        label="Actions"
                                        items={employeeRowActions(e, { canUpdate, canEnroll, canDelete, onEdit, onPush, onEnroll, onReadFingerprints, onMerge, onDelete })}
                                    />
                                </div>
                            )}
                        </Card>
                    ))}
                </div>
            )}
        </div>
    );
}
