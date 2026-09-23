import { useState } from 'react';
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
    const on = enrolledTargets(employee);
    const onIds = on.map((t) => t.id);
    // Writable terminals they are NOT already on. Kept separate rather than merged
    // into one list: adding somebody to a new site is a different act from
    // correcting the name on a site they already work at.
    const off = writableTargets(devices).filter((t) => !onIds.includes(t.id));

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
                        Writes their name and code onto each one. Their fingerprints, card and PIN there are
                        kept as they are.
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
