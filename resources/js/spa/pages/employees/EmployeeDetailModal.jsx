import { useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { Badge, Button, Card, InlineAlert, SelectField, Skeleton } from '../../shared/ui';
import FingerPicker, { slotName } from './FingerPicker';
import { CardIcon, syncedAgo } from './enrollmentCells';
import { useEscapeKey } from '../../shared/useEscapeKey';
import { useConfirm } from '../../shared/confirm';
import { useToast } from '../../shared/toast';

/*
| What the terminal holds for one employee: which finger slots have a template,
| and which card is registered.
|
| READS FROM THE DATABASE, PER TERMINAL. Enrollment is stored per device in
| employee_device_maps by the roster read, so this opens instantly with no
| hardware round trip. Everything below the terminal picker describes THAT
| terminal only — two devices routinely hold different fingers for one person. Touching the
| terminal takes seconds, so it only happens when someone presses "Read from
| device" — and that read is persisted, so it refreshes the roster too.
|
| Read-only otherwise: "Enroll or replace" hands off to the capture dialog.
*/
export default function EmployeeDetailModal({ employee, devices, canEnroll, onClose, onEnroll, onRefreshed }) {
    const eligible = devices.filter((d) => d.protocol === 'binary' && d.is_active);
    const confirm = useConfirm();
    const toast = useToast();

    const [deviceId, setDeviceId] = useState(eligible[0]?.id ?? '');
    const [reading, setReading] = useState(false);
    const [deletingSlot, setDeletingSlot] = useState(null);
    const [busy, setBusy] = useState(null); // 'push' | 'remove' | 'card'
    // Only set once a device read has happened in this dialog; otherwise the
    // stored columns are the source of truth.
    const [live, setLive] = useState(null);

    const device = eligible.find((d) => String(d.id) === String(deviceId));

    // No dismissing mid-write, same rule as the backdrop.
    useEscapeKey(onClose, !busy && deletingSlot == null);

    async function readFromDevice() {
        if (!deviceId) return;
        setReading(true);
        try {
            const res = await api.get(`/employees/${employee.id}/devices/${deviceId}/enrollment`);
            setLive(res.data);
            // The read was persisted server-side; pull the roster back in step.
            onRefreshed?.();
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not read from the terminal.');
        } finally {
            setReading(false);
        }
    }

    /**
     * Adds the employee to the terminal, or rewrites their name/code if already
     * there. This is the precondition for any capture: the device attaches
     * templates to an existing user record.
     *
     * Safe to repeat — it reuses their uid and preserves card, privilege and
     * fingerprints.
     */
    async function pushToDevice() {
        if (!deviceId) return;
        setBusy('push');
        try {
            const res = await api.post(`/employees/${employee.id}/devices/${deviceId}/push`);
            toast.success(res.message);
            onRefreshed?.();
            await readFromDevice(); // now we can state on_device for certain
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not add them to the terminal.');
        } finally {
            setBusy(null);
        }
    }

    /**
     * Deletes the employee's whole record from the terminal — user, all
     * fingerprints, and card together. Their attendance history in this app is
     * untouched; only the device enrollment goes.
     */
    async function removeFromDevice() {
        if (!deviceId) return;

        const fingers = slots?.length ?? 0;
        const parts = [];
        if (fingers > 0) parts.push(`${fingers} fingerprint${fingers === 1 ? '' : 's'}`);
        if (cardNumber) parts.push(`card #${cardNumber}`);

        const ok = await confirm({
            title: `Remove ${employee.name} from ${device?.name ?? 'the terminal'}?`,
            body: [
                (parts.length
                    ? `This deletes their ${parts.join(' and ')} from the device.`
                    : 'This deletes their record from the device.')
                + ' They will have to be added and re-enrolled in person.',
                'Past attendance records in this app are not affected.',
            ],
            confirmLabel: 'Remove from terminal',
            danger: true,
        });
        if (!ok) return;

        setBusy('remove');
        try {
            const res = await api.post(`/employees/${employee.id}/devices/${deviceId}/remove`);
            // Gone from the device: drop to "nothing stored" rather than showing
            // enrollment that no longer exists.
            setLive({ on_device: false, fingerprint_slots: null, fingerprint_count: null, card_number: null, card_count: 0 });
            toast.success(res.message);
            onRefreshed?.();
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not remove them from the terminal.');
        } finally {
            setBusy(null);
        }
    }

    /**
     * Clears the card on the terminal. There is no "delete card" command — the
     * card is one field on the user record — so the record is rewritten with card
     * 0, keeping their fingerprints and privilege.
     */
    async function deleteCard() {
        if (!deviceId || busy) return;

        const ok = await confirm({
            title: `Remove card #${cardNumber} from ${employee.name}?`,
            body: [
                `This clears the card on ${device?.name ?? 'the terminal'}. Their fingerprints stay.`,
                'The card will no longer open the door or register punches for them.',
            ],
            confirmLabel: 'Remove card',
            danger: true,
        });
        if (!ok) return;

        setBusy('card');
        try {
            const res = await api.delete(`/employees/${employee.id}/devices/${deviceId}/card`);
            setLive({ ...(live ?? {}), card_number: null, card_count: 0, on_device: true, stored: false });
            toast.success(res.message);
            onRefreshed?.();
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not remove the card.');
        } finally {
            setBusy(null);
        }
    }

    /**
     * Deletes one finger from the terminal. Destructive and not undoable from
     * here — the person has to stand at the device and re-enroll — so it confirms
     * first and names exactly which slot is going.
     *
     * The API only reports success after re-reading the device and seeing the
     * slot gone, so the returned slots are confirmed, not assumed.
     */
    async function deleteSlot(slot) {
        if (!deviceId || busy) return;

        const where = device ? ` from ${device.name}` : '';
        const ok = await confirm({
            title: `Delete slot ${slot} (${slotName(slot)}) for ${employee.name}${where}?`,
            body: 'This removes the fingerprint from the terminal. They will have to re-enroll that finger in person.',
            confirmLabel: 'Delete fingerprint',
            danger: true,
        });
        if (!ok) return;

        setDeletingSlot(slot);
        try {
            const res = await api.delete(`/employees/${employee.id}/devices/${deviceId}/fingerprints/${slot}`);
            setLive({ ...(live ?? {}), ...res.data, on_device: true, stored: false });
            toast.success(res.message);
            onRefreshed?.();
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not delete that fingerprint.');
        } finally {
            setDeletingSlot(null);
        }
    }

    /*
     | Stored values are the default; a device read in this dialog overrides them.
     |
     | SCOPED TO THE SELECTED TERMINAL. This used to read the flat
     | employees.fingerprint_slots / card_number columns, which hold whatever the
     | LAST READ of ANY device wrote. With two terminals that showed one device's
     | fingers while the dialog's header, its delete buttons and its "remove from
     | terminal" action all pointed at another — so an operator could read "slots
     | 1 and 6" off IT Room and press delete against Good Wheels, which holds 6
     | and 7.
     |
     | No row for this device is NOT zero: it means we have never read that
     | terminal for this person, so it falls through to `neverRead` rather than
     | claiming they have nothing (rule 10).
     */
    const enrollments = Array.isArray(employee.enrollments) ? employee.enrollments : null;
    const mine = enrollments?.find((e) => String(e.device_id) === String(deviceId)) ?? null;
    const stored = enrollments
        ? {
            slots: mine?.fingerprint_slots ?? null,
            count: mine?.fingerprint_count ?? null,
            card: mine?.card_number ?? null,
            syncedAt: mine?.synced_at ?? null,
        }
        : {
            // Pre-`enrollments` fallback: the flat snapshot is all we have.
            slots: employee.fingerprint_slots,
            count: employee.fingerprint_count,
            card: employee.card_number,
            syncedAt: employee.enrollment_synced_at,
        };
    const slots = live ? live.fingerprint_slots : stored.slots;
    const count = live ? live.fingerprint_count : stored.count;
    const cardNumber = live ? live.card_number : stored.card;
    const neverRead = !live && stored.syncedAt == null;
    // Freshness is this terminal's read, not the roster's newest across all of them.
    const freshness = live ? 'just now' : syncedAgo([{ enrollment_synced_at: stored.syncedAt }]);

    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-slate-900/40 p-0 sm:items-center sm:p-4"
            // Don't let a stray backdrop click dismiss the dialog mid-write.
            onClick={busy || deletingSlot != null ? undefined : onClose}
            role="presentation"
        >
            <Card
                className="w-full max-w-lg rounded-b-none sm:rounded-2xl"
                onClick={(e) => e.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-label={`${employee.name} enrollment`}
            >
                <div className="p-5">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <h2 className="truncate text-lg font-semibold tracking-[-0.01em] text-slate-900">
                                {employee.name}
                            </h2>
                            <p className="mt-0.5 text-sm text-slate-500">
                                <span className="font-mono text-xs">{employee.employee_code}</span>
                                {employee.department ? ` · ${employee.department}` : ''}
                                {employee.attendance_logs_count != null
                                    ? ` · ${employee.attendance_logs_count.toLocaleString()} punches`
                                    : ''}
                            </p>
                        </div>
                        {!employee.is_active && <Badge tone="amber">Inactive</Badge>}
                    </div>

                    {live?.on_device === false && (
                        <div className="mt-4">
                            <InlineAlert tone="info">
                                {employee.name} is not enrolled on {device?.name}, so that terminal holds no
                                fingerprints or card for them.
                            </InlineAlert>
                        </div>
                    )}

                    {neverRead && (
                        <div className="mt-4">
                            <InlineAlert tone="info">
                                Nothing stored for {employee.name} on {device?.name ?? 'this terminal'} yet — read it
                                to fill this in.
                            </InlineAlert>
                        </div>
                    )}

                    {/*
                      * Every terminal at once. The panels below show only the
                      * SELECTED device, and switching between them one at a time
                      * to find out where somebody is missing is exactly the
                      * question this answers in one glance.
                      *
                      * Persistent page state, not an event, so it is content and
                      * not a toast (rule 2).
                      */}
                    {enrollments && enrollments.length > 1 && (
                        <div className="mt-4 rounded-xl border border-slate-200">
                            <div className="border-b border-slate-100 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                Enrollment by terminal
                            </div>
                            <ul className="divide-y divide-slate-100">
                                {enrollments.map((row) => {
                                    const selected = String(row.device_id) === String(deviceId);
                                    const unknown = row.fingerprint_slots == null;

                                    return (
                                        <li
                                            key={row.device_id}
                                            className={`flex items-center justify-between gap-3 px-4 py-2 text-sm ${
                                                selected ? 'bg-violet-50/60' : ''
                                            }`}
                                        >
                                            <span className="min-w-0 truncate font-medium text-slate-800">
                                                {row.device_name ?? `Device ${row.device_id}`}
                                                {selected && (
                                                    <span className="ml-2 text-xs font-normal text-violet-700">shown below</span>
                                                )}
                                            </span>
                                            <span className="flex shrink-0 items-center gap-3 text-xs">
                                                <span className={unknown ? 'text-amber-700' : 'text-slate-600'}>
                                                    {unknown ? 'fingerprints unknown' : `${row.fingerprint_slots.length} of 10 slots`}
                                                </span>
                                                <span className={row.card_number ? 'text-slate-600' : 'text-slate-500'}>
                                                    {row.card_number ? (
                                                        <span className="inline-flex items-center gap-1">
                                                            <CardIcon className="h-3.5 w-3.5 text-slate-400" />
                                                            <span className="font-mono tabular-nums">{row.card_number}</span>
                                                        </span>
                                                    ) : (
                                                        'no card'
                                                    )}
                                                </span>
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    )}

                    {/* Fingerprints */}
                    <div className="mt-4 rounded-xl border border-slate-200 p-4">
                        <div className="flex items-baseline justify-between gap-3">
                            <h3 className="text-sm font-semibold text-slate-800">Registered fingerprints</h3>
                            {reading ? (
                                <Skeleton className="h-4 w-16" />
                            ) : (
                                <span className="text-xs text-slate-500">
                                    {count == null ? 'unknown' : `${count} of 10 slots`}
                                </span>
                            )}
                        </div>

                        <div className="mt-3">
                            <FingerPicker readOnly enrolled={reading ? undefined : slots} />
                        </div>

                        {!reading && slots != null && (
                            <div className="mt-3 border-t border-slate-100 pt-3">
                                {slots.length === 0 ? (
                                    <p className="text-sm text-slate-500">No fingerprints registered.</p>
                                ) : (
                                    <ul className="space-y-1">
                                        {slots.map((slot) => (
                                            <li key={slot} className="flex items-center gap-2.5 rounded-lg py-1 text-sm">
                                                <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-[11px] font-bold text-white">
                                                    {slot}
                                                </span>
                                                <span className="font-medium text-slate-800">Slot {slot}</span>
                                                <span className="text-slate-500">{slotName(slot)}</span>
                                                {canEnroll && (
                                                    <Button
                                                        variant="ghost"
                                                        className="ml-auto min-h-[30px] px-2 text-xs text-rose-600 hover:bg-rose-50 hover:text-rose-700"
                                                        loading={deletingSlot === slot}
                                                        disabled={deletingSlot != null || !!busy}
                                                        onClick={() => deleteSlot(slot)}
                                                    >
                                                        {deletingSlot === slot ? 'Deleting…' : 'Delete'}
                                                    </Button>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <p className="mt-2.5 text-xs text-slate-500">
                                    Slot numbers come from the terminal. The finger name is the usual convention
                                    for that slot, not something the device reports.
                                </p>
                            </div>
                        )}
                    </div>

                    {/* Card */}
                    <div className="mt-4 rounded-xl border border-slate-200 p-4">
                        <div className="flex items-baseline justify-between gap-3">
                            <h3 className="text-sm font-semibold text-slate-800">Registered card</h3>
                            {reading ? <Skeleton className="h-4 w-14" /> : <span className="text-xs text-slate-500">{cardNumber ? 1 : 0} of 1</span>}
                        </div>

                        <div className="mt-3">
                            {reading ? (
                                <Skeleton className="h-11 w-44" />
                            ) : cardNumber ? (
                                <>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <div className="inline-flex items-center gap-2.5 rounded-xl bg-slate-50 px-3.5 py-2.5">
                                            <CardIcon className="h-5 w-5 text-slate-500" />
                                            <span className="font-mono text-base font-semibold tabular-nums text-slate-900">
                                                {cardNumber}
                                            </span>
                                        </div>
                                        {canEnroll && (
                                            <Button
                                                variant="ghost"
                                                className="min-h-[38px] px-2.5 text-xs text-rose-600 hover:bg-rose-50 hover:text-rose-700"
                                                loading={busy === 'card'}
                                                disabled={!!busy || reading}
                                                onClick={deleteCard}
                                            >
                                                {busy === 'card' ? 'Removing…' : 'Remove card'}
                                            </Button>
                                        )}
                                    </div>
                                    {cardNumber === 4294967295 && (
                                        <p className="mt-2 text-xs text-amber-700">
                                            4294967295 is 0xFFFFFFFF — all bits set. On many firmwares that is the
                                            "no card" marker rather than a real card number.
                                        </p>
                                    )}
                                </>
                            ) : (
                                <p className="text-sm text-slate-500">No card registered.</p>
                            )}
                        </div>

                        <p className="mt-2.5 text-xs text-slate-500">
                            A ZKTeco user record holds one card field, so an employee can have at most one.
                        </p>
                    </div>

                    {/* Terminal membership — everything here writes to hardware. */}
                    {eligible.length > 0 ? (
                        <div className="mt-4 rounded-xl border border-slate-200 p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <h3 className="text-sm font-semibold text-slate-800">On the terminal</h3>
                                    <p className="mt-0.5 text-xs text-slate-500">
                                        {live?.on_device === true
                                            ? `${employee.name} has a record on ${device?.name}.`
                                            : live?.on_device === false
                                                ? `${employee.name} has no record on ${device?.name}.`
                                                : neverRead
                                                    ? 'Never sent to a terminal — fingerprint and card capture will be refused until they are.'
                                                    : 'Read the device to confirm whether they have a record.'}
                                    </p>
                                </div>

                                {eligible.length > 1 && (
                                    <SelectField
                                        value={deviceId}
                                        onChange={(e) => setDeviceId(e.target.value)}
                                        aria-label="Terminal"
                                        className="w-auto py-1.5 text-xs"
                                    >
                                        {eligible.map((d) => (
                                            <option key={d.id} value={d.id}>{d.name}</option>
                                        ))}
                                    </SelectField>
                                )}
                            </div>

                            {canEnroll && (
                                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                    <Button
                                        variant={live?.on_device === true ? 'secondary' : 'primary'}
                                        disabled={!!busy || reading}
                                        loading={busy === 'push'}
                                        onClick={pushToDevice}
                                        title="Writes their name and code to the terminal, keeping card, privilege and fingerprints"
                                    >
                                        {busy === 'push'
                                            ? 'Writing…'
                                            : live?.on_device === true ? 'Update on terminal' : 'Add to terminal'}
                                    </Button>

                                    <Button
                                        variant="danger"
                                        disabled={!!busy || reading || live?.on_device === false}
                                        loading={busy === 'remove'}
                                        onClick={removeFromDevice}
                                        title="Deletes their record, fingerprints and card from the terminal"
                                    >
                                        {busy === 'remove' ? 'Removing…' : 'Remove from terminal'}
                                    </Button>
                                </div>
                            )}

                            <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3">
                                <span className="text-xs text-slate-500">
                                    {neverRead
                                        ? 'Never read from a terminal'
                                        : `Stored values${freshness ? `, read ${freshness}` : ''}`}
                                </span>
                                <Button
                                    variant="ghost"
                                    className="min-h-[32px] px-2.5 text-xs"
                                    disabled={!!busy}
                                    loading={reading}
                                    onClick={readFromDevice}
                                >
                                    {reading ? 'Reading terminal…' : 'Read from device'}
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <p className="mt-4 text-xs text-slate-500">
                            No active Binary/TCP-IP terminal, so device actions are unavailable.
                        </p>
                    )}

                    <div className="mt-5 flex justify-end gap-2">
                        {canEnroll && eligible.length > 0 && (
                            <Button variant="soft" onClick={() => onEnroll(employee)}>
                                Enroll or replace
                            </Button>
                        )}
                        <Button variant="secondary" onClick={onClose}>Close</Button>
                    </div>
                </div>
            </Card>
        </div>
    );
}
