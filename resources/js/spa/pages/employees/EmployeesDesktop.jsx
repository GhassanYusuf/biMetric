import { Button, EmptyState, InlineAlert, PageHeader, TableShell, TextField, Thead } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import { CardCell, FingerprintCell, TerminalsCell, syncedAgo } from './enrollmentCells';

// Desktop employees: toolbar + wide data table.
export default function EmployeesDesktop({ rows, search, onSearch, canCreate, canDelete, canEnroll, canReadDevice, readingDevice, total, perPage, onAdd, onDelete, onEnroll, onView, onReadFromDevice }) {
    const searching = search.trim().length > 0;
    const showActions = canEnroll || canDelete;
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

            {/* The endpoint paginates; never let a partial list read as the whole roster. */}
            {truncated && (
                <InlineAlert tone="info">
                    Showing the first {rows.length} of {total.toLocaleString()} employees. Use search to find
                    someone outside this page.
                </InlineAlert>
            )}

            <TextField
                placeholder="Search by name, code, department or email…"
                value={search}
                onChange={(e) => onSearch(e.target.value)}
                aria-label="Search employees"
                className="max-w-sm"
            />

            <TableShell>
                <Thead>
                    <tr>
                        <th className="px-5 py-3 text-left">Name</th>
                        <th className="px-5 py-3 text-left">Code</th>
                        <th className="px-5 py-3 text-left">Department</th>
                        <th className="px-5 py-3 text-left">Fingerprints</th>
                        <th className="px-5 py-3 text-left">Card number</th>
                        <th className="px-5 py-3 text-left">Enrolled on</th>
                        <th className="px-5 py-3 text-right">Punches</th>
                        {showActions && <th className="px-5 py-3" />}
                    </tr>
                </Thead>
                <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={8}>
                                <EmptyState
                                    title={searching ? 'No matches' : 'No employees yet'}
                                    hint={searching ? `Nothing matches "${search.trim()}".` : 'Add one by hand, or import the roster straight off a device.'}
                                    action={!searching && canCreate && <Button onClick={onAdd}>+ Add your first employee</Button>}
                                />
                            </td>
                        </tr>
                    )}
                    {rows.map((e) => (
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
                            className={`cursor-pointer transition-colors duration-150 hover:bg-violet-50/40 ${FOCUS}`}
                        >
                            <td className="px-5 py-3.5 font-medium text-slate-900">{e.name}</td>
                            <td className="px-5 py-3.5 font-mono text-xs text-slate-600">{e.employee_code}</td>
                            <td className="px-5 py-3.5 text-slate-600">{e.department || '—'}</td>
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
                                <td className="px-5 py-3.5" onClick={(event) => event.stopPropagation()}>
                                    <div className="flex items-center justify-end gap-1.5">
                                        {canEnroll && (
                                            <Button variant="secondary" className="min-h-[34px] px-2.5 text-xs" onClick={() => onEnroll(e)}>
                                                Enroll
                                            </Button>
                                        )}
                                        {canDelete && (
                                            <button onClick={() => onDelete(e)} className="rounded-lg px-2 py-1 text-sm font-medium text-rose-600 transition-colors hover:bg-rose-50">
                                                Delete
                                            </button>
                                        )}
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
