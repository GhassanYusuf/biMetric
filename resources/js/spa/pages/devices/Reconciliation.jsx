import { useCallback, useEffect, useMemo, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useConfirm } from '../../shared/confirm';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useToast } from '../../shared/toast';
import { SkeletonTable } from '../../shared/ui';
import ReconciliationDesktop from './ReconciliationDesktop';
import ReconciliationMobile from './ReconciliationMobile';

/*
| Where the system and the terminals disagree about who is enrolled.
|
| Deliberately READ-ONLY apart from three explicit operator actions. Auto-resolving
| would mean deciding whose version wins, and getting that wrong either deletes a real
| enrollment or restores a departed employee's access to the building. So this screen
| surfaces the discrepancies and an operator decides each one.
|
| The summary reads STORED reconciliation state — no device contact — so it is instant
| and safe to open repeatedly. Refreshing it against the hardware is a queued command.
*/
export default function Reconciliation() {
    const deviceClass = useDeviceClass();
    const { can } = useAuth();
    const confirm = useConfirm();
    const toast = useToast();

    const [summary, setSummary] = useState([]);
    const [maps, setMaps] = useState([]);
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(null);
    const [filters, setFilters] = useState({ device_id: '', status: '', needs_attention: true });

    const query = useMemo(() => {
        const params = new URLSearchParams({ per_page: '100' });
        if (filters.device_id) params.set('device_id', filters.device_id);
        if (filters.status) params.set('status', filters.status);
        // Default to only what disagrees — an in-sync row needs no decision.
        else if (filters.needs_attention) params.set('needs_attention', '1');
        return params.toString();
    }, [filters]);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const [s, m] = await Promise.all([
                api.get('/device-maps/summary'),
                api.get(`/device-maps?${query}`),
            ]);
            setSummary(s.data ?? []);
            setMaps(m.data ?? []);
            setMeta(m.meta ?? null);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not load the reconciliation report.');
        } finally {
            setLoading(false);
        }
    }, [query, toast]);

    useEffect(() => {
        load();
    }, [load]);

    /** Queues a fresh read of the device's user table. */
    async function reconcile(deviceId, deviceName) {
        setBusy(`reconcile-${deviceId}`);
        try {
            const res = await api.post(`/devices/${deviceId}/reconcile`);
            toast.success(res.message);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not queue a read of ${deviceName}.`);
        } finally {
            setBusy(null);
        }
    }

    /** Resolves missing_on_device: writes one employee onto the terminal. */
    async function pushEmployee(map) {
        const ok = await confirm({
            title: `Write ${map.employee_name ?? 'this employee'} to ${map.device_name}?`,
            body: 'They will be able to clock in once the terminal accepts the record. Any fingerprints they already had on that device are preserved.',
            confirmLabel: 'Push to device',
        });
        if (!ok) return;

        setBusy(`push-${map.id}`);
        try {
            const res = await api.post(`/devices/${map.device_id}/reconcile/employees/${map.employee_id}/push`);
            toast.success(res.message);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not queue that push.');
        } finally {
            setBusy(null);
        }
    }

    /** Resolves missing_in_system: imports device users nobody recognises. */
    async function importUnknown(deviceId, deviceName, count) {
        const ok = await confirm({
            title: `Import ${count} unknown user${count === 1 ? '' : 's'} from ${deviceName}?`,
            body: [
                'Each one becomes a new employee record, carrying whatever name and code the terminal holds.',
                'Only do this if those users are genuinely staff who were enrolled directly at the device — importing former employees would restore them to the payroll.',
            ],
            confirmLabel: 'Import as employees',
        });
        if (!ok) return;

        setBusy(`import-${deviceId}`);
        try {
            const res = await api.post(`/devices/${deviceId}/reconcile/import-unknown`);
            toast.success(res.message);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not queue that import.');
        } finally {
            setBusy(null);
        }
    }

    const props = {
        summary,
        maps,
        meta,
        filters,
        loading,
        busy,
        canSync: can('devices.sync'),
        canPush: can('employees.update'),
        onFilter: (key, value) => setFilters((f) => ({ ...f, [key]: value })),
        onReconcile: reconcile,
        onPush: pushEmployee,
        onImportUnknown: importUnknown,
        onRefresh: load,
    };

    const Presentation = deviceClass === 'desktop' ? ReconciliationDesktop : ReconciliationMobile;

    if (loading && summary.length === 0 && maps.length === 0) return <SkeletonTable rows={6} cols={5} />;

    return <Presentation {...props} />;
}
