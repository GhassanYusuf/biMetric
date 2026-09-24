import { useCallback, useEffect, useState } from 'react';
import { api } from './api';
import { useMqtt } from './useMqtt';
import { useAuth } from './auth';

// The unread-notifications badge state, shared by the layout chrome. It seeds
// from the API and then increments live over MQTT when a notification arrives
// on this user's tenant-scoped topic — no polling, no refresh.
export function useUnreadCount() {
    const { user } = useAuth();
    const [count, setCount] = useState(0);

    const refresh = useCallback(async () => {
        try {
            const { data } = await api.get('/notifications/unread-count');
            setCount(data.unread_count ?? 0);
        } catch {
            /* keep last known count on transient failure */
        }
    }, []);

    useEffect(() => {
        refresh();
    }, [refresh]);

    const topics = user?.tenant ? [`tenant/${user.tenant.id}/user/${user.id}/notifications`] : [];
    // A pushed notification adds one; the live poller's null means "re-count" (rule 18).
    useMqtt(topics, (_topic, payload) => (payload === null ? refresh() : setCount((c) => c + 1)));

    return { count, refresh, setCount };
}
