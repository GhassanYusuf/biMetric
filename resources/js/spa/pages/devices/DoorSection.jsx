import { Badge, Button, SectionCard, Skeleton } from '../../shared/ui';
import { can, relativeTime } from './parts';
import { absoluteTime } from './statusParts';

/*
| The terminal's door, as it reports it (owner, 2026-11-18: "can we see if the door is locked and the
| status of the lock"). Shared by the desktop and mobile layouts (rule 5); DeviceDetail owns the read.
|
| Only what the hardware says, and how sure we are (rules 3 and 9):
|   - an F18 reports no live door state — only its door and alarm events and its lock settings, so the
|     section says so instead of drawing a badge it cannot back;
|   - a C3 panel (Glass Door) reports each door open / closed and its alarms; while that level is
|     "implemented" (not yet checked at the doors) the readings carry a warning;
|   - nothing here opens a door. Reading is on demand: it talks to the terminal.
*/
const SETTINGS = {
    LockOn: (v) => ['Lock opens for', `${v} s`],
    DoorSensorTimeout: (v) => ['Door-open alarm after', `${v} s`],
};

function DoorBadge({ door }) {
    if (door.sensor === 'open') return <Badge tone="amber">Open</Badge>;
    if (door.sensor === 'closed') return <Badge tone="emerald">Closed</Badge>;
    return <Badge tone="slate">Unknown</Badge>;
}

export function hasDoor(device) {
    return can(device, 'door.events') || can(device, 'door.status');
}

export default function DoorSection({ device, door, loading, onRead }) {
    if (!hasDoor(device)) return null;

    const statusLevel = door?.levels?.status;
    const unconfirmed = statusLevel === 'implemented';
    const subtitle = door?.read_at
        ? `Read from the terminal ${relativeTime(door.read_at)}`
        : 'Reads the terminal when you ask. Nothing here opens a door.';

    return (
        <SectionCard
            title="Door"
            subtitle={subtitle}
            action={<Button variant="secondary" className="min-h-[36px] px-3 text-sm" loading={loading} onClick={onRead}>{door ? 'Read again' : 'Read door'}</Button>}
        >
            {loading && !door && (
                <div className="space-y-2" aria-hidden="true">
                    <Skeleton className="h-6 w-2/3" />
                    <Skeleton className="h-4 w-full" />
                    <Skeleton className="h-4 w-5/6" />
                </div>
            )}

            {!door && !loading && (
                <p className="text-sm text-slate-600">
                    {can(device, 'door.status')
                        ? 'Shows each door open or closed, its alarms, and the latest door events.'
                        : 'Shows the lock settings and the latest door and alarm events (exit button, alarms).'}
                </p>
            )}

            {door && (
                <div className="space-y-4">
                    {door.status ? (
                        <div>
                            <div className="flex flex-wrap gap-2">
                                {door.status.doors.length === 0 && <span className="text-sm text-slate-600">No door sensor reports a state.</span>}
                                {door.status.doors.map((d) => (
                                    <div key={d.door} className="flex items-center gap-2 rounded-xl px-3 py-2 ring-1 ring-slate-200">
                                        <span className="text-sm font-medium text-slate-800">Door {d.door}</span>
                                        <DoorBadge door={d} />
                                        {d.alarm && <Badge tone="rose">Alarm</Badge>}
                                        {d.open_too_long && <Badge tone="rose">Open too long</Badge>}
                                    </div>
                                ))}
                            </div>
                            {unconfirmed && (
                                <p className="mt-2 text-xs text-amber-800">
                                    Not yet checked against the doors themselves: if a closed door shows Open, the panel's door-sensor setting does not match its wiring.
                                </p>
                            )}
                        </div>
                    ) : (
                        <p className="text-sm text-slate-600">
                            This terminal does not report whether the door is open or locked right now — only the events below.
                        </p>
                    )}

                    {Object.keys(door.settings ?? {}).some((k) => SETTINGS[k]) && (
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
                            {Object.entries(door.settings).filter(([k]) => SETTINGS[k]).map(([k, v]) => {
                                const [label, value] = SETTINGS[k](v);
                                return (
                                    <div key={k}>
                                        <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
                                        <dd className="text-sm font-medium text-slate-900">{value}</dd>
                                    </div>
                                );
                            })}
                        </dl>
                    )}

                    <div>
                        <h3 className="text-xs font-medium uppercase tracking-wide text-slate-500">Latest door events</h3>
                        {door.events.length === 0 ? (
                            <p className="mt-1 text-sm text-slate-600">
                                {door.status ? 'None since the last read — this panel hands each event out once.' : 'None recorded.'}
                            </p>
                        ) : (
                            <ul className="mt-1 divide-y divide-slate-100">
                                {door.events.slice(0, 15).map((e, i) => (
                                    <li key={i} className="flex flex-wrap items-baseline justify-between gap-x-3 py-1.5 text-sm">
                                        <span className="text-slate-800">
                                            {e.what}
                                            {e.door ? <span className="text-slate-500"> · door {e.door}</span> : null}
                                            {e.name || e.pin ? <span className="text-slate-500"> · {e.name ?? `user ${e.pin}`}</span> : null}
                                        </span>
                                        <span className="text-xs tabular-nums text-slate-500">{e.time ? absoluteTime(e.time) : '—'}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            )}
        </SectionCard>
    );
}
