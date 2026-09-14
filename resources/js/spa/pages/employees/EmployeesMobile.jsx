import { Badge, Button, Card, EmptyState, InlineAlert, PageHeader, TextField } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import { CardCell, FingerprintCell, TerminalsCell } from './enrollmentCells';

/*
| Mobile employees: search + stacked tap-friendly cards (no wide table).
|
| Each card is three stacked bands rather than one flex row: identity, enrollment,
| actions. The single-row version overflowed at 360px once the hand diagram and
| card number joined the department badge and both buttons.
*/
export default function EmployeesMobile({
    rows, search, onSearch,
    canCreate, canDelete, canEnroll, canReadDevice,
    readingDevice, total, perPage,
    onAdd, onDelete, onEnroll, onView, onReadFromDevice,
}) {
    const searching = search.trim().length > 0;
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

            <TextField
                placeholder="Search employees…"
                aria-label="Search employees"
                value={search}
                onChange={(e) => onSearch(e.target.value)}
            />

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
                        title={searching ? 'No matches' : 'No employees yet'}
                        hint={searching
                            ? `Nothing matches "${search.trim()}".`
                            : 'Add one by hand, or import the roster straight off a device.'}
                        action={!searching && canCreate && <Button onClick={onAdd}>+ Add your first employee</Button>}
                    />
                </Card>
            ) : (
                <div className="space-y-2">
                    {rows.map((e) => (
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
                                <div className="min-w-0">
                                    <div className="truncate font-semibold text-slate-900">{e.name}</div>
                                    <div className="font-mono text-xs text-slate-500">{e.employee_code}</div>
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

                            <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-slate-100 pt-3">
                                <FingerprintCell employee={e} />
                                <CardCell employee={e} />
                            </div>

                            {/* Rule 5: the desktop "Enrolled on" column, same data, stacked. */}
                            <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                <TerminalsCell employee={e} />
                            </div>

                            {(canEnroll || canDelete) && (
                                <div
                                    className="mt-3 flex items-center gap-4 border-t border-slate-100 pt-3"
                                    onClick={(event) => event.stopPropagation()}
                                >
                                    {canEnroll && (
                                        <button onClick={() => onEnroll(e)} className={`rounded-lg px-1 py-0.5 text-sm font-medium text-violet-700 ${FOCUS}`}>
                                            Enroll
                                        </button>
                                    )}
                                    {canDelete && (
                                        <button onClick={() => onDelete(e)} className={`ml-auto rounded-lg px-1 py-0.5 text-sm font-medium text-rose-600 ${FOCUS}`}>
                                            Delete
                                        </button>
                                    )}
                                </div>
                            )}
                        </Card>
                    ))}
                </div>
            )}
        </div>
    );
}
