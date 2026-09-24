import { useCallback, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { Badge, Button, Card, EmptyState, InlineAlert, SelectField, Skeleton, Spinner, TextField } from '../../shared/ui';
import FingerPicker, { slotName } from './FingerPicker';
import { useEscapeKey } from '../../shared/useEscapeKey';
import { useToast } from '../../shared/toast';
import { useConfirm } from '../../shared/confirm';

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
    // Terminals they are enrolled on come first, and the dialog opens on one of them.
    const onIds = new Set(
        (employee.enrollments ?? []).filter((m) => m.is_live !== false).map((m) => String(m.device_id)),
    );
    const eligible = devices
        .filter((d) => d.protocol === 'binary' && d.is_active)
        .sort((a, b) => Number(onIds.has(String(b.id))) - Number(onIds.has(String(a.id))));

    const [deviceId, setDeviceId] = useState(eligible[0]?.id ?? '');
    const [typedCard, setTypedCard] = useState('');
    const [cardError, setCardError] = useState(null);
    const [slot, setSlot] = useState(0);
    const toast = useToast();
    const confirm = useConfirm();
    const [busy, setBusy] = useState(null); // 'fingerprint' | 'card' | 'push'
    /*
     * Seeded from what is already stored; replaced by a real device read after a
     * capture, a push, or an explicit refresh.
     *
     * `on_device` is null — unknown — rather than a guess. Since the dialog no
     * longer reads the terminal on open, the only honest answers are "a read told
     * us" or "we don't know". A never-synced employee is flagged separately,
     * because that we do know.
     *
     * Seeded from THE SELECTED TERMINAL's own enrollment row, never from the flat
     * employee snapshot, which belongs to whichever terminal was read last (see
     * docs/enrollment-flow.md, "Enrollment belongs to ONE terminal"). No row means
     * that terminal was never read for them: `never_read`, not "undecodable".
     */
    const storedFor = (id) => {
        // The LIVE mapping: after an ID change the closed one (old user ID) is older and would match first.
        const row = (employee.enrollments ?? []).find((m) => m.is_live !== false && String(m.device_id) === String(id)) ?? null;
        return {
            on_device: null,
            fingerprint_slots: row?.fingerprint_slots ?? null,
            fingerprint_count: row?.fingerprint_count ?? null,
            card_number: row?.card_number ?? null,
            card_count: row?.card_number ? 1 : 0,
            stored: true,
            never_read: row == null || row.synced_at == null,
        };
    };
    const [live, setLive] = useState(() => storedFor(eligible[0]?.id));
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

    /**
     * A ZKTeco record holds ONE card, so a new card always removes the old one from
     * this terminal. Asked before the write, never after: once the terminal holds the
     * new number, the old card has already stopped opening the door.
     *
     * Only for a real card. The 0xFFFFFFFF marker and "never read" are not a card
     * anybody would miss, so asking about them would be noise.
     */
    async function okToReplaceCard(newNumber) {
        const current = live?.card_number;
        if (!current || current === 4294967295 || live?.never_read) return true;
        if (newNumber != null && String(current) === String(newNumber)) return true;

        return confirm({
            title: `Replace card #${current}?`,
            body: [
                `${employee.name} already has card #${current} on ${device?.name ?? 'this terminal'}.`,
                'A terminal record holds one card, so the new card replaces it. Card '
                    + `#${current} will stop working for ${employee.name} here.`,
            ],
            confirmLabel: 'Replace card',
            danger: true,
        });
    }

    async function trigger(kind) {
        if (!device) return;
        if (kind === 'card' && !(await okToReplaceCard(null))) return;
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

    /**
     * Writes a card NUMBER instead of waiting for a tap — for when the number is
     * printed on the card. The server reads the record back and fails the request if
     * the terminal did not keep it, so a success here is the device's word.
     */
    async function saveTypedCard(e) {
        e.preventDefault();
        if (!device) return;
        const digits = typedCard.trim();
        if (!/^\d{1,10}$/.test(digits)) {
            setCardError('Enter the card number as digits only, up to 10.');
            return;
        }
        setCardError(null);
        if (!(await okToReplaceCard(digits))) return;
        setBusy('typed-card');
        try {
            const res = await api.put(`/employees/${employee.id}/devices/${device.id}/card`, { card_number: digits });
            toast.success(res.message);
            setTypedCard('');
            onEnrolled?.();
            await readEnrollment();
        } catch (err) {
            if (err instanceof ApiError && err.errors?.card_number) {
                setCardError(err.errors.card_number[0]);
            } else {
                toast.error(err instanceof ApiError
                    ? err.message
                    : `Could not reach ${device.name}. Check it is powered on and on the network.`);
            }
        } finally {
            setBusy(null);
        }
    }

    /*
     * Scan card is offered where the terminal's capability says it is usable
     * (rule 9), with a warning while it is only `implemented`. On 2026-09-23 IT Room
     * mostly returned a non-card; the server now undoes those and reports a failed
     * scan, so a retry or typing the number is always safe.
     */
    const cardScan = device?.capabilities?.['card.enroll'];
    const canScanCard = cardScan?.usable === true;
    const scanUnproven = canScanCard && cardScan?.confirmed !== true;

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
                                    onChange={(e) => { setDeviceId(e.target.value); setLive(storedFor(e.target.value)); }}
                                    disabled={!!busy}
                                >
                                    {eligible.map((d) => (
                                        <option key={d.id} value={d.id}>{d.name} — {d.ip_address}{onIds.has(String(d.id)) ? ' · enrolled' : ''}</option>
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
                                                ? (live?.never_read ? 'Fingerprints never read here' : 'Fingerprints unknown')
                                                : `${live.fingerprint_count} fingerprint${live.fingerprint_count === 1 ? '' : 's'}`}
                                        </Badge>
                                        <Badge tone={live?.card_count > 0 ? 'emerald' : 'slate'}>
                                            {live?.card_number === 4294967295
                                                ? 'Probably no card (terminal reports 0xFFFFFFFF)'
                                                : live?.never_read ? 'Card never read here'
                                                : live?.card_count > 0 ? `1 card · #${live.card_number}` : '0 cards'}
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
                                            neverRead={!!live?.never_read}
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
                                        {canScanCard
                                            ? 'Type the number printed on the card, or scan it at the terminal.'
                                            : 'Type the number printed on the card.'}{' '}
                                        A ZKTeco user record holds one card, so this replaces any existing one.
                                    </p>
                                    <form onSubmit={saveTypedCard} className="mt-3 flex items-start gap-2" noValidate>
                                        <div className="min-w-0 flex-1">
                                            <TextField
                                                value={typedCard}
                                                onChange={(e) => { setTypedCard(e.target.value); setCardError(null); }}
                                                inputMode="numeric"
                                                autoComplete="off"
                                                placeholder="Card number, e.g. 2857525"
                                                aria-label="Card number"
                                                error={cardError}
                                                disabled={!!busy}
                                            />
                                        </div>
                                        <Button type="submit" disabled={!!busy || typedCard.trim() === ''} loading={busy === 'typed-card'}>
                                            Save card
                                        </Button>
                                    </form>
                                    {scanUnproven && (
                                        <p className="mt-2 text-xs text-amber-700">
                                            Scanning isn't reliable on {device?.name ?? 'this terminal'} yet. If the
                                            terminal doesn't send the card number, nothing is saved and you can tap
                                            again. Check the number shown against the card.
                                        </p>
                                    )}
                                    {canScanCard && (
                                        <Button
                                            variant="soft"
                                            className="mt-3 w-full"
                                            disabled={!!busy}
                                            loading={busy === 'card'}
                                            onClick={() => trigger('card')}
                                        >
                                            {busy === 'card' ? 'Waiting for card…' : 'Scan card'}
                                        </Button>
                                    )}
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
