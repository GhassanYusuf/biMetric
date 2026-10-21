import { useState } from 'react';
import { can, idMoveMethod } from '../devices/parts';
import { Button, Card, InlineAlert } from '../../shared/ui';
import { useEscapeKey } from '../../shared/useEscapeKey';
import { FOCUS } from '../../shared/theme';
import DeviceTargets, { enrolledTargets, writableTargets } from './DeviceTargets';
import { FixButton, useTerminalFix } from './TerminalFixes';
import { formatDeviceError } from '../devices/statusParts';

/*
 * Why a terminal is greyed, in the server's own words.
 *
 * DeviceResource already ships `write_refusal` — an actionable sentence from
 * Device::userWriteRefusal() — and it was being dropped for one generic string.
 * That hid the three refusals that actually happen, which have three different
 * fixes: read the roster and adopt it, the protocol cannot write users, or the
 * terminal is switched off. Abdul Haq's Glass Door push reported only "cannot be
 * written to from here" (2026-10-05), so a refused write read as a silent no-op.
 */
function refusal(device, fallback) {
    const why = device?.write_refusal;
    const sentence = why || fallback;
    return sentence.charAt(0).toUpperCase() + sentence.slice(1) + (sentence.endsWith('.') ? '' : '.');
}

/*
| Writes one employee onto terminals, without editing them first.
|
| The edit form can already push, but only as a side effect of saving — which is
| the wrong shape for the commonest reasons to push: a terminal was replaced, a
| push was skipped because the device was read-only at the time, or somebody needs
| adding to a site they have just started working at. None of those involve
| changing a single field, and making an operator re-save an unchanged record to
| trigger a device write is how people learn to distrust the save button.
|
| Both lists are offered together and marked, because "already on it" and "not on
| it yet" need different decisions from the person looking: the first is a repair,
| the second is an enrolment.
*/
export default function PushModal({ employee, devices, onSubmit, onRetry, onClose }) {
    const code = String(employee.employee_code ?? '');
    const codeIsPin = /^\d{1,9}$/.test(code);
    /*
     * Each terminal they are on, annotated with what a push will really do there
     * (2026-09-24). Decided by CAPABILITY, as the server decides it — the C3 door
     * panels take names since 2026-09-24 and are no longer special-cased here. A
     * terminal that cannot take a name but can move an ID still gets the move.
     */
    const on = enrolledTargets(employee).map((t) => {
        const device = devices.find((d) => d.id === t.id);
        const moves = codeIsPin && t.pin != null && String(t.pin) !== code && can(device, 'user.renumber');
        if (device?.is_active && !can(device, 'user.write') && moves) {
            return { ...t, note: `User ID ${t.pin} → ${code}, with their door access and fingerprints. This terminal takes no name changes.` };
        }
        if (!device || !device.is_active || !can(device, 'user.write')) {
            return { ...t, disabledReason: refusal(device, 'this terminal cannot be written to from here') };
        }
        const experimental = device.capabilities?.['user.write']?.level === 'implemented';
        if (codeIsPin && t.pin != null && String(t.pin) !== code) {
            // Each model moves an ID its own way (rule 21): in place, or — the F18 —
            // by re-adding with card, fingerprints and admin rights put back.
            const method = idMoveMethod(device);
            if (method === 'renumber') return { ...t, note: `User ID ${t.pin} → ${code}, card and fingerprints kept.` };
            if (method === 'readd') return { ...t, note: `User ID ${t.pin} → ${code} by re-adding them, with card, fingerprints and admin rights put back.` };
            return { ...t, note: `Keeps user ID ${t.pin} — this terminal cannot change an ID.`, noteTone: 'warn' };
        }
        return experimental ? { ...t, note: 'Name writes are new on this terminal — check the result.', noteTone: 'warn' } : t;
    });
    const onIds = on.filter((t) => !t.disabledReason).map((t) => t.id);
    // Writable terminals they are NOT already on. Kept separate rather than merged
    // into one list: adding somebody to a new site is a different act from
    // correcting the name on a site they already work at.
    const enrolledAll = on.map((t) => t.id);
    const writableOff = writableTargets(devices)
        .filter((t) => !enrolledAll.includes(t.id))
        .map((t) => {
            const d = devices.find((x) => x.id === t.id);
            // A C3 panel gives a new person the door access most people there have.
            return d?.protocol === 'c3' ? { ...t, note: 'Gets the door access most people on this panel have.' } : t;
        });
    // Terminals they could NOT be added to are listed too, greyed with the reason: a
    // terminal that simply was not in the list read as a bug.
    const off = [
        ...writableOff,
        ...devices
            .filter((d) => d.is_active && !enrolledAll.includes(d.id) && !writableOff.some((t) => t.id === d.id))
            .map((d) => ({
                id: d.id,
                name: d.name,
                held: null,
                pin: null,
                disabledReason: refusal(d, 'this terminal cannot take new people from here'),
            })),
    ];

    /*
     * Fingerprints ride the same push (2026-09-24): after the person is written, their
     * stored copies go to each ticked terminal that can take templates and lacks those
     * fingers — how somebody enrolled on the F18 gets into Glass Door in one step.
     * Add-only; a finger the terminal already holds is never replaced.
     */
    const storedFingers = (employee.stored_fingerprint_slots ?? []).length;
    const [withFingers, setWithFingers] = useState(storedFingers > 0);

    // Pre-selected: the terminals they are on. Re-pushing where they already are is
    // the repair this dialog exists for; adding them somewhere new is not something
    // to tick on their behalf.
    const [selected, setSelected] = useState(onIds);
    const [busy, setBusy] = useState(false);
    /*
     * Terminals the push could not finish but the operator can fix here (2026-10-11,
     * casebook DC-014): the dialog stays open on them, each with its button, instead
     * of closing on a toast that names a problem and offers nothing.
     */
    const [fixes, setFixes] = useState(null);
    const [fixing, setFixing] = useState(null);
    const fixTerminal = useTerminalFix();

    useEscapeKey(onClose, !busy && !fixing);

    async function submit(e) {
        e.preventDefault();
        setBusy(true);
        try {
            const result = await onSubmit(selected, { fingerprints: withFingers && storedFingers > 0 });
            const fixable = (result?.failed ?? []).filter((f) => f.action);
            if (fixable.length > 0) setFixes(fixable);
        } finally {
            setBusy(false);
        }
    }

    async function fix(failure, mode) {
        setFixing(failure.device_id);
        try {
            if (!(await fixTerminal(employee, failure, mode))) return;
            const again = onRetry ? await onRetry([failure.device_id]) : null;
            const still = (again?.failed ?? []).filter((f) => f.action);
            setFixes((list) => [...list.filter((f) => f.device_id !== failure.device_id), ...still]);
        } finally {
            setFixing(null);
        }
    }

    if (fixes) {
        return (
            <div className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-slate-900/40 p-0 sm:items-center sm:p-4" role="presentation" onClick={fixing ? undefined : onClose}>
                <Card className="w-full max-w-md rounded-b-none sm:rounded-2xl" onClick={(e) => e.stopPropagation()}>
                    <div className="p-5">
                        <h2 className="text-lg font-semibold tracking-[-0.01em] text-slate-900">
                            {fixes.length > 0 ? 'Needs your go-ahead' : `${employee.name} is done`}
                        </h2>
                        {fixes.length > 0 ? (
                            <ul className="mt-3 space-y-3">
                                {fixes.map((f) => (
                                    <li key={f.device_id} className="flex items-start justify-between gap-3 text-sm text-slate-700">
                                        <span className="min-w-0">
                                            <span className="block font-medium text-slate-900">{f.device_name}</span>
                                            <span className="block">{formatDeviceError(f.error)}</span>
                                        </span>
                                        <FixButton failure={f} onClick={(mode) => fix(f, mode)} busy={fixing === f.device_id} disabled={fixing != null} />
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="mt-2 text-sm text-slate-600">Every terminal that needed your go-ahead has been updated.</p>
                        )}
                        <div className="mt-5 flex justify-end">
                            <Button type="button" variant="secondary" onClick={onClose} disabled={fixing != null}>
                                {fixes.length > 0 ? 'Close' : 'Done'}
                            </Button>
                        </div>
                    </div>
                </Card>
            </div>
        );
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-slate-900/40 p-0 sm:items-center sm:p-4"
            onClick={busy ? undefined : onClose}
            role="presentation"
        >
            <Card className="w-full max-w-md rounded-b-none sm:rounded-2xl" onClick={(e) => e.stopPropagation()}>
                <form onSubmit={submit} className="p-5">
                    <h2 className="text-lg font-semibold tracking-[-0.01em] text-slate-900">
                        Push {employee.name} to terminals
                    </h2>
                    <p className="mt-1 text-sm text-slate-600">
                        Writes their name onto each ticked terminal and moves them to Personnel ID {code || '—'} wherever
                        the terminal supports it. Fingerprints and card already there are kept.
                    </p>

                    {on.length > 0 && (
                        <div className="mt-4">
                            <span className="text-sm font-medium text-slate-700">Already enrolled on</span>
                            <DeviceTargets
                                targets={on}
                                selected={selected}
                                onChange={setSelected}
                                compareName={employee.name}
                                emptyHint=""
                            />
                        </div>
                    )}

                    <div className="mt-4">
                        <span className="text-sm font-medium text-slate-700">
                            {on.length > 0 ? 'Add them to' : 'Terminals'}
                        </span>
                        <DeviceTargets
                            targets={off}
                            selected={selected}
                            onChange={setSelected}
                            compareName={employee.name}
                            emptyHint={
                                on.length > 0
                                    ? 'No other terminal can be written to.'
                                    : 'No terminal here can be written to. Read a device roster and adopt it first.'
                            }
                        />
                    </div>

                    {storedFingers > 0 && (
                        <label className="mt-4 flex items-start gap-2 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                className={`mt-0.5 h-4 w-4 rounded border-slate-300 ${FOCUS}`}
                                checked={withFingers}
                                onChange={(e) => setWithFingers(e.target.checked)}
                                disabled={busy}
                            />
                            <span>
                                Also copy their {storedFingers} stored fingerprint{storedFingers === 1 ? '' : 's'} to
                                terminals that are missing them. Fingers already on a terminal are never replaced.
                            </span>
                        </label>
                    )}

                    {/*
                      | Page STATE while the dialog is open, so content and not a toast
                      | (rule 2). It sets the expectation that this BLOCKS — the write
                      | is immediate by instruction, not queued, so the dialog can sit
                      | for a few seconds against a remote terminal.
                    */}
                    <div className="mt-4">
                        <InlineAlert tone="info">
                            This writes to each terminal now and waits for it, so a slow or unreachable one takes a
                            few seconds. Each is attempted separately — one failure never stops the rest — and the
                            result says exactly which terminals took it.
                        </InlineAlert>
                    </div>

                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose} disabled={busy}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={busy} disabled={selected.length === 0}>
                            {selected.length === 0
                                ? 'Pick a terminal'
                                : `Push to ${selected.length} terminal${selected.length === 1 ? '' : 's'}`}
                        </Button>
                    </div>
                </form>
            </Card>
        </div>
    );
}
