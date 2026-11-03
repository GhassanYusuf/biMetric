import { useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { Button, Card } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';
import { useToast } from '../../shared/toast';
import { useEscapeKey } from '../../shared/useEscapeKey';

/*
| Delete, with the choice spelled out (owner, 2026-11-03): "if I click delete it asks me
| whether to delete it from the system, or from the system and remove access from the
| terminal, and whether to save their fingerprints".
|
| Three outcomes, one endpoint (POST /employees/{id}/offboard, EmployeeController::offboard):
|
|   record      the record only — their fingerprints and card keep working on the terminals
|   resign      off every terminal, record kept as resigned with fingerprints, card, punches
|   everywhere  off every terminal, then the record is deleted with its fingerprints and card
|
| Whatever is chosen, a terminal removal saves card and fingers first (rule 19), so a
| terminal that does not answer leaves the record, never half a person. Punches are kept in
| every case. Until 2026-11-03 Delete was the record only, behind a warning that was easy to
| miss (casebook DC-025: a deleted employee kept opening three doors).
*/
const Option = ({ name, value, checked, onChange, disabled, title, children }) => (
    <label className={`flex cursor-pointer items-start gap-3 rounded-xl p-3 ring-1 transition-colors ${checked ? 'bg-violet-50 ring-violet-300' : 'ring-slate-200 hover:bg-slate-50'}`}>
        <input
            type="radio"
            name={name}
            value={value}
            checked={checked}
            onChange={() => onChange(value)}
            disabled={disabled}
            className={`mt-0.5 h-4 w-4 border-slate-300 text-violet-600 ${FOCUS}`}
        />
        <span className="min-w-0 text-sm">
            <span className="block font-medium text-slate-900">{title}</span>
            <span className="mt-0.5 block text-slate-600">{children}</span>
        </span>
    </label>
);

export default function DeleteEmployeeModal({ employee, onDone, onClose }) {
    const toast = useToast();
    const terminals = (employee.enrollments ?? []).filter((m) => m.is_live !== false);
    const onTerminals = terminals.length > 0;
    const [scope, setScope] = useState(onTerminals ? 'terminals' : 'record');
    const [keep, setKeep] = useState('yes');
    const [busy, setBusy] = useState(false);

    useEscapeKey(onClose, !busy);

    const mode = !onTerminals || scope === 'record' ? (onTerminals ? 'record' : 'everywhere') : (keep === 'yes' ? 'resign' : 'everywhere');
    const names = terminals.map((m) => m.device_name ?? m.device?.name).filter(Boolean).join(', ');
    const punches = (employee.attendance_logs_count ?? 0).toLocaleString();
    const label = { record: 'Delete from the system', resign: 'Remove access, keep as resigned', everywhere: 'Remove access and delete' }[mode];

    async function go() {
        setBusy(true);
        try {
            const res = await api.post(`/employees/${employee.id}/offboard`, { mode });
            toast.success(res.message);
            onDone(res.data ?? {});
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not delete ${employee.name}.`);
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
                className="w-full max-w-lg rounded-b-none sm:rounded-2xl"
                onClick={(e) => e.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-labelledby="delete-employee-title"
            >
                <div className="space-y-4 p-5">
                    <div>
                        <h2 id="delete-employee-title" className="text-lg font-semibold tracking-[-0.01em] text-slate-900">Delete {employee.name}?</h2>
                        <p className="mt-0.5 text-sm text-slate-500">
                            {onTerminals ? `On ${terminals.length === 1 ? 'terminal' : 'terminals'}: ${names}.` : 'Not on any terminal.'}
                            {` Their ${punches} punches are kept whatever you choose.`}
                        </p>
                    </div>

                    {onTerminals ? (
                        <fieldset className="space-y-2" disabled={busy}>
                            <legend className="mb-1.5 text-sm font-medium text-slate-700">What should be deleted?</legend>
                            <Option name="scope" value="terminals" checked={scope === 'terminals'} onChange={setScope} disabled={busy}
                                title="Delete from the system and remove their access from all terminals">
                                They are taken off every terminal (their card and fingerprints are saved first).
                            </Option>
                            <Option name="scope" value="record" checked={scope === 'record'} onChange={setScope} disabled={busy}
                                title="Delete from the system only">
                                <span className="font-medium text-rose-700">Their fingerprints and card keep working on the terminals.</span>
                            </Option>
                        </fieldset>
                    ) : null}

                    {onTerminals && scope === 'terminals' && (
                        <fieldset className="space-y-2" disabled={busy}>
                            <legend className="mb-1.5 text-sm font-medium text-slate-700">Keep their fingerprints and card in the system?</legend>
                            <Option name="keep" value="yes" checked={keep === 'yes'} onChange={setKeep} disabled={busy}
                                title="Yes, keep them (mark as resigned)">
                                The record stays, inactive and resigned, with fingerprints, card and punches. If they come back, adding them to a terminal puts their fingerprints back.
                            </Option>
                            <Option name="keep" value="no" checked={keep === 'no'} onChange={setKeep} disabled={busy}
                                title="No, delete the record">
                                Once every terminal confirms, the record is deleted with its fingerprints and card. Until then they stay, inactive.
                            </Option>
                        </fieldset>
                    )}

                    <div className="flex justify-end gap-2 pt-1">
                        <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
                        <Button variant={mode === 'resign' ? 'primary' : 'danger'} onClick={go} loading={busy}>{label}</Button>
                    </div>
                </div>
            </Card>
        </div>
    );
}
