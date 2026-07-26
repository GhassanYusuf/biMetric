import { useCallback, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { Badge, Button, Card, EmptyState, InlineAlert, SelectField, Skeleton, Spinner } from '../../shared/ui';
import FingerPicker, { slotName } from './FingerPicker';
import { useEscapeKey } from '../../shared/useEscapeKey';
import { useToast } from '../../shared/toast';

/*
| Live enrollment against a physical terminal.
|
| The hand diagram opens from the STORED enrollment on the employee record, so
| the dialog appears instantly with no hardware round trip. After a successful
| capture it re-reads the device once — the data genuinely just changed, and that
| read is persisted so the roster follows.
|
| Both capture actions beep the device the moment the trigger lands
| (ZktecoConnection sends CMD_TESTVOICE before capturing), so whoever is standing
| at the terminal hears that it is waiting for them. The request then blocks
| until the device captures or times out: ~30s for a finger, ~20s for a card.
*/
export default function EnrollModal({ employee, devices, onClose, onEnrolled }) {
    // Enrollment and enrollment reads are Binary-only; CSL HTTP cannot capture.
    const eligible = devices.filter((d) => d.protocol === 'binary' && d.is_active);

    const [deviceId, setDeviceId] = useState(eligible[0]?.id ?? '');
    const [slot, setSlot] = useState(0);
    const toast = useToast();
    const [busy, setBusy] = useState(null); // 'fingerprint' | 'card' | 'push'
    /*
     * Seeded from what is already stored; replaced by a real device read after a
     * capture, a push, or an explicit refresh.
     *
     * `on_device` is null — unknown — rather than a guess. Since the dialog no
     * longer reads the terminal on open, the only honest answers are "a read told
     * us" or "we don't know". A never-synced employee is flagged separately,
     * because that we do know.
     */
    const [live, setLive] = useState({
        on_device: null,
        fingerprint_slots: employee.fingerprint_slots,
        fingerprint_count: employee.fingerprint_count,
        card_number: employee.card_number,
        card_count: employee.card_number ? 1 : 0,
        stored: true,
    });
    const [reading, setReading] = useState(false);


    // We have never seen this person on any terminal, so a capture will fail
    // until they are pushed. That is knowable without touching hardware.
    const neverSynced = employee.enrollment_synced_at == null;

    const device = eligible.find((d) => String(d.id) === String(deviceId));

    // A capture blocks for ~30s; don't let Escape abandon the dialog mid-write.
    useEscapeKey(onClose, !busy);

    const readEnrollment = useCallback(async () => {
        if (!deviceId) return;
        setReading(true);
        try {
            const res = await api.get(`/employees/${employee.id}/devices/${deviceId}/enrollment`);
            setLive(res.data);
        } catch (err) {
            // Keep whatever we were already showing; a failed refresh should not
            // blank out the stored enrollment.
            toast.error(err instanceof ApiError ? err.message : 'Could not read enrollment from the device.');
        } finally {
            setReading(false);
        }
    }, [employee.id, deviceId]);

    /**
     * Writes the employee onto the terminal, which is the precondition for any
     * capture: the device attaches a template to an existing user record, so
     * enrolling a fingerprint for somebody it has never heard of is rejected.
     *
     * Safe to run on someone already there — it reuses their existing uid and
     * preserves their card and privilege, so it doubles as "update their name on
     * the terminal".
     */
    async function pushToDevice() {
        if (!device) return;
        setBusy('push');
        try {
            const res = await api.post(`/employees/${employee.id}/devices/${device.id}/push`);
            toast.success(res.message);
            onEnrolled?.();          // the roster's enrollment state changed
            await readEnrollment();  // and now we can say for sure they are on it
        } catch (err) {
            toast.error(err instanceof ApiError
                ? err.message
                : `Could not reach ${device.name}. Check it is powered on and on the network.`);
        } finally {
            setBusy(null);
        }
    }

    async function trigger(kind) {
        if (!device) return;
        setBusy(kind);
        try {
            const path = `/employees/${employee.id}/devices/${device.id}/${
                kind === 'fingerprint' ? 'enroll-fingerprint' : 'enroll-card'
            }`;
            const body = kind === 'fingerprint' ? { finger_index: Number(slot) } : undefined;

            const res = await api.post(path, body);
            toast.success(res.message);
            onEnrolled?.();
            await readEnrollment(); // re-read so the hand reflects the new template
        } catch (err) {
            toast.error(err instanceof ApiError
                ? err.message
                : `Could not reach ${device.name}. Check it is powered on and on the network.`);
        } finally {
            setBusy(null);
        }
    }

    const slotTaken = live?.fingerprint_slots?.includes(Number(slot)) ?? false;

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-slate-900/40 p-0 sm:items-center sm:p-4"
            onClick={busy ? undefined : onClose}
            role="presentation"
        >
            <Card className="w-full max-w-lg rounded-b-none sm:rounded-2xl" onClick={(e) => e.stopPropagation()}>
                <div className="p-5">
                    <h2 className="text-lg font-semibold tracking-[-0.01em] text-slate-900">Enroll on device</h2>
                    <p className="mt-0.5 text-sm text-slate-500">
                        {employee.name} · <span className="font-mono text-xs">{employee.employee_code}</span>
                    </p>

                    {eligible.length === 0 ? (
                        <div className="mt-4">
                            <EmptyState
                                title="No terminal available"
                                hint="Live enrollment needs an active device on the Binary/TCP-IP protocol. CSL HTTP terminals cannot capture remotely."
                            />
                        </div>
                    ) : (
                        <>
                            <div className="mt-4">
                                <SelectField
                                    label="Terminal"
                                    value={deviceId}
                                    onChange={(e) => setDeviceId(e.target.value)}
                                    disabled={!!busy}
                                >
                                    {eligible.map((d) => (
                                        <option key={d.id} value={d.id}>{d.name} — {d.ip_address}</option>
                                    ))}
                                </SelectField>
                            </div>

                            {/* What the device currently holds for this person. */}
                            <div className="mt-3 flex flex-wrap items-center gap-1.5">
                                {reading ? (
                                    <>
                                        <Skeleton className="h-5 w-28 rounded-full" />
                                        <Skeleton className="h-5 w-20 rounded-full" />
                                    </>
                                ) : live?.on_device === false ? (
                                    <Badge tone="amber">Not on this terminal</Badge>
                                ) : neverSynced && live?.on_device !== true ? (
                                    <Badge tone="amber">Not yet on a terminal</Badge>
                                ) : (
                                    <>
                                        <Badge tone={live?.fingerprint_count > 0 ? 'emerald' : 'slate'}>
                                            {live?.fingerprint_count == null
                                                ? 'Fingerprints unknown'
                                                : `${live.fingerprint_count} fingerprint${live.fingerprint_count === 1 ? '' : 's'}`}
                                        </Badge>
                                        <Badge tone={live?.card_count > 0 ? 'emerald' : 'slate'}>
                                            {live?.card_count > 0 ? `1 card · #${live.card_number}` : '0 cards'}
                                        </Badge>
                                        <span className="text-xs text-slate-500">
                                            {live?.stored ? 'stored values' : 'read from the device'}
                                        </span>
                                    </>
                                )}
                            </div>

                            {/*
                              * Step 1. The device attaches templates to an existing user
                              * record, so a capture for somebody it has never heard of is
                              * rejected. This panel leads when we know they are missing.
                              */}
                            {(live?.on_device === false || (neverSynced && live?.on_device !== true)) && (
                                <div className="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-4">
                                    <div className="text-sm font-semibold text-sky-900">
                                        {employee.name} will be added to the terminal automatically
                                    </div>
                                    <p className="mt-1 text-xs text-sky-800">
                                        {live?.on_device === false
                                            ? `${device?.name} has no record for this employee yet.`
                                            : 'This employee has never been sent to a terminal.'}{' '}
                                        A capture attaches to a terminal record, so scanning below creates that
                                        record first — no separate step needed. Add them now if you would rather
                                        do it before anyone walks over.
                                    </p>
                                    <Button
                                        variant="secondary"
                                        className="mt-3 w-full"
                                        disabled={!!busy}
                                        loading={busy === 'push'}
                                        onClick={pushToDevice}
                                    >
                                        {busy === 'push' ? 'Adding to terminal…' : `Add to ${device?.name ?? 'terminal'} now`}
                                    </Button>
                                </div>
                            )}

                            {busy && (
                                <div className="mt-4 flex items-center gap-3 rounded-xl bg-violet-50 px-4 py-3.5 text-sm text-violet-900" role="status">
                                    <Spinner className="h-5 w-5 shrink-0 text-violet-600" />
                                    <span>
                                        <strong className="font-semibold">The device just beeped.</strong>{' '}
                                        {busy === 'fingerprint'
                                            ? 'Place the finger on the sensor — it may ask for several presses.'
                                            : 'Tap the card on the reader.'}
                                    </span>
                                </div>
                            )}

                            <div className="mt-5 space-y-4">
                                <div className="rounded-xl border border-slate-200 p-4">
                                    <div className="flex items-baseline justify-between gap-3">
                                        <div className="text-sm font-semibold text-slate-800">Fingerprint</div>
                                        <div className="text-xs text-slate-500">
                                            Slot <span className="font-semibold text-slate-700">{slot}</span> · {slotName(Number(slot))}
                                        </div>
                                    </div>

                                    <div className="mt-3">
                                        <FingerPicker
                                            value={slot}
                                            onChange={setSlot}
                                            enrolled={live?.fingerprint_slots}
                                            disabled={!!busy}
                                        />
                                    </div>

                                    {slotTaken && (
                                        <p className="mt-2 text-xs text-amber-700">
                                            Slot {slot} already holds a template. Capturing again replaces it.
                                        </p>
                                    )}

                                    <Button
                                        className="mt-3 w-full"
                                        disabled={!!busy}
                                        loading={busy === 'fingerprint'}
                                        onClick={() => trigger('fingerprint')}
                                    >
                                        {busy === 'fingerprint' ? 'Waiting for finger…' : `Scan fingerprint into slot ${slot}`}
                                    </Button>
                                </div>

                                <div className="rounded-xl border border-slate-200 p-4">
                                    <div className="text-sm font-semibold text-slate-800">Card</div>
                                    <p className="mt-0.5 text-xs text-slate-500">
                                        The next card tapped is written onto this employee's record. A ZKTeco user
                                        record holds one card, so this replaces any existing one.
                                    </p>
                                    <Button
                                        variant="soft"
                                        className="mt-3 w-full"
                                        disabled={!!busy}
                                        loading={busy === 'card'}
                                        onClick={() => trigger('card')}
                                    >
                                        {busy === 'card' ? 'Waiting for card…' : 'Scan card'}
                                    </Button>
                                </div>
                            </div>
                        </>
                    )}

                    <div className="mt-5 flex flex-wrap items-center justify-end gap-2">
                        {eligible.length > 0 && live?.on_device === true && (
                            <Button
                                variant="ghost"
                                className="mr-auto text-xs"
                                disabled={!!busy}
                                loading={busy === 'push'}
                                onClick={pushToDevice}
                                title="Rewrites their name and code on the terminal, keeping card, privilege and fingerprints"
                            >
                                {busy === 'push' ? 'Updating…' : 'Update record on terminal'}
                            </Button>
                        )}
                        <Button variant="secondary" onClick={onClose} disabled={!!busy}>
                            {busy ? 'Please wait…' : 'Done'}
                        </Button>
                    </div>
                </div>
            </Card>
        </div>
    );
}
