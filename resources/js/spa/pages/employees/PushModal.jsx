import { useState } from 'react';
import { can } from '../devices/parts';
import { Button, Card, InlineAlert } from '../../shared/ui';
import { useEscapeKey } from '../../shared/useEscapeKey';
import DeviceTargets, { enrolledTargets, writableTargets } from './DeviceTargets';

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
export default function PushModal({ employee, devices, onSubmit, onClose }) {
    const code = String(employee.employee_code ?? '');
    const codeIsPin = /^\d{1,9}$/.test(code);
    /*
     * Each terminal they are on, annotated with what a push will really do there
     * (2026-09-24): a terminal the app cannot write users to (a C3 panel) is shown but
     * blocked, and every writable one says whether the user ID moves to the Personnel
     * ID or stays — the server makes the same decision from the same capability.
     */
    const on = enrolledTargets(employee).map((t) => {
        const device = devices.find((d) => d.id === t.id);
        const moves = codeIsPin && t.pin != null && String(t.pin) !== code && can(device, 'user.renumber');
        // A C3 panel takes no names from here, but CAN move them to the new ID (2026-09-24).
        if (device?.protocol === 'c3' && device.is_active && moves) {
            return { ...t, note: `User ID ${t.pin} → ${code}, with their door access and fingerprints. Names on this panel are managed in ZKAccess.` };
        }
        if (!device || device.protocol !== 'binary' || !device.is_active || !can(device, 'user.write')) {
            return {
                ...t,
                disabledReason: device?.protocol === 'c3'
                    ? `C3 door panel — the app cannot write users here; change it in ZKAccess (user ID ${t.pin}).`
                    : 'This terminal cannot be written to from here.',
            };
        }
        if (codeIsPin && t.pin != null && String(t.pin) !== code) {
            return can(device, 'user.renumber')
                ? { ...t, note: `User ID ${t.pin} → ${code}, card and fingerprints kept.` }
                : { ...t, note: `Keeps user ID ${t.pin} — this terminal cannot change an ID.`, noteTone: 'warn' };
        }
        return t;
    });
    const onIds = on.filter((t) => !t.disabledReason).map((t) => t.id);
    // Writable terminals they are NOT already on. Kept separate rather than merged
    // into one list: adding somebody to a new site is a different act from
    // correcting the name on a site they already work at.
    const enrolledAll = on.map((t) => t.id);
    const writableOff = writableTargets(devices).filter((t) => !enrolledAll.includes(t.id));
    /*
     * Terminals they could NOT be added to from here are listed too, greyed with the
     * reason (2026-09-24): a panel that simply was not in the list read as a bug.
     * A C3 panel can have an existing user's ID moved, but adding a new person there
     * (user, door access, fingerprints) is not built.
     */
    const off = [
        ...writableOff,
        ...devices
            .filter((d) => d.is_active && !enrolledAll.includes(d.id) && !writableOff.some((t) => t.id === d.id))
            .map((d) => ({
                id: d.id,
                name: d.name,
                held: null,
                pin: null,
                disabledReason: d.protocol === 'c3'
                    ? 'C3 door panel — adding a new person here is not supported from the app yet; add them in ZKAccess.'
                    : 'This terminal cannot take new people from here.',
            })),
    ];

    // Pre-selected: the terminals they are on. Re-pushing where they already are is
    // the repair this dialog exists for; adding them somewhere new is not something
    // to tick on their behalf.
    const [selected, setSelected] = useState(onIds);
    const [busy, setBusy] = useState(false);

    useEscapeKey(onClose, !busy);

    async function submit(e) {
        e.preventDefault();
        setBusy(true);
        try {
            await onSubmit(selected);
        } finally {
            setBusy(false);
        }
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
                        the terminal supports it. Fingerprints and card are kept.
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
