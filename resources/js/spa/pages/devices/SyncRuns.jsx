import { useCallback, useEffect, useMemo, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useMqtt } from '../../shared/useMqtt';
import { useToast } from '../../shared/toast';
import { SkeletonTable } from '../../shared/ui';
import SyncRunsDesktop from './SyncRunsDesktop';
import SyncRunsMobile from './SyncRunsMobile';

/*
| Attendance sync history across every terminal.
|
| This screen exists because "the sync looks wrong" is unanswerable without the per
| run record counts. A run that read 9,582 records and imported 0 is CORRECT
| incremental behaviour; a run that read 9,582 and could not decode 3,236 is a
| decoder bug that silently lost a third of the data for months. Both look identical
| if you only show "imported 0".
*/
export default function SyncRuns() {
    const deviceClass = useDeviceClass();
    const { user } = useAuth();
    const toast = useToast();

    const [runs, setRuns] = useState([]);
    const [devices, setDevices] = useState([]);
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState({ device_id: '', status: '' });

    const query = useMemo(() => {
        const params = new URLSearchParams({ per_page: '30' });
        if (filters.device_id) params.set('device_id', filters.device_id);
        if (filters.status) params.set('status', filters.status);
        return params.toString();
    }, [filters]);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const [r, d] = await Promise.all([
                api.get(`/device-sync-runs?${query}`),
                api.get('/devices'),
            ]);
            setRuns(r.data ?? []);
            setMeta(r.meta ?? null);
            setDevices(d.data ?? []);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not load sync history.');
        } finally {
            setLoading(false);
        }
    }, [query, toast]);

    useEffect(() => {
        load();
    }, [load]);

    /*
     * A punch landing means a sync just completed, so the history is stale. Reloading
     * on the tenant attendance topic keeps this page live without polling.
     */
    const topics = user?.tenant ? [`tenant/${user.tenant.id}/module/attendance/+`] : [];
    useMqtt(topics, () => load());

    /*
     * Totals across the loaded page, not across all history — labelled as such on the
     * screen. Claiming an all-time total from one page of results would be a number
     * that quietly means something else.
     */
    const totals = useMemo(() => runs.reduce((acc, r) => ({
        read: acc.read + (r.counts?.read ?? 0),
        imported: acc.imported + (r.counts?.imported ?? 0),
        duplicate: acc.duplicate + (r.counts?.duplicate ?? 0),
        unmatched: acc.unmatched + (r.counts?.unmatched ?? 0),
        undecodable: acc.undecodable + (r.counts?.undecodable ?? 0),
    }), { read: 0, imported: 0, duplicate: 0, unmatched: 0, undecodable: 0 }), [runs]);

    const props = {
        runs,
        devices,
        meta,
        totals,
        filters,
        loading,
        onFilter: (key, value) => setFilters((f) => ({ ...f, [key]: value })),
        onRefresh: load,
    };

    const Presentation = deviceClass === 'desktop' ? SyncRunsDesktop : SyncRunsMobile;

    if (loading && runs.length === 0) return <SkeletonTable rows={6} cols={6} />;

    return <Presentation {...props} />;
}
