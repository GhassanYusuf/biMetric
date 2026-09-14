import { useCallback, useEffect, useMemo, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useConfirm } from '../../shared/confirm';
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
    const { user, can } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();

    const [runs, setRuns] = useState([]);
    const [devices, setDevices] = useState([]);
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState({ device_id: '', status: '' });
    const [clearing, setClearing] = useState(false);

    // The filter alone, shared by the listing and the clear so the two can never
    // disagree about which runs they are talking about.
    const filterQuery = useMemo(() => {
        const params = new URLSearchParams();
        if (filters.device_id) params.set('device_id', filters.device_id);
        if (filters.status) params.set('status', filters.status);
        return params.toString();
    }, [filters]);

    const query = useMemo(
        () => (filterQuery ? `per_page=30&${filterQuery}` : 'per_page=30'),
        [filterQuery],
    );

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

    /*
     * Clearing erases the run rows AND the raw payloads those runs archived — the
     * pre-parse byte store that exists so an undecodable punch can be reparsed after
     * a decoder fix. That is a real loss, so the dialog says it in those words rather
     * than "this cannot be undone", and it also says what SURVIVES: the punches
     * themselves, which is the thing an operator is actually afraid of losing.
     *
     * Scoped to whatever the filters currently select, because the button sits under
     * those filters and clearing more than the screen is showing would be a trap.
     */
    const onClear = useCallback(async () => {
        const deviceName = devices.find((d) => String(d.id) === String(filters.device_id))?.name;
        const scope = [
            deviceName ? `for ${deviceName}` : null,
            filters.status ? `with outcome “${filters.status}”` : null,
        ].filter(Boolean).join(' ');

        const total = meta?.pagination?.total ?? runs.length;

        const ok = await confirm({
            title: `Clear ${total.toLocaleString()} sync run${total === 1 ? '' : 's'}${scope ? ` ${scope}` : ''}?`,
            body: [
                'The raw payloads those runs archived are deleted with them, so undecodable punches from that period can no longer be reparsed after a decoder fix.',
                'Imported attendance is not affected — the punches stay in attendance history, and the next sync still picks up exactly where the last one left off.',
                'A sync that is still running is kept.',
            ],
            confirmLabel: 'Clear history',
            danger: true,
        });
        if (!ok) return;

        setClearing(true);
        try {
            const res = await api.delete(`/device-sync-runs?${filterQuery}`);
            toast.success(res.message);
            await load();
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not clear the sync history.');
        } finally {
            setClearing(false);
        }
    }, [confirm, devices, filterQuery, filters, load, meta, runs.length, toast]);

    const props = {
        runs,
        devices,
        meta,
        totals,
        filters,
        loading,
        clearing,
        canClear: can('devices.delete'),
        onFilter: (key, value) => setFilters((f) => ({ ...f, [key]: value })),
        onRefresh: load,
        onClear,
    };

    const Presentation = deviceClass === 'desktop' ? SyncRunsDesktop : SyncRunsMobile;

    if (loading && runs.length === 0) return <SkeletonTable rows={6} cols={6} />;

    return <Presentation {...props} />;
}
