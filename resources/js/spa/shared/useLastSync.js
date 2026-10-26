import { useCallback, useEffect, useState } from 'react';
import { api } from './api';
import { useAuth } from './auth';
import { useMqtt } from './useMqtt';
import { formatDate } from './dates';

/*
| Powers the top bar's "Last sync" pill: the most recent successful device sync
| across the workspace, plus whether anything is currently in an error state.
|
| Derived from /devices rather than a bespoke endpoint — the data is already
| there and this keeps one source of truth for sync state. A user without
| devices.view simply gets `null` and the pill hides itself; that is a
| permission outcome, not an error.
*/
export function useLastSync() {
    const { user, can } = useAuth();
    const allowed = can('devices.view');
    const [state, setState] = useState(null);

    const refresh = useCallback(async () => {
        if (!allowed) {
            setState(null);
            return;
        }
        try {
            const { data } = await api.get('/devices');
            const devices = data ?? [];

            if (devices.length === 0) {
                setState({ empty: true, at: null, errored: 0, total: 0 });
                return;
            }

            const timestamps = devices
                .map((d) => d.last_synced_at)
                .filter(Boolean)
                .map((iso) => new Date(iso).getTime());

            setState({
                empty: false,
                at: timestamps.length ? new Date(Math.max(...timestamps)) : null,
                errored: devices.filter((d) => d.last_sync_status === 'error').length,
                total: devices.length,
            });
        } catch {
            setState(null); // never let the chrome break over a failed poll
        }
    }, [allowed]);

    useEffect(() => {
        refresh();
    }, [refresh]);

    // A punch means a device just talked to us — refresh the freshness readout.
    const topics = user?.tenant ? [`tenant/${user.tenant.id}/module/attendance/+`] : [];
    useMqtt(topics, () => refresh());

    return { ...(state ?? {}), available: state !== null, refresh };
}

/** "2m ago" / "3h ago" / a date. Kept short enough for a chrome pill. */
export function shortAgo(date) {
    if (!date) return 'never';
    const mins = Math.round((Date.now() - date.getTime()) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    const days = Math.round(hrs / 24);
    if (days < 7) return `${days}d ago`;
    return formatDate(date);
}
