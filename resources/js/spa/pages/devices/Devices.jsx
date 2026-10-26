import { useCallback, useEffect, useRef, useState } from 'react';
import { useMqtt } from '../../shared/useMqtt';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useConfirm } from '../../shared/confirm';
import { useToast } from '../../shared/toast';
import { SkeletonTable } from '../../shared/ui';
import { useSyncProgress, describeProgress } from '../../shared/useSyncProgress';
import { useActionResult } from '../../shared/useActionResult';
import { waitForCommand } from '../../shared/deviceCommand';
import DevicesDesktop from './DevicesDesktop';
import DevicesMobile from './DevicesMobile';
import DeviceFormModal from './DeviceFormModal';
import SyncRangeModal from './SyncRangeModal';
import WipeDeviceModal from './WipeDeviceModal';
import { FULL_RESYNC_CONFIRM, adoptConfirm, clearPunchesConfirm, restartConfirm } from './parts';

// Actions that QUEUE a device read rather than returning a result inline. The POST
// comes back at once with a queued command; the read then runs on the worker and
// the sync-progress poller below drives the bar and detects completion. (Reads used
// to run inside the request, which timed the front proxy out — a 524 — on a slow
// device like Camelot.)
const SYNCING_KEYS = ['sync-attendance', 'full-resync', 'range-read'];

// Owns device data + every device operation. The three hardware actions
// (test / sync / import) talk to a physical ZKTeco unit over the network, so
// they can legitimately take seconds and fail — each reports its real outcome
// back to the UI rather than silently succeeding.
export default function Devices() {
    const deviceClass = useDeviceClass();
    const { can, user } = useAuth();
    const confirm = useConfirm();
    const toast = useToast();
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState(null); // device object, or {} for "new"
    const [rangeFor, setRangeFor] = useState(null); // device awaiting a date range
    const [wipeFor, setWipeFor] = useState(null); // device awaiting the wipe's answers
    const [busy, setBusy] = useState({}); // { [deviceId]: 'test' | 'sync' | 'import' }
    const { flash, resultOf, outcomeOf } = useActionResult();

    /*
     * The outcome of an action on the control that started it: the row's Actions
     * menu on desktop (keyed by device), the button itself on mobile (keyed by
     * device and action). Called once the outcome is KNOWN, not when it is queued.
     */
    const settle = useCallback((deviceId, action, result) => {
        flash(String(deviceId), result, result === 'success' ? 'Done' : 'Failed');
        flash(`${deviceId}:${action}`, result);
    }, [flash]);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await api.get('/devices');
            setRows(res.data);
        } finally {
            setLoading(false);
        }
    }, []);

    // LIVE (CLAUDE.md rule 18): refresh in place when devices change anywhere.
    useMqtt(user?.tenant ? [`tenant/${user.tenant.id}/module/devices/+`] : [], () => load());

    useEffect(() => {
        load();
    }, [load]);

    async function saveDevice(payload) {
        // Throws ApiError(422) so the modal can render field errors inline.
        if (editing?.id) {
            const res = await api.put(`/devices/${editing.id}`, payload);
            setRows((prev) => prev.map((d) => (d.id === editing.id ? res.data : d)));
        } else {
            const res = await api.post('/devices', payload);
            setRows((prev) => [res.data, ...prev]);
        }
        setEditing(null);
    }

    async function deleteDevice(device) {
        const ok = await confirm({
            title: `Remove ${device.name}?`,
            body: `Its ${(device.attendance_logs_count ?? 0).toLocaleString()} punch record(s) stay in attendance history.`,
            confirmLabel: 'Remove device',
            danger: true,
        });
        if (!ok) return;

        await api.delete(`/devices/${device.id}`);
        setRows((prev) => prev.filter((d) => d.id !== device.id));
    }

    /**
     * Runs one hardware action. `devices/{id}/{action}` answers 502 with a real
     * error message when the unit is unreachable, so failures are surfaced
     * verbatim instead of being flattened into "something went wrong".
     */
    async function runAction(device, action, { body, busyKey } = {}) {
        // busyKey lets two buttons hit the same endpoint and still spin
        // independently — a full re-read is 'sync-attendance' with a body.
        const key = busyKey ?? action;
        const queued = SYNCING_KEYS.includes(key);
        setBusy((b) => ({ ...b, [device.id]: key }));
        try {
            const res = await api.post(`/devices/${device.id}/${action}`, body);
            // The read's command id, so the completion effect knows its run when it sees it.
            if (queued) queuedCommand.current[device.id] = res.data?.id ?? null;
            // "Queued" is not an outcome; the completion effect below reports that.
            if (queued) toast.info(res.message);
            else toast.success(res.message);
            // A queued read returns immediately; the row STAYS busy and the
            // completion effect below clears it and refetches when the worker
            // finishes. Everything else resolves inline here.
            if (queued) return;
            settle(device.id, key, 'success');
            if (action !== 'test-connection') await load();
            else setRows((prev) => prev.map((d) => (d.id === device.id ? { ...d, last_sync_error: null } : d)));
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not reach ${device.name}.`);
            settle(device.id, key, 'error');
            if (queued) setBusy((b) => ({ ...b, [device.id]: undefined })); // failed to QUEUE — release now
        } finally {
            if (!queued) setBusy((b) => ({ ...b, [device.id]: undefined }));
        }
    }

    /**
     * Re-reads everything the terminal holds, ignoring the watermark.
     *
     * Confirmed first because it is slow, not because it is dangerous — the unique
     * punch index means it can add rows but never duplicate them.
     *
     * Goes to the SAME endpoint as an ordinary sync, with `full: true`. The devices
     * list syncs inline (device detail queues instead), so the operator gets the
     * result here rather than a command id.
     */
    async function fullResync(device) {
        if (!(await confirm(FULL_RESYNC_CONFIRM))) return;

        await runAction(device, 'sync-attendance', { body: { full: true }, busyKey: 'full-resync' });
    }

    /**
     * Imports one window of days and nothing else.
     *
     * The SAME endpoint again, with two dates instead of `full` — the device read is
     * identical either way, because this protocol cannot be asked for part of its
     * table (see AttendanceSyncService). What changes is which punches are written,
     * and that the device's sync position is left where it was, so this can be used
     * to repair one month without disturbing ordinary syncing.
     *
     * The dialog closes as soon as the read is QUEUED; its outcome arrives on the
     * same progress bar every other read uses.
     */
    /**
     * Unlocks writes on a terminal whose roster has been read and fully decided.
     *
     * The gate itself is not being weakened here — `finishAdoption()` still refuses
     * while anything is unclaimed, and this button is only offered when the count is
     * zero. What was missing was any way to TAKE the step from the UI: the endpoint
     * and a console command existed, and no screen called either, so every push was
     * refused with an instruction nobody could act on without a terminal.
     */
    async function adoptDevice(device) {
        if (!(await confirm(adoptConfirm(device)))) return;

        setBusy((b) => ({ ...b, [device.id]: 'adopt' }));
        try {
            const res = await api.post(`/devices/${device.id}/adopt`);
            toast.success(res.message);
            await load();
        } catch (err) {
            // A 422 here is the gate refusing, and its message names what is still
            // undecided — that is the useful answer, so it is shown verbatim.
            toast.error(err instanceof ApiError ? err.message : `Could not allow writes to ${device.name}.`);
        } finally {
            setBusy((b) => ({ ...b, [device.id]: undefined }));
        }
    }

    /**
     * Saves every punch on the terminal, then erases them from it (2026-10-18,
     * AttendanceClearService). Staff, cards and fingerprints stay. Queued — it reads
     * the whole log first — so the row stays busy until the command FINISHES, and
     * the outcome is the command's own message: either what was cleared, or why
     * nothing was.
     */
    /**
     * Removes every user and punch from the terminal (2026-10-21, DeviceWipeService,
     * CLAUDE.md rule 22). The dialog asks whether to save the punches and for the
     * terminal's name; a 422 is thrown back to it for its fields. Queued, so the row
     * stays busy until the command FINISHES and the toast is the command's own message:
     * what was removed, or why nothing was.
     */
    async function wipeDevice(device, answers) {
        const res = await api.post(`/devices/${device.id}/wipe`, answers); // 422 → dialog fields
        setWipeFor(null);
        toast.info(res.message);

        const key = 'wipe';
        setBusy((b) => ({ ...b, [device.id]: key }));
        try {
            const done = await waitForCommand(res.data.id);
            if (done === null) {
                toast.info(`Still working on ${device.name} — the result will appear in its command history.`);
            } else if (done.status === 'succeeded') {
                toast.success(done.message);
                settle(device.id, key, 'success');
            } else {
                toast.error(done.message ?? `${device.name} was not wiped.`);
                settle(device.id, key, 'error');
            }
            await load();
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not reach ${device.name}.`);
            settle(device.id, key, 'error');
        } finally {
            setBusy((b) => ({ ...b, [device.id]: undefined }));
        }
    }

    async function clearPunches(device) {
        if (!(await confirm(clearPunchesConfirm(device)))) return;

        const key = 'clear-attendance';
        setBusy((b) => ({ ...b, [device.id]: key }));
        try {
            const res = await api.post(`/devices/${device.id}/clear-attendance`);
            toast.info(res.message);

            const done = await waitForCommand(res.data.id);
            if (done === null) {
                toast.info(`Still working on ${device.name} — the result will appear in its command history.`);
            } else if (done.status === 'succeeded') {
                toast.success(done.message);
                settle(device.id, key, 'success');
            } else {
                toast.error(done.message ?? `Punches were not cleared from ${device.name}.`);
                settle(device.id, key, 'error');
            }
            await load();
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not reach ${device.name}.`);
            settle(device.id, key, 'error');
        } finally {
            setBusy((b) => ({ ...b, [device.id]: undefined }));
        }
    }

    /**
     * Reboots the terminal (2026-10-26, DeviceRestartService, casebook DC-034). Queued;
     * the row stays busy until the command finishes, which is when the terminal has gone
     * away and answers again. The toast is the command's own message: how long it took,
     * or why it is not back.
     */
    async function restartDevice(device) {
        if (!(await confirm(restartConfirm(device)))) return;

        const key = 'restart';
        setBusy((b) => ({ ...b, [device.id]: key }));
        try {
            const res = await api.post(`/devices/${device.id}/restart`);
            toast.info(res.message);

            const done = await waitForCommand(res.data.id);
            if (done === null) {
                toast.info(`Still restarting ${device.name} — the result will appear in its command history.`);
            } else if (done.status === 'succeeded') {
                toast.success(done.message);
                settle(device.id, key, 'success');
            } else {
                toast.error(done.message ?? `${device.name} did not restart.`);
                settle(device.id, key, 'error');
            }
            await load();
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not reach ${device.name}.`);
            settle(device.id, key, 'error');
        } finally {
            setBusy((b) => ({ ...b, [device.id]: undefined }));
        }
    }

    async function rangeSync(device, { from, to }) {
        await runAction(device, 'sync-attendance', { body: { from, to }, busyKey: 'range-read' });
        setRangeFor(null);
    }

    /*
     * Only one device syncs at a time from this screen (every action button is
     * disabled while any is busy), so a single poller covers the page. Polling is
     * scoped to the device actually reading — asking about idle terminals once a
     * second would be pure noise.
     *
     * Both sync buttons feed the same bar: a full re-read is still a read, and an
     * operator watching progress does not care which button started it.
     */
    const syncingId = Object.keys(busy).find((id) => SYNCING_KEYS.includes(busy[id]));
    const { progress, status, commandId, stalled } = useSyncProgress(syncingId ? Number(syncingId) : null, !!syncingId);

    /*
     * Finish a queued read.
     *
     * sync-progress reports the LATEST run for the device, which right after
     * queueing may still be a previous 'completed' one — so wait until this run is
     * actually seen 'running' before treating a terminal status as ours, or the bar
     * would clear the instant it appeared. Once the worker's run ends (or the
     * progress feed is lost), refetch the row and release it.
     *
     * OR the run carries the command this screen queued. A read of an empty terminal
     * finishes before the first poll, so 'running' is never seen; matching the command
     * is what releases it (2026-10-21: IT-Office after a punch clear spun forever).
     */
    const sawRunning = useRef(false);
    const queuedCommand = useRef({}); // device id → command id of the read it queued
    const handled = useRef(null);
    useEffect(() => {
        if (!syncingId) {
            sawRunning.current = false;
            handled.current = null;
            return;
        }
        if (status === 'running') sawRunning.current = true;

        // Terminal AttendanceSyncRun statuses — see the model. NOT 'completed'.
        const done = status === 'succeeded' || status === 'partial' || status === 'failed';
        const ours = commandId != null && commandId === queuedCommand.current[syncingId];
        const ended = (sawRunning.current || ours) && done;
        if (!ended && !stalled) return;
        if (handled.current === syncingId) return; // fire once per run

        handled.current = syncingId;
        const finishedId = syncingId;
        const action = busy[finishedId];
        if (status === 'succeeded' || status === 'partial') {
            toast.success('Attendance sync finished.');
            settle(finishedId, action, 'success');
        } else if (status === 'failed') {
            toast.error('Attendance sync failed — open the device for details.');
            settle(finishedId, action, 'error');
        } else if (stalled) toast.info('Lost contact with the progress feed — refreshing.');

        load().finally(() => setBusy((b) => ({ ...b, [finishedId]: undefined })));
    // `busy` is read for the finishing action's key only; the handled ref keeps a
    // busy change from firing this twice.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [status, commandId, stalled, syncingId, load, toast, settle]);

    const presentationProps = {
        rows,
        busy,
        resultOf,
        outcomeOf,
        syncingId: syncingId ? Number(syncingId) : null,
        syncProgress: progress,
        syncStalled: stalled,
        canCreate: can('devices.create'),
        canUpdate: can('devices.update'),
        canDelete: can('devices.delete'),
        canSync: can('devices.sync'),
        onAdd: () => setEditing({}),
        onEdit: (device) => setEditing(device),
        onDelete: deleteDevice,
        onTest: (d) => runAction(d, 'test-connection'),
        onSync: (d) => runAction(d, 'sync-attendance'),
        onFullResync: fullResync,
        onRangeSync: (d) => setRangeFor(d),
        onAdopt: adoptDevice,
        onImport: (d) => runAction(d, 'import-employees'),
        onClearPunches: clearPunches,
        onRestart: restartDevice,
        onWipe: (d) => setWipeFor(d),
    };

    const Presentation = deviceClass === 'desktop' ? DevicesDesktop : DevicesMobile;

    return (
        <>
            {loading && rows.length === 0 ? (
                <SkeletonTable rows={4} cols={6} />
            ) : (
                <Presentation {...presentationProps} />
            )}
            {editing && (
                <DeviceFormModal
                    device={editing.id ? editing : null}
                    onSubmit={saveDevice}
                    onClose={() => setEditing(null)}
                />
            )}
            {wipeFor && (
                <WipeDeviceModal
                    device={wipeFor}
                    onSubmit={async (answers) => {
                        try {
                            await wipeDevice(wipeFor, answers);
                        } catch (err) {
                            if (!(err instanceof ApiError && err.errors)) {
                                toast.error(err instanceof ApiError ? err.message : `Could not reach ${wipeFor.name}.`);
                            }
                            throw err;
                        }
                    }}
                    onClose={() => setWipeFor(null)}
                />
            )}
            {rangeFor && (
                <SyncRangeModal
                    device={rangeFor}
                    onSubmit={(range) => rangeSync(rangeFor, range)}
                    onClose={() => setRangeFor(null)}
                />
            )}
        </>
    );
}
