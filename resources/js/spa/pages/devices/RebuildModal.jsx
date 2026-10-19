import { useEffect, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { useEscapeKey } from '../../shared/useEscapeKey';
import { Button, Card, InlineAlert, Skeleton } from '../../shared/ui';
import { FOCUS } from '../../shared/theme';

/*
| "Rebuild from system" (2026-10-11, casebook DC-017): make this terminal hold exactly
| what the system says — every linked person under their employee code, with the
| system's name, their card, every stored finger and their admin rights.
|
| The dialog IS the preview: it reads the terminal (read-only) and lists what would
| change, before anything is asked. Nothing is written until "Rebuild" is pressed, and
| then it is queued: punches downloaded, the whole terminal backed up, then one person
| at a time. People already right are not touched, so the doors keep working.
|
| Shared by desktop and mobile (rule 5). The outcome is a toast from the caller
| (rule 2); the preview is page state while the dialog is open, so it is content.
*/
export default function RebuildModal({ device, onStarted, onClose }) {
    const [plan, setPlan] = useState(null);
    const [error, setError] = useState(null);
    const [removeUnlinked, setRemoveUnlinked] = useState(false);
    const [starting, setStarting] = useState(false);

    useEscapeKey(onClose, !starting);

    useEffect(() => {
        let live = true;
        api.get(`/devices/${device.id}/rebuild-plan`)
            .then((res) => live && setPlan(res.data))
            .catch((err) => live && setError(err instanceof ApiError ? err.message : `Could not read ${device.name}.`));
        return () => { live = false; };
    }, [device.id, device.name]);

    async function start() {
        setStarting(true);
        try {
            const res = await api.post(`/devices/${device.id}/rebuild`, { remove_unlinked: removeUnlinked });
            onStarted(res.message);
        } catch (err) {
            setError(err instanceof ApiError ? err.message : `Could not start the rebuild of ${device.name}.`);
            setStarting(false);
        }
    }

    const changing = (plan?.people ?? []).filter((p) => p.changes.length > 0 && p.problems.length === 0);
    const attention = (plan?.people ?? []).filter((p) => p.problems.length > 0);
    const blocked = (plan?.blockers ?? []).length > 0;

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-slate-900/40 p-0 sm:items-center sm:p-4"
            onClick={starting ? undefined : onClose}
            role="presentation"
        >
            <Card className="w-full max-w-2xl rounded-b-none sm:rounded-2xl" onClick={(e) => e.stopPropagation()}>
                <div className="p-5">
                    <h2 className="text-lg font-semibold tracking-[-0.01em] text-slate-900">Rebuild {device.name} from the system</h2>
                    <p className="mt-1 text-sm text-slate-600">
                        Makes this terminal hold exactly what the system has: every linked person under their employee code,
                        with their name, card, all their stored fingerprints and their admin rights. First the punches are
                        downloaded and the whole terminal is backed up; then people are updated one at a time. People who are
                        already right are not touched, so the doors keep working.
                    </p>

                    {error && <div className="mt-4"><InlineAlert tone="error">{error}</InlineAlert></div>}

                    {!plan && !error && (
                        <div className="mt-4 space-y-2" aria-hidden="true">
                            {[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-6 w-full" />)}
                        </div>
                    )}

                    {plan && (
                        <div className="mt-4 space-y-4">
                            {blocked && (
                                <InlineAlert tone="error">{plan.blockers.join(' ')}</InlineAlert>
                            )}

                            <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                                {[
                                    ['To update', plan.counts.to_change],
                                    ['Already right', plan.counts.unchanged],
                                    ['Need attention', plan.counts.need_attention],
                                    ['Linked to nobody', plan.counts.unlinked],
                                ].map(([label, n]) => (
                                    <div key={label} className="rounded-xl bg-slate-50 px-3 py-2">
                                        <dt className="text-xs text-slate-500">{label}</dt>
                                        <dd className="text-lg font-semibold tabular-nums text-slate-900">{n}</dd>
                                    </div>
                                ))}
                            </dl>

                            {changing.length > 0 && (
                                <Section title="Will be updated">
                                    {changing.map((p) => (
                                        <li key={p.employee_id}>
                                            <span className="font-medium text-slate-900">{p.name}</span>
                                            <span className="text-slate-600"> — {p.changes.join('; ')}</span>
                                        </li>
                                    ))}
                                </Section>
                            )}

                            {attention.length > 0 && (
                                <Section title="Left as they are — need attention first">
                                    {attention.map((p) => (
                                        <li key={p.employee_id}>
                                            <span className="font-medium text-slate-900">{p.name}</span>
                                            <span className="text-amber-800"> — {p.problems.join('; ')}</span>
                                        </li>
                                    ))}
                                </Section>
                            )}

                            {plan.unlinked.length > 0 && (
                                <Section title="Records linked to no employee">
                                    {plan.unlinked.map((u) => (
                                        <li key={u.device_user_id}>
                                            <span className="font-medium text-slate-900">{u.name || '(no name)'}</span>
                                            <span className="text-slate-600">
                                                {' '}— user ID {u.device_user_id}
                                                {u.card ? `, card ${u.card}` : ''}
                                                {u.fingers == null ? '' : `, ${u.fingers} fingerprint${u.fingers === 1 ? '' : 's'}`}
                                            </span>
                                        </li>
                                    ))}
                                </Section>
                            )}

                            {plan.unlinked.length > 0 && (
                                <label className="flex items-start gap-2 text-sm text-slate-700">
                                    <input
                                        type="checkbox"
                                        className={`mt-0.5 h-4 w-4 rounded border-slate-300 ${FOCUS}`}
                                        checked={removeUnlinked}
                                        onChange={(e) => setRemoveUnlinked(e.target.checked)}
                                        disabled={starting}
                                    />
                                    <span>
                                        Also remove the {plan.unlinked.length} record{plan.unlinked.length === 1 ? '' : 's'} linked to no employee.
                                        Each is saved in the system first. Leave this off if any of them are real people not yet matched
                                        to an employee — removing them stops their card and finger working here.
                                    </span>
                                </label>
                            )}
                        </div>
                    )}

                    <div className="mt-5 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose} disabled={starting}>Cancel</Button>
                        <Button
                            type="button"
                            variant="danger"
                            loading={starting}
                            disabled={!plan || blocked || (changing.length === 0 && !removeUnlinked)}
                            onClick={start}
                        >
                            {plan && changing.length === 0 && !removeUnlinked ? 'Nothing to change' : 'Rebuild'}
                        </Button>
                    </div>
                </div>
            </Card>
        </div>
    );
}

function Section({ title, children }) {
    return (
        <div>
            <h3 className="text-sm font-medium text-slate-700">{title}</h3>
            <ul className="mt-1 max-h-48 space-y-1 overflow-y-auto text-sm">{children}</ul>
        </div>
    );
}
