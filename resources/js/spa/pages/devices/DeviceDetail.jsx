import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useConfirm } from '../../shared/confirm';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useMqtt } from '../../shared/useMqtt';
import { useToast } from '../../shared/toast';
import { Skeleton, SkeletonCards } from '../../shared/ui';
import DeviceDetailDesktop from './DeviceDetailDesktop';
import DeviceDetailMobile from './DeviceDetailMobile';

/*
| One terminal, everything known about it: identity read off the hardware, health
| history, sync position, capabilities, recent runs and the command queue.
|
| Every action here QUEUES a command and returns immediately. Nothing on this page
| blocks on hardware — a fingerprint capture waits up to 30 seconds for a human, and
| holding a request open for that is what the queue exists to avoid. So the pattern
| is always: fire, toast the queued message, then let MQTT or a refresh bring the
| outcome back.
*/
export default function DeviceDetail() {
    const { id } = useParams();
    const navigate = useNavigate();
    const deviceClass = useDeviceClass();
    const { user, can } = useAuth();
    const confirm = useConfirm();
    const toast = useToast();

    const [device, setDevice] = useState(null);
    const [runs, setRuns] = useState([]);
    const [commands, setCommands] = useState([]);
    const [history, setHistory] = useState([]);
    const [loading, setLoading] = useState(true);
    const [notFound, setNotFound] = useState(false);
    const [dispatching, setDispatching] = useState(null);

    const load = useCallback(async () => {
        try {
            /*
             * Four reads in parallel rather than in series. They are independent, and
             * a detail page that fetches sequentially spends four round trips before
             * showing anything.
             */
            const [d, r, c, h] = await Promise.all([
                api.get(`/devices/${id}`),
                api.get(`/device-sync-runs?device_id=${id}&per_page=10`),
                api.get(`/device-commands?device_id=${id}&per_page=15`),
                api.get(`/devices/${id}/status-history?per_page=12`),
            ]);
            setDevice(d.data);
            setRuns(r.data ?? []);
            setCommands(c.data ?? []);
            setHistory(h.data ?? []);
        } catch (err) {
            if (err instanceof ApiError && (err.status === 404 || err.status === 403)) {
                setNotFound(true);
            } else {
                toast.error(err instanceof ApiError ? err.message : 'Could not load this device.');
            }
        } finally {
            setLoading(false);
        }
    }, [id, toast]);

    useEffect(() => {
        load();
    }, [load]);

    /*
     * Health transitions are published tenant-wide on one topic, so any device
     * changing state wakes this page. Refetching only when the payload names THIS
     * device keeps a busy workspace from re-rendering the page constantly.
     */
    const topics = user?.tenant ? [`tenant/${user.tenant.id}/module/devices/status`] : [];
    useMqtt(topics, (_topic, payload) => {
        if (String(payload?.device_id ?? payload?.id ?? '') === String(id)) load();
    });

    /** Queues one command. 422 here is a capability refusal, which is information. */
    async function dispatch(command, { full = false } = {}) {
        setDispatching(command);
        try {
            const res = await api.post(`/devices/${id}/commands`, { command, full });
            toast.success(res.message);
            setCommands((prev) => [res.data, ...prev]);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : `Could not queue ${command}.`);
        } finally {
            setDispatching(null);
        }
    }

    async function fullResync() {
        const ok = await confirm({
            title: 'Re-read the entire attendance log?',
            body: [
                'This ignores the sync watermark and reads every record the terminal still holds, which can be tens of thousands.',
                'Existing punches are recognised and skipped, so nothing is duplicated — but it takes considerably longer than a normal sync.',
            ],
            confirmLabel: 'Read everything',
        });
        if (ok) dispatch('sync_attendance', { full: true });
    }

    async function retryCommand(command) {
        try {
            const res = await api.post(`/device-commands/${command.id}/retry`);
            toast.success(res.message);
            setCommands((prev) => prev.map((c) => (c.id === command.id ? res.data : c)));
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not re-queue that command.');
        }
    }

    async function cancelCommand(command) {
        try {
            const res = await api.post(`/device-commands/${command.id}/cancel`);
            toast.success(res.message);
            setCommands((prev) => prev.map((c) => (c.id === command.id ? res.data : c)));
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not cancel that command.');
        }
    }

    if (notFound) {
        return (
            <div className="space-y-4">
                <p className="text-sm text-slate-600">That device does not exist, or you cannot see it.</p>
                <button onClick={() => navigate('/devices')} className="text-sm font-semibold text-violet-700 hover:underline">
                    ← Back to devices
                </button>
            </div>
        );
    }

    if (loading && !device) {
        return (
            <div className="space-y-5" aria-busy="true" aria-label="Loading device">
                <Skeleton className="h-8 w-64" />
                <SkeletonCards count={4} />
                <Skeleton className="h-52 w-full rounded-2xl" />
            </div>
        );
    }

    const Presentation = deviceClass === 'desktop' ? DeviceDetailDesktop : DeviceDetailMobile;

    return (
        <Presentation
            device={device}
            runs={runs}
            commands={commands}
            history={history}
            dispatching={dispatching}
            canSync={can('devices.sync')}
            canUpdate={can('devices.update')}
            onRefresh={load}
            onDispatch={dispatch}
            onFullResync={fullResync}
            onRetryCommand={retryCommand}
            onCancelCommand={cancelCommand}
            onBack={() => navigate('/devices')}
        />
    );
}
