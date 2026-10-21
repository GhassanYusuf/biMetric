import { Badge, Button, Card, EmptyState, PageHeader, Pager, ActionMenu } from '../../shared/ui';
import AutoPushToggle from './AutoPushToggle';
import { FOCUS } from '../../shared/theme';
import { CardCell, FingerprintCell, TerminalsCell } from './enrollmentCells';
import EmployeeFilters from './EmployeeFilters';
import { EmploymentBadges, orgOf } from './employeeLabels';
import DepartmentTree from './DepartmentTree';
import { Link } from 'react-router-dom';
import { employeeRowActions } from './rowActions';
import { useRef, useState } from 'react';

/*
| Mobile employees: search + stacked tap-friendly cards (no wide table).
|
| Each card is three stacked bands rather than one flex row: identity, enrollment,
| actions. The single-row version overflowed at 360px once the hand diagram and
| card number joined the department badge and both buttons.
*/
export default function EmployeesMobile({
    rows, search, onSearch, filters, onFilters, departments, unassignedCount, departmentsLoaded, devices,
    canCreate, canDelete, canEnroll, canUpdate, canReadDevice,
    readingDevice, total, loading, pagination, onPage,
    autoPush, savingAutoPush, canManageSettings, onToggleAutoPush,
    onAdd, onDelete, onEdit, onPush, onMerge, onScan, onEnroll, onReadFingerprints, onPushFingerprints, onView, onReadFromDevice, onReport, exportItems,
}) {
    const searching = search.trim().length > 0;
    const filtering = Object.values(filters ?? {}).some((v) => v !== '' && v != null);

    /*
     | The card is the menu trigger (2026-10-05), the same decision as the desktop
     | row: tap anywhere on the person, their actions drop down, and there is no
     | separate button to hunt for with a thumb.
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
        menuAnchor.current = event.currentTarget;
        setMenuPoint({ x: event.clientX, y: event.clientY });
        setMenuFocus('none');
        setMenuFor(employee.id);
    }

    function openMenuFromKey(event, employee, focus) {
        if (menuFor === employee.id) return;
        menuAnchor.current = event.currentTarget;
        setMenuPoint(null);
        setMenuFocus(focus);
        setMenuFor(employee.id);
    }

    return (
        <div className="space-y-4">
            <PageHeader
                title="Employees"
                subtitle={searching ? `${rows.length} matching` : `${(total ?? rows.length).toLocaleString()} on the roster`}
                compact
            >
                {/* Rule 5: the same export as desktop — the list as filtered. */}
                <ActionMenu label="Export" className="min-h-[38px] px-3 text-sm" items={exportItems} />
                {canCreate && <Button onClick={onAdd} className="px-3">+ Add</Button>}
            </PageHeader>

            {canManageSettings && (
                <div className="flex justify-end">
                    <AutoPushToggle on={autoPush} busy={savingAutoPush} onToggle={onToggleAutoPush} />
                </div>
            )}

            {/* Rule 5: the same tree as desktop's left panel, folded into a card. */}
            <DepartmentTree
                variant="collapsible"
                departments={departments}
                unassignedCount={unassignedCount}
                loaded={departmentsLoaded}
                value={filters?.department_id ?? ''}
                onChange={(id) => onFilters({ ...(filters ?? {}), department_id: id })}
            />

            {/* Rule 5: the same filters as desktop, stacked rather than in a row. */}
            <EmployeeFilters
                search={search}
                onSearch={onSearch}
                filters={filters ?? {}}
                onChange={onFilters}
                devices={devices}
                stacked
            />

            {/* Offered to everyone (2026-10-06, owner's request): the scan reads,
                and noticing a duplicate needs no permission. */}
            <Button variant="secondary" className="w-full" onClick={() => onScan()}>
                Scan for duplicates
            </Button>

            {canReadDevice && (
                <Button variant="secondary" className="w-full" loading={readingDevice} onClick={onReadFromDevice}>
                    {readingDevice ? 'Reading terminal…' : 'Read enrollment from device'}
                </Button>
            )}

            {/* The endpoint paginates, so this is one page of the roster. The pager
                below carries the real total, which says it better than a banner. */}
            {rows.length === 0 ? (
                <Card>
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
                </Card>
            ) : (
                <div className="space-y-2">
                    {rows.map((e, i) => (
                        <Card
                            key={e.id}
                            // Matches the desktop row: the card opens the actions
                            // menu, and is reachable by keyboard too.
                            role="button"
                            tabIndex={0}
                            aria-haspopup="menu"
                            aria-expanded={menuFor === e.id}
                            aria-label={`Actions for ${[e.name, e.last_name].filter(Boolean).join(' ')}`}
                            onClick={(event) => toggleMenu(event, e)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' || event.key === ' ') {
                                    event.preventDefault();
                                    toggleMenu(event, e);
                                } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                                    event.preventDefault();
                                    openMenuFromKey(event, e, event.key === 'ArrowDown' ? 'first' : 'last');
                                }
                            }}
                            className={`cursor-pointer p-4 active:bg-slate-50 ${menuFor === e.id ? 'bg-violet-50/60' : ''} ${FOCUS}`}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex min-w-0 items-baseline gap-2.5">
                                    {/* Rule 5: the desktop "No." column, same number.
                                        Cards are already visually discrete, so they get
                                        the number but not the stripe — and `active:` is
                                        the touch equivalent of the desktop hover. */}
                                    <span className="shrink-0 tabular-nums text-slate-600">{i + 1}</span>
                                    <div className="min-w-0">
                                        <div className="truncate font-semibold text-slate-900">{[e.name, e.last_name].filter(Boolean).join(" ")}</div>
                                        <div className="font-mono text-xs text-slate-500">{e.employee_code}</div>
                                    </div>
                                </div>
                                <div className="shrink-0 text-right text-xs tabular-nums text-slate-500">
                                    {(e.attendance_logs_count ?? 0).toLocaleString()} punches
                                </div>
                            </div>

                            {/* Rule 5: the desktop Company, Department and Job title columns. */}
                            {(orgOf(e).company || e.job_title || e.resigned_at || e.employment_type === 'part_time') && (
                                <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
                                    {orgOf(e).company && (
                                        <Badge tone="violet">{[orgOf(e).company, orgOf(e).department].filter(Boolean).join(' › ')}</Badge>
                                    )}
                                    <EmploymentBadges employee={e} />
                                    {e.job_title && <span className="min-w-0 truncate text-xs text-slate-600">{e.job_title}</span>}
                                </div>
                            )}

                            <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-slate-100 pt-3">
                                <FingerprintCell employee={e} />
                                <CardCell employee={e} />
                            </div>

                            {/* Rule 5: the desktop "Enrolled on" column, same data, stacked. */}
                            <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                <TerminalsCell employee={e} />
                            </div>
                        </Card>
                    ))}

                    {/* One menu for the whole list, anchored to the card that opened it
                        — the same list as the desktop row, from the same source. */}
                    {menuRow && (
                        <ActionMenu
                            key={menuRow.id}
                            label={`Actions for ${menuRow.name}`}
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
                </div>
            )}

            <Pager pagination={pagination} onPage={onPage} loading={loading} noun="employees" />
        </div>
    );
}
