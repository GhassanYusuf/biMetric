import { FOCUS } from '../../shared/theme';

/*
| The "which terminals" checklist, shared by the add/edit form and the Push dialog.
|
| ONE component because the two callers must agree about what they are offering.
| They differ only in which devices they list, and that difference is the caller's
| to make: an edit offers the terminals the person is ALREADY on, a create or a push
| offers the ones that can take a write. A second copy of this list would be a second
| place for "which devices are live" to drift.
*/
export default function DeviceTargets({ targets, selected, onChange, compareName, emptyHint }) {
    if (targets.length === 0) {
        return <p className="mt-2 text-xs text-slate-500">{emptyHint}</p>;
    }

    function toggle(id, on) {
        onChange(on ? [...selected, id] : selected.filter((x) => x !== id));
    }

    return (
        <div className="mt-2 space-y-1.5">
            {targets.map((t) => {
                /*
                 * The terminal holds a different name from ours. This is the drift a
                 * push closes, and it is also what the push will OVERWRITE — so it is
                 * said at the point of deciding rather than left to be discovered.
                 */
                const drifted = t.held && compareName && t.held !== compareName;

                return (
                    <label key={t.id} className="flex items-start gap-2.5">
                        <input
                            type="checkbox"
                            checked={selected.includes(t.id)}
                            onChange={(e) => toggle(t.id, e.target.checked)}
                            className={`mt-0.5 h-4 w-4 rounded border-slate-300 text-violet-600 ${FOCUS}`}
                        />
                        <span className="min-w-0 text-sm text-slate-700">
                            {t.name}
                            {t.pin && <span className="text-slate-500"> · PIN {t.pin}</span>}
                            {drifted && (
                                <span className="block text-xs text-amber-700">
                                    holds “{t.held}” — this will overwrite it
                                </span>
                            )}
                        </span>
                    </label>
                );
            })}
        </div>
    );
}

/** Live enrollments as picker targets. Closed mappings are NEVER offered — see below. */
export function enrolledTargets(employee) {
    return (employee?.enrollments ?? [])
        /*
         * A closed mapping is a PIN this person USED to hold on that terminal, kept so
         * their old punches still resolve to them. Pushing to it would write their name
         * over whoever holds that slot today.
         */
        .filter((m) => m.is_live !== false)
        .map((m) => ({
            id: m.device_id,
            name: m.device_name ?? `Device ${m.device_id}`,
            held: m.device_held_name,
            pin: m.device_user_id,
        }));
}

/** Every terminal this app can write a user to. */
export function writableTargets(devices) {
    return devices
        .filter((d) => d.protocol === 'binary' && d.is_active)
        .map((d) => ({ id: d.id, name: d.name, held: null, pin: null }));
}
