import { useState } from 'react';
import { useEscapeKey } from '../../shared/useEscapeKey';
import { ApiError } from '../../shared/api';
import { FOCUS } from '../../shared/theme';
import { Button, Card, InlineAlert, TextField } from '../../shared/ui';

/*
| "Wipe terminal" (owner, 2026-10-21; CLAUDE.md rule 22). Removes every user and every
| punch from one terminal.
|
| WHY A DIALOG AND NOT useConfirm: the wipe needs two answers a yes/no cannot carry —
| whether to save the punches (asked, never defaulted: the owner wants the question),
| and the terminal's name typed back, so the destructive action cannot land on the
| wrong row. The server checks both again; this is the affordance, not the authority.
|
| Rule 2: what the wipe will do is page STATE while the dialog is open, so it is
| content here; the OUTCOME is a toast, raised by the caller. A wrong name is a field
| error, never a toast.
*/
export default function WipeDeviceModal({ device, onSubmit, onClose }) {
    const [savePunches, setSavePunches] = useState(null); // null = not answered yet
    const [name, setName] = useState('');
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    // Rule 7: Escape closes — but not while the request is in flight.
    useEscapeKey(onClose, !submitting);

    const proven = device?.capabilities?.['device.wipe']?.confirmed === true;
    const ready = savePunches !== null && name.trim() === device.name;

    async function submit(e) {
        e.preventDefault();
        if (!ready) return;

        setSubmitting(true);
        try {
            await onSubmit({ save_punches: savePunches, confirm_name: name.trim() });
        } catch (err) {
            // 422s go on their fields; anything else the caller has already toasted.
            if (err instanceof ApiError && err.errors) setErrors(err.errors);
        } finally {
            setSubmitting(false);
        }
    }

    const choice = (value, title, detail) => (
        <label
            className={`flex cursor-pointer gap-3 rounded-xl border p-3 ${
                savePunches === value ? 'border-violet-300 bg-violet-50' : 'border-slate-200 hover:bg-slate-50'
            }`}
        >
            <input
                type="radio"
                name="save_punches"
                className={`mt-0.5 h-4 w-4 shrink-0 accent-violet-600 ${FOCUS}`}
                checked={savePunches === value}
                onChange={() => {
                    setSavePunches(value);
                    setErrors((x) => ({ ...x, save_punches: undefined }));
                }}
            />
            <span className="text-sm">
                <span className="block font-medium text-slate-900">{title}</span>
                <span className="block text-slate-600">{detail}</span>
            </span>
        </label>
    );

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-slate-900/40 p-0 sm:items-center sm:p-4"
            onClick={submitting ? undefined : onClose}
        >
            <Card className="w-full max-w-lg rounded-b-none sm:rounded-2xl">
                <form onClick={(e) => e.stopPropagation()} onSubmit={submit} className="p-5">
                    <h2 className="text-lg font-semibold text-slate-900">Wipe {device.name}?</h2>
                    <p className="mt-1 text-sm text-slate-600">
                        Removes every person from this terminal — with their fingerprints, cards and passwords — and
                        every punch on it. The terminal will hold nobody and no punches.
                    </p>

                    <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-700">
                        <li>
                            People <strong>linked to an employee</strong>: any fingerprint or card the system does not
                            have yet is saved to their account first. If that cannot be done, nothing is wiped.
                        </li>
                        <li>
                            People <strong>linked to no employee</strong>: removed, and their fingerprints and cards
                            are <strong>not kept</strong>.
                        </li>
                        <li>Employees and their attendance already in the system are not affected.</li>
                    </ul>

                    <fieldset className="mt-4">
                        <legend className="text-sm font-medium text-slate-900">Save this terminal's punches to the system first?</legend>
                        <div className="mt-2 grid gap-2">
                            {choice(true, 'Yes, save them first', 'Every punch is read and stored, then cleared. If any cannot be saved, nothing is wiped.')}
                            {choice(false, 'No, erase them', 'The punches are erased without being saved. Any not already in the system are lost for good.')}
                        </div>
                        {errors.save_punches?.[0] && <p className="mt-1 text-sm text-rose-600">{errors.save_punches[0]}</p>}
                    </fieldset>

                    <div className="mt-4">
                        <TextField
                            label={`Type ${device.name} to confirm`}
                            value={name}
                            autoComplete="off"
                            onChange={(e) => {
                                setName(e.target.value);
                                setErrors((x) => ({ ...x, confirm_name: undefined }));
                            }}
                            error={errors.confirm_name?.[0]}
                        />
                    </div>

                    {/* Rule 9: implemented, not yet proven on this model — offered with a warning. */}
                    {!proven && (
                        <div className="mt-4">
                            <InlineAlert tone="error">
                                This has not yet been run on this model of terminal. The terminal is read back
                                afterwards and must hold no users and no punches.
                            </InlineAlert>
                        </div>
                    )}

                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="danger" loading={submitting} disabled={!ready}>
                            Wipe terminal
                        </Button>
                    </div>
                </form>
            </Card>
        </div>
    );
}
