import { useCallback, useEffect, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useConfirm } from '../../shared/confirm';
import { useToast } from '../../shared/toast';
import { SkeletonTable } from '../../shared/ui';
import DevicesDesktop from './DevicesDesktop';
import DevicesMobile from './DevicesMobile';
import DeviceFormModal from './DeviceFormModal';

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
    async function runAction(device, action) {
        setBusy((b) => ({ ...b, [device.id]: action }));
        try {
            const res = await api.post(`/devices/${device.id}/${action}`);
            toast.success(res.message);
            // sync/import change server-side counts and sync status — refetch.
            if (action !== 'test-connection') await load();
            else setRows((prev) => prev.map((d) => (d.id === device.id ? { ...d, last_sync_error: null } : d)));
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not reach ${device.name}.`);
        } finally {
            setBusy((b) => ({ ...b, [device.id]: undefined }));
        }
    }

    const presentationProps = {
        rows,
        busy,
        canCreate: can('devices.create'),
        canUpdate: can('devices.update'),
        canDelete: can('devices.delete'),
        canSync: can('devices.sync'),
        onAdd: () => setEditing({}),
        onEdit: (device) => setEditing(device),
        onDelete: deleteDevice,
        onTest: (d) => runAction(d, 'test-connection'),
        onSync: (d) => runAction(d, 'sync-attendance'),
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
