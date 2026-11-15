import { useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { Button, Card, TextField } from '../../shared/ui';
import { useToast } from '../../shared/toast';
import { useEscapeKey } from '../../shared/useEscapeKey';
import { Option } from './DeleteEmployeeModal';

/*
| Delete the ticked employees (owner, 2026-11-15). The same three choices as DeleteEmployeeModal,
| asked once and applied to each person by POST /employees/bulk-offboard, which runs the single
| delete's own code per person (EmployeeController::offboardOne):
|
|   record      the records only — their fingerprints and cards keep working on the terminals
|   resign      off every terminal, records kept as resigned with fingerprints, cards, punches
|   everywhere  off every terminal, then each record is deleted once its terminals confirm
|
| Every terminal removal saves card and fingers first (rule 19). Punches are kept in every case.
| A choice that deletes records asks for the number of people to be typed, because a tick too many
| is easy on a long page and a deleted record does not come back from a button.
*/
export default function BulkDeleteEmployeesModal({ employees, onDone, onClose }) {
    const toast = useToast();
    const count = employees.length;
    const onTerminals = employees.filter((e) => (e.enrollments ?? []).some((m) => m.is_live !== false)).length;
    const anyOnTerminals = onTerminals > 0;
    const [scope, setScope] = useState(anyOnTerminals ? 'terminals' : 'record');
    const [keep, setKeep] = useState('yes');
    const [typed, setTyped] = useState('');
    const [busy, setBusy] = useState(false);

    useEscapeKey(onClose, !busy);

    const mode = !anyOnTerminals || scope === 'record' ? (anyOnTerminals ? 'record' : 'everywhere') : (keep === 'yes' ? 'resign' : 'everywhere');
    const deletesRecords = mode !== 'resign';
    const confirmed = !deletesRecords || typed.trim() === String(count);
    const punches = employees.reduce((n, e) => n + (e.attendance_logs_count ?? 0), 0).toLocaleString();
    const people = `${count.toLocaleString()} ${count === 1 ? 'employee' : 'employees'}`;
    const label = { record: `Delete ${people} from the system`, resign: `Remove access, keep ${people} as resigned`, everywhere: `Remove access and delete ${people}` }[mode];
    const names = employees.map((e) => [e.name, e.last_name].filter(Boolean).join(' '));

    async function go() {
        setBusy(true);
        try {
            const res = await api.post('/employees/bulk-offboard', { ids: employees.map((e) => e.id), mode });
            const d = res.data ?? {};
            // Something not done is said in red, even when the rest went through (rule 2).
            if ((d.refused?.length ?? 0) > 0 || (d.failed?.length ?? 0) > 0 || (d.skipped_devices?.length ?? 0) > 0) {
                toast.error(res.message);
            } else {
                toast.success(res.message);
            }
            onDone(d);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not delete the selected employees.');
            setBusy(false);
        }
    }

    return (
        <div
            className="fixed inset-0 z-[60] flex items-end justify-center bg-slate-900/40 sm:items-center sm:p-4"
            onClick={() => { if (!busy) onClose(); }}
            role="presentation"
        >
            <Card
                className="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-b-none sm:rounded-2xl"
                onClick={(e) => e.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-labelledby="bulk-delete-title"
            >
                <div className="space-y-4 p-5">
                    <div>
                        <h2 id="bulk-delete-title" className="text-lg font-semibold tracking-[-0.01em] text-slate-900">Delete {people}?</h2>
                        <p className="mt-0.5 text-sm text-slate-500">
                            {anyOnTerminals
                                ? `${onTerminals.toLocaleString()} of them ${onTerminals === 1 ? 'is' : 'are'} on a terminal.`
                                : 'None of them is on a terminal.'}
                            {` Their ${punches} punches are kept whatever you choose.`}
                        </p>
                        <p className="mt-2 max-h-24 overflow-y-auto rounded-xl bg-slate-50 px-3 py-2 text-xs leading-relaxed text-slate-700">
                            {names.join(', ')}
                        </p>
                    </div>

                    {anyOnTerminals && (
                        <fieldset className="space-y-2" disabled={busy}>
                            <legend className="mb-1.5 text-sm font-medium text-slate-700">What should be deleted?</legend>
                            <Option name="bulk-scope" value="terminals" checked={scope === 'terminals'} onChange={setScope} disabled={busy}
                                title="Delete from the system and remove their access from all terminals">
                                Each is taken off every terminal (their card and fingerprints are saved first).
                            </Option>
                            <Option name="bulk-scope" value="record" checked={scope === 'record'} onChange={setScope} disabled={busy}
                                title="Delete from the system only">
                                <span className="font-medium text-rose-700">Their fingerprints and cards keep working on the terminals.</span>
                            </Option>
                        </fieldset>
                    )}

                    {anyOnTerminals && scope === 'terminals' && (
                        <fieldset className="space-y-2" disabled={busy}>
                            <legend className="mb-1.5 text-sm font-medium text-slate-700">Keep their fingerprints and cards in the system?</legend>
                            <Option name="bulk-keep" value="yes" checked={keep === 'yes'} onChange={setKeep} disabled={busy}
                                title="Yes, keep them (mark as resigned)">
                                The records stay, inactive and resigned, with fingerprints, cards and punches. Anyone who comes back gets their fingerprints back when added to a terminal.
                            </Option>
                            <Option name="bulk-keep" value="no" checked={keep === 'no'} onChange={setKeep} disabled={busy}
                                title="No, delete the records">
                                Once every terminal confirms, each record is deleted with its fingerprints and card. Until then they stay, inactive.
                            </Option>
                        </fieldset>
                    )}

                    {deletesRecords && (
                        <TextField
                            label={`Type ${count} to confirm`}
                            inputMode="numeric"
                            autoComplete="off"
                            value={typed}
                            onChange={(e) => setTyped(e.target.value)}
                            disabled={busy}
                            hint="The number of employees selected."
                        />
                    )}

                    <div className="flex flex-wrap justify-end gap-2 pt-1">
                        <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
                        <Button variant={mode === 'resign' ? 'primary' : 'danger'} onClick={go} loading={busy} disabled={!confirmed}>{label}</Button>
                    </div>
                </div>
            </Card>
        </div>
    );
}
