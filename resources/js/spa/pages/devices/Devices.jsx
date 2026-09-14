import { useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useConfirm } from '../../shared/confirm';
import { useToast } from '../../shared/toast';
import { SkeletonTable } from '../../shared/ui';
import { useSyncProgress, describeProgress } from '../../shared/useSyncProgress';
import DevicesDesktop from './DevicesDesktop';
import DevicesMobile from './DevicesMobile';
import DeviceFormModal from './DeviceFormModal';
import { FULL_RESYNC_CONFIRM } from './parts';

// Actions that QUEUE a device read rather than returning a result inline. The POST
// comes back at once with a queued command; the read then runs on the worker and
// the sync-progress poller below drives the bar and detects completion. (Reads used
// to run inside the request, which timed the front proxy out — a 524 — on a slow
// device like Camelot.)
const SYNCING_KEYS = ['sync-attendance', 'full-resync'];

// Owns device data + every device operation. The three hardware actions
// (test / sync / import) talk to a physical ZKTeco unit over the network, so
// they can legitimately take seconds and fail — each reports its real outcome
// back to the UI rather than silently succeeding.
export default function Devices() {
    const deviceClass = useDeviceClass();
    const { can } = useAuth();
    const confirm = useConfirm();
    const toast = useToast();
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState(null); // device object, or {} for "new"
    const [busy, setBusy] = useState({}); // { [deviceId]: 'test' | 'sync' | 'import' }

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await api.get('/devices');
            setRows(res.data);
        } finally {
            setLoading(false);
        }
    }, []);

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
            toast.success(res.message);
            // A queued read returns immediately; the row STAYS busy and the
            // completion effect below clears it and refetches when the worker
            // finishes. Everything else resolves inline here.
            if (queued) return;
            if (action !== 'test-connection') await load();
            else setRows((prev) => prev.map((d) => (d.id === device.id ? { ...d, last_sync_error: null } : d)));
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not reach ${device.name}.`);
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
    const { progress, status, stalled } = useSyncProgress(syncingId ? Number(syncingId) : null, !!syncingId);

    /*
     * Finish a queued read.
     *
     * sync-progress reports the LATEST run for the device, which right after
     * queueing may still be a previous 'completed' one — so wait until this run is
     * actually seen 'running' before treating a terminal status as ours, or the bar
     * would clear the instant it appeared. Once the worker's run ends (or the
     * progress feed is lost), refetch the row and release it.
     */
    const sawRunning = useRef(false);
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
        const ended = sawRunning.current && done;
        if (!ended && !stalled) return;
        if (handled.current === syncingId) return; // fire once per run

        handled.current = syncingId;
        const finishedId = syncingId;
        if (status === 'succeeded' || status === 'partial') toast.success('Attendance sync finished.');
        else if (status === 'failed') toast.error('Attendance sync failed — open the device for details.');
        else if (stalled) toast.info('Lost contact with the progress feed — refreshing.');

        load().finally(() => setBusy((b) => ({ ...b, [finishedId]: undefined })));
    }, [status, stalled, syncingId, load, toast]);

    const presentationProps = {
        rows,
        busy,
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
        onImport: (d) => runAction(d, 'import-employees'),
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
        </>
    );
}
