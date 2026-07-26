import { Link } from 'react-router-dom';
import { Badge, Button, Card, EmptyState, PageHeader, SelectField } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import { EventTrail, FINGER_NAMES } from './enrollmentParts';
import { SESSION_STATUSES, SessionStatusBadge, shortTime } from './statusParts';

/*
| Mobile enrollment history.
|
| Live sessions are pulled to the top and given the loudest treatment: on a phone the
| operator is usually standing at the terminal while it waits, and "is it listening
| right now?" is the only question that matters in that moment.
*/
export default function EnrollmentSessionsMobile({
    sessions, devices, filters, loading, activeCount, expanded,
    canCancel, onToggleExpand, onFilter, onCancel, onRefresh,
}) {
    const live = sessions.filter((s) => s.is_active);
    const past = sessions.filter((s) => !s.is_active);

    return (
        <div className="space-y-4">
            <PageHeader
                compact
                title="Enrollment sessions"
                subtitle={activeCount ? `${activeCount} waiting at a terminal now` : 'Fingerprint and card captures'}
            >
                <Button variant="secondary" className="min-h-[38px] px-3 text-xs" loading={loading} onClick={onRefresh}>
                    Refresh
                </Button>
            </PageHeader>

            <div className="grid grid-cols-2 gap-2">
                <SelectField aria-label="Filter by terminal" value={filters.device_id} onChange={(e) => onFilter('device_id', e.target.value)}>
                    <option value="">All terminals</option>
                    {devices.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                </SelectField>
                <SelectField aria-label="Filter by status" value={filters.status} onChange={(e) => onFilter('status', e.target.value)}>
                    <option value="">Any status</option>
                    {SESSION_STATUSES.map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
                </SelectField>
            </div>

            {sessions.length === 0 ? (
                <Card>
                    <EmptyState
                        title={filters.device_id || filters.status ? 'Nothing matches those filters' : 'No captures yet'}
                        hint="Start a fingerprint or card capture from an employee’s record and it will be tracked here."
                    />
                </Card>
            ) : (
                <div className="space-y-4">
                    {live.length > 0 && (
                        <div className="space-y-3">
                            <h2 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Waiting at a terminal</h2>
                            {live.map((s) => (
                                <SessionCard
                                    key={s.id} session={s} expanded={expanded === s.id} canCancel={canCancel}
                                    onToggleExpand={onToggleExpand} onCancel={onCancel} live
                                />
                            ))}
                        </div>
                    )}

                    {past.length > 0 && (
                        <div className="space-y-3">
                            {live.length > 0 && (
                                <h2 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Finished</h2>
                            )}
                            {past.map((s) => (
                                <SessionCard
                                    key={s.id} session={s} expanded={expanded === s.id} canCancel={canCancel}
                                    onToggleExpand={onToggleExpand} onCancel={onCancel}
                                />
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

function SessionCard({ session: s, expanded, canCancel, onToggleExpand, onCancel, live = false }) {
    const what = s.type === 'card'
        ? 'Card'
        : `Fingerprint${s.finger_slot != null ? ` · ${FINGER_NAMES[s.finger_slot] ?? `slot ${s.finger_slot}`}` : ''}`;

    return (
        <Card className={`p-4 ${live ? 'ring-1 ring-violet-200' : ''}`}>
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <div className="truncate font-semibold text-slate-900">{s.employee_name ?? `Employee ${s.employee_id}`}</div>
                    <div className="mt-0.5 text-xs text-slate-500">{what}</div>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1">
                    <SessionStatusBadge status={s.status} />
                    {live && <Badge tone="blue">Live</Badge>}
                </div>
            </div>

            <div className="mt-2 flex items-center gap-1.5 text-xs text-slate-500">
                <Link to={`/devices/${s.device_id}`} className="font-medium text-violet-700">
                    {s.device_name ?? `Device ${s.device_id}`}
                </Link>
                <span aria-hidden="true">·</span>
                <span>{shortTime(s.requested_at)}</span>
            </div>

            {s.message && <p className="mt-2 text-xs text-slate-600">{s.message}</p>}
            {s.captured_card_number && (
                <p className="mt-1 font-mono text-xs text-slate-700">card {s.captured_card_number}</p>
            )}

            <div className="mt-3 flex gap-2 border-t border-slate-100 pt-3">
                <button
                    onClick={() => onToggleExpand(s.id)}
                    aria-expanded={expanded}
                    className={`min-h-[36px] flex-1 rounded-lg text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-100 ${FOCUS}`}
                >
                    {expanded ? 'Hide trail' : `Device trail (${(s.events ?? []).length})`}
                </button>
                {canCancel && live && (
                    <Button variant="secondary" className="min-h-[36px] flex-1 text-xs !text-rose-600" onClick={() => onCancel(s)}>
                        Cancel
                    </Button>
                )}
            </div>

            {expanded && (
                <div className="mt-3 border-t border-slate-100 pt-3">
                    <EventTrail session={s} />
                </div>
            )}
        </Card>
    );
}
