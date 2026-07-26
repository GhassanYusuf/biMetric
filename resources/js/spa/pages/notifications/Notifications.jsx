import { useCallback, useEffect, useState } from 'react';
import { api } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useMqtt } from '../../shared/useMqtt';
import { Badge, Button, Card, EmptyState, PageHeader, Skeleton } from '../../shared/ui';

// A single list works well on both mobile and desktop, so no split is needed
// here. New notifications arrive live over MQTT and prepend with no reload.
const LEVEL_TONE = { urgent: 'rose', warning: 'amber', success: 'emerald', info: 'violet' };

export default function Notifications() {
    const { user } = useAuth();
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(true);

    const load = useCallback(async () => {
        try {
            const res = await api.get('/notifications?per_page=50');
            setItems(res.data);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    // Live: prepend incoming notifications for this user.
    const topics = user?.tenant ? [`tenant/${user.tenant.id}/user/${user.id}/notifications`] : [];
    useMqtt(topics, (_topic, payload) => {
        if (payload && payload.id) {
            setItems((prev) => [{ ...payload, is_read: false, read_at: null }, ...prev]);
        } else {
            load();
        }
    });

    async function markRead(n) {
        if (n.is_read) return;
        await api.post(`/notifications/${n.id}/read`);
        setItems((prev) => prev.map((x) => (x.id === n.id ? { ...x, is_read: true } : x)));
    }

    const unreadCount = items.filter((x) => !x.is_read).length;

    async function markAllRead() {
        await api.post('/notifications/read-all');
        setItems((prev) => prev.map((x) => ({ ...x, is_read: true })));
    }

    if (loading) {
        return (
            <div className="space-y-4" aria-busy="true" aria-label="Loading alerts">
                <Skeleton className="h-7 w-28" />
                <div className="space-y-2">
                    {Array.from({ length: 5 }, (_, i) => (
                        <Card key={i} className="p-4">
                            <Skeleton className="h-3.5 w-44" />
                            <Skeleton className="mt-2.5 h-3 w-full max-w-md" />
                            <Skeleton className="mt-2.5 h-2.5 w-32" />
                        </Card>
                    ))}
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <PageHeader
                title="Alerts"
                subtitle={unreadCount > 0 ? `${unreadCount} unread` : 'You are all caught up'}
            >
                {unreadCount > 0 && (
                    <Button variant="secondary" onClick={markAllRead}>Mark all read</Button>
                )}
            </PageHeader>

            {items.length === 0 ? (
                <Card><EmptyState title="No notifications" hint="Alerts and updates appear here live." /></Card>
            ) : (
                <div className="space-y-2">
                    {items.map((n) => (
                        <Card
                            key={n.id}
                            className={`cursor-pointer p-4 transition ${n.is_read ? 'opacity-70' : ''}`}
                            onClick={() => markRead(n)}
                        >
                            <div className="flex items-start gap-3">
                                {!n.is_read && <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-violet-500" />}
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="font-semibold text-slate-900">{n.title}</span>
                                        <Badge tone={LEVEL_TONE[n.level] || 'slate'}>{n.level}</Badge>
                                    </div>
                                    {n.body && <p className="mt-0.5 text-sm text-slate-600">{n.body}</p>}
                                    <p className="mt-1 text-xs text-slate-500">{new Date(n.created_at).toLocaleString()}</p>
                                </div>
                            </div>
                        </Card>
                    ))}
                </div>
            )}
        </div>
    );
}
