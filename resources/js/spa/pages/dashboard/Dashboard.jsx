import { useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { Button, Card, EmptyState } from '../../shared/ui';
import { useAuth } from '../../shared/auth';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useMqtt } from '../../shared/useMqtt';
import { useTopBarSlot } from '../../shared/topbar';
import RangeControl from '../../shared/RangeControl';
import DashboardMobile from './DashboardMobile';
import DashboardDesktop from './DashboardDesktop';
import DashboardSkeleton from './DashboardSkeleton';

// Owns the data; delegates rendering to the device-specific presentation file.
// Live: any punch on this tenant refreshes the KPIs with no page reload.
export default function Dashboard() {
    const deviceClass = useDeviceClass();
    const { user } = useAuth();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [days, setDays] = useState(7);
    // The first load failed and there is nothing to show (2026-11-10: a network drop
    // left `data` null and the page crashed with "e is null").
    const [failed, setFailed] = useState(null);
    // Distinguishes the first paint (skeleton) from a range change or live
    // refresh (keep the old chart, dim it) so the page never flashes empty.
    const refreshing = useRef(false);

    const load = useCallback(async (rangeDays) => {
        try {
            const res = await api.get(`/dashboard/summary?days=${rangeDays}`);
            setData(res.data);
            setFailed(null);
        } catch (err) {
            // Caught here, not left as an unhandled rejection. A failed LIVE refresh keeps
            // the numbers already on screen (rule 18: never flash the page); only a page
            // with nothing on it says so.
            setFailed(err instanceof ApiError ? err.message : 'The dashboard could not be loaded. Check the connection and try again.');
        } finally {
            setLoading(false);
            refreshing.current = false;
        }
    }, []);

    useEffect(() => {
        refreshing.current = data !== null;
        load(days);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [load, days]);

    // Live refresh on any attendance event for this tenant.
    const topics = user?.tenant ? [`tenant/${user.tenant.id}/module/attendance/+`] : [];
    useMqtt(topics, () => load(days));

    // The range control belongs to this page, so it is registered here and
    // unregisters on navigation rather than living permanently in the chrome.
    useTopBarSlot(() => <RangeControl value={days} onChange={setDays} />, [days]);

    if (loading && !data) return <DashboardSkeleton deviceClass={deviceClass} />;

    // Persistent state, not an event (rule 2): the page has nothing to show until a load works.
    if (!data) {
        return (
            <Card>
                <EmptyState
                    title="Couldn’t load the dashboard"
                    hint={failed ?? 'The dashboard could not be loaded.'}
                    action={<Button onClick={() => { setLoading(true); load(days); }}>Try again</Button>}
                />
            </Card>
        );
    }

    const Presentation = deviceClass === 'desktop' ? DashboardDesktop : DashboardMobile;

    return <Presentation data={data} days={days} onRefresh={() => load(days)} />;
}
