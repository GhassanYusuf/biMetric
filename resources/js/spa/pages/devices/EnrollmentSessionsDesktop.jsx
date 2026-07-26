import { Link } from 'react-router-dom';
import { Badge, Button, Card, EmptyState, PageHeader, SelectField, TableShell, Thead } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import { EventTrail, FINGER_NAMES } from './enrollmentParts';
import { SESSION_STATUSES, SessionStatusBadge, absoluteTime } from './statusParts';

/*
| Desktop enrollment history: one row per capture, expandable to the device's own
| event trail.
|
| The trail is collapsed by default because most rows are uneventful successes, and
| expanded on demand because when a capture fails it is the only thing that matters.
*/
export default function EnrollmentSessionsDesktop({
    sessions, devices, meta, filters, loading, activeCount, expanded,
    canCancel, onToggleExpand, onFilter, onCancel, onRefresh,
}) {
    return (
        <div className="space-y-5">
            <PageHeader
                title="Enrollment sessions"
                subtitle={
                    sessions.length === 0
                        ? 'Every fingerprint and card capture, with the terminal’s own progress trail'
                        : `${meta?.pagination?.total?.toLocaleString() ?? sessions.length} session${sessions.length === 1 ? '' : 's'}${activeCount ? ` · ${activeCount} waiting at a terminal now` : ''}`
                }
            >
                <Button variant="secondary" loading={loading} onClick={onRefresh}>Refresh</Button>
            </PageHeader>

            <div className="flex flex-wrap items-end gap-3">
                <SelectField label="Terminal" className="min-w-[14rem]" value={filters.device_id} onChange={(e) => onFilter('device_id', e.target.value)}>
                    <option value="">All terminals</option>
                    {devices.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                </SelectField>
                <SelectField label="Status" className="min-w-[11rem]" value={filters.status} onChange={(e) => onFilter('status', e.target.value)}>
                    <option value="">Any status</option>
                    {SESSION_STATUSES.map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
                </SelectField>
                <label className={`inline-flex min-h-[42px] cursor-pointer items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50 ${FOCUS}`}>
                    <input
                        type="checkbox"
                        checked={filters.active}
                        onChange={(e) => onFilter('active', e.target.checked)}
                        className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-400"
                    />
                    Still in progress only
                </label>
            </div>

            <TableShell>
                <Thead>
                    <tr>
                        <th className="px-5 py-3 text-left">Requested</th>
                        <th className="px-5 py-3 text-left">Employee</th>
                        <th className="px-5 py-3 text-left">Terminal</th>
                        <th className="px-5 py-3 text-left">Capturing</th>
                        <th className="px-5 py-3 text-left">Status</th>
                        <th className="px-5 py-3" />
                    </tr>
                </Thead>
                <tbody className="divide-y divide-slate-100">
                    {sessions.length === 0 && (
                        <tr>
                            <td colSpan={6}>
                                <EmptyState
                                    title={filters.device_id || filters.status || filters.active ? 'Nothing matches those filters' : 'No captures yet'}
                                    hint={filters.device_id || filters.status || filters.active
                                        ? 'Clear the filters to see the full history.'
                                        : 'Start a fingerprint or card capture from an employee’s record and it will be tracked here.'}
                                />
                            </td>
                        </tr>
                    )}
                    {sessions.map((s) => {
                        const isOpen = expanded === s.id;
                        return [
                            <tr key={s.id} className="align-top transition-colors duration-150 hover:bg-slate-50/70">
                                <td className="px-5 py-3.5 text-slate-600">{absoluteTime(s.requested_at)}</td>
                                <td className="px-5 py-3.5">
                                    <div className="font-medium text-slate-900">{s.employee_name ?? `Employee ${s.employee_id}`}</div>
                                </td>
                                <td className="px-5 py-3.5">
                                    <Link to={`/devices/${s.device_id}`} className="font-medium text-violet-700 hover:underline">
                                        {s.device_name ?? `Device ${s.device_id}`}
                                    </Link>
                                </td>
                                <td className="px-5 py-3.5 text-slate-700">
                                    {s.type === 'card' ? (
                                        <>
                                            Card
                                            {s.captured_card_number && (
                                                <span className="ml-1.5 font-mono text-xs text-slate-600">{s.captured_card_number}</span>
                                            )}
                                        </>
                                    ) : (
                                        <>
                                            Fingerprint
                                            {s.finger_slot != null && (
                                                <span className="ml-1.5 text-xs text-slate-500">
                                                    {FINGER_NAMES[s.finger_slot] ?? `slot ${s.finger_slot}`}
                                                </span>
                                            )}
                                        </>
                                    )}
                                </td>
                                <td className="px-5 py-3.5">
                                    <div className="flex items-center gap-1.5">
                                        <SessionStatusBadge status={s.status} />
                                        {s.is_active && <Badge tone="blue">Live</Badge>}
                                    </div>
                                    {s.message && <div className="mt-1 max-w-sm text-xs text-slate-600">{s.message}</div>}
                                    {s.device_error_code != null && (
                                        <div className="mt-1 font-mono text-xs text-rose-600">device code {s.device_error_code}</div>
                                    )}
                                </td>
                                <td className="px-5 py-3.5">
                                    <div className="flex items-center justify-end gap-1.5">
                                        <button
                                            onClick={() => onToggleExpand(s.id)}
                                            aria-expanded={isOpen}
                                            className={`rounded-lg px-2 py-1 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-100 ${FOCUS}`}
                                        >
                                            {isOpen ? 'Hide trail' : `Trail (${(s.events ?? []).length})`}
                                        </button>
                                        {canCancel && s.is_active && (
                                            <button
                                                onClick={() => onCancel(s)}
                                                className={`rounded-lg px-2 py-1 text-xs font-semibold text-rose-600 transition-colors hover:bg-rose-50 ${FOCUS}`}
                                            >
                                                Cancel
                                            </button>
                                        )}
                                    </div>
                                </td>
                            </tr>,
                            isOpen && (
                                <tr key={`${s.id}-trail`} className="bg-slate-50/60">
                                    <td colSpan={6} className="px-5 py-4">
                                        <EventTrail session={s} />
                                    </td>
                                </tr>
                            ),
                        ];
                    })}
                </tbody>
            </TableShell>

            {sessions.length > 0 && (
                <Card className="p-4">
                    <p className="text-xs text-slate-500">
                        A capture is queued, not synchronous — the terminal beeps and waits for a person, and the session records what happened even if the
                        browser is closed. <strong className="font-semibold text-slate-600">Timed out</strong> means nobody presented a finger or card in
                        time; it is not a device fault.
                    </p>
                </Card>
            )}
        </div>
    );
}
