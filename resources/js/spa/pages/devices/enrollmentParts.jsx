import { slotName } from '../employees/handGeometry';
import { absoluteTime } from './statusParts';

/*
| The device's own progress trail for one capture.
|
| This is raw evidence, not a designed narrative. Each entry is what the terminal
| actually reported — beeped, waiting_for_finger, finger_placed_sample_1,
| sample_score_N, enrolled — and it is the only thing available when somebody says
| they stood at the device and nothing happened. It shows whether the terminal ever
| beeped at all, whether a finger was ever placed, and what it scored.
|
| So the raw event key is shown verbatim in mono alongside a readable label, rather
| than being replaced by one. A prettified summary would throw away the detail that
| makes the trail worth keeping.
*/

/*
| The complete event vocabulary the protocol library emits — all five of them.
|
| Nothing else is listed here on purpose. Inventing labels for events the device never
| sends (sample scores, "finger placed") would make this screen look like it knows
| more about the capture than the hardware actually reports. Anything unrecognised
| falls through and is shown verbatim.
|
| A trail can repeat: `beeped, waiting_for_finger, timeout, beeped,
| waiting_for_finger, timeout` is one session that retried, which is why the entries
| are listed in order rather than deduplicated.
*/
const EVENT_LABELS = {
    beeped: 'Terminal beeped — the trigger landed',
    waiting_for_finger: 'Waiting for a finger',
    waiting_for_card: 'Waiting for a card',
    enrolled: 'Enrolled successfully',
    timeout: 'Nobody presented in time',
};

/**
 * Anatomical slot names, reusing the employee module's convention so a slot is
 * never described one way here and another way on the employee record.
 */
export const FINGER_NAMES = Object.fromEntries(
    Array.from({ length: 10 }, (_, slot) => [slot, slotName(slot)]),
);

function label(event) {
    const key = typeof event === 'string' ? event : (event?.event ?? event?.name ?? '');

    // Unrecognised events pass through verbatim. Firmware may report something this
    // build has never seen, and showing it is more useful than hiding it.
    return EVENT_LABELS[key] ?? (key.replace(/_/g, ' ') || 'Unlabelled event');
}

function tone(event) {
    const key = typeof event === 'string' ? event : (event?.event ?? '');
    if (key === 'enrolled') return 'bg-emerald-500';
    // A timeout is a person not turning up, not a broken device — amber, never rose.
    if (key === 'timeout') return 'bg-amber-500';
    return 'bg-slate-300';
}

export function EventTrail({ session }) {
    const events = session.events ?? [];

    if (events.length === 0) {
        return (
            <p className="text-sm text-slate-500">
                The terminal reported nothing for this session. If it never reached{' '}
                <span className="font-mono text-xs">beeped</span>, the command never got through — check the device is reachable
                and that the capture was not cancelled before it was sent.
            </p>
        );
    }

    return (
        <div className="space-y-3">
            <ol className="space-y-2">
                {events.map((event, i) => {
                    const key = typeof event === 'string' ? event : (event?.event ?? '');
                    const at = typeof event === 'object' ? (event.at ?? event.timestamp) : null;
                    return (
                        <li key={`${key}-${i}`} className="flex items-start gap-3">
                            <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${tone(event)}`} aria-hidden="true" />
                            <div className="min-w-0 flex-1">
                                <span className="text-sm text-slate-800">{label(event)}</span>
                                {/* The raw key, verbatim — this is the part that debugs a failure. */}
                                <span className="ml-2 font-mono text-[11px] text-slate-500">{key}</span>
                                {typeof event === 'object' && event.message && (
                                    <p className="text-xs text-slate-600">{event.message}</p>
                                )}
                            </div>
                            {at && <span className="shrink-0 text-xs tabular-nums text-slate-500">{absoluteTime(at)}</span>}
                        </li>
                    );
                })}
            </ol>

            <dl className="flex flex-wrap gap-x-6 gap-y-1 border-t border-slate-200 pt-3 text-xs text-slate-500">
                <div>
                    <dt className="inline font-semibold text-slate-600">Requested </dt>
                    <dd className="inline">{absoluteTime(session.requested_at)}</dd>
                </div>
                {session.sent_at && (
                    <div>
                        <dt className="inline font-semibold text-slate-600">Sent to device </dt>
                        <dd className="inline">{absoluteTime(session.sent_at)}</dd>
                    </div>
                )}
                {session.completed_at && (
                    <div>
                        <dt className="inline font-semibold text-slate-600">Finished </dt>
                        <dd className="inline">{absoluteTime(session.completed_at)}</dd>
                    </div>
                )}
                <div>
                    <dt className="inline font-semibold text-slate-600">Timeout </dt>
                    <dd className="inline">{session.timeout_seconds}s</dd>
                </div>
            </dl>
        </div>
    );
}
