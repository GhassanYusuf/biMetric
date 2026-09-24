import { useCallback, useEffect, useMemo, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useConfirm } from '../../shared/confirm';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useMqtt } from '../../shared/useMqtt';
import { useToast } from '../../shared/toast';
import { SkeletonTable } from '../../shared/ui';
import EnrollmentSessionsDesktop from './EnrollmentSessionsDesktop';
import EnrollmentSessionsMobile from './EnrollmentSessionsMobile';

/*
| Enrollment sessions: every fingerprint and card capture ever attempted.
|
| A capture blocks on a human walking to a terminal, so it runs as a durable session
| rather than a request. That is what makes this screen necessary: when somebody says
| "I stood there and nothing happened", the session's event trail is the only evidence
| of whether the terminal ever beeped, whether a finger was placed, and what it scored.
|
| Progress arrives on tenant/{id}/module/enrollment/{sessionId}. Subscribing with a
| single-level wildcard covers sessions that do not exist yet when the page loads.
*/
export default function EnrollmentSessions() {
    const deviceClass = useDeviceClass();
    const { user, can } = useAuth();
    const confirm = useConfirm();
    const toast = useToast();

    const [sessions, setSessions] = useState([]);
    const [devices, setDevices] = useState([]);
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(true);
    const [filters, setFilters] = useState({ device_id: '', status: '', active: false });
    const [expanded, setExpanded] = useState(null);

    const query = useMemo(() => {
        const params = new URLSearchParams({ per_page: '30' });
        if (filters.device_id) params.set('device_id', filters.device_id);
        if (filters.status) params.set('status', filters.status);
        if (filters.active) params.set('active', '1');
        return params.toString();
    }, [filters]);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const [s, d] = await Promise.all([
                api.get(`/enrollment-sessions?${query}`),
                api.get('/devices'),
            ]);
            setSessions(s.data ?? []);
            setMeta(s.meta ?? null);
            setDevices(d.data ?? []);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not load enrollment sessions.');
        } finally {
            setLoading(false);
        }
    }, [query, toast]);

    useEffect(() => {
        load();
    }, [load]);

    /*
     * Live progress.
     *
     * The published payload is a NOTIFICATION, not the session: it carries
     * session_id, status, event and message, but not the accumulated event trail. So
     * the status is patched straight from the message — instant, no flicker while a
     * capture emits beeped → waiting → enrolled in quick succession — and the full
     * record is refetched for the one session so its trail stays complete.
     *
     * A session id we have never seen means a capture started elsewhere; only then is
     * the whole list reloaded, because the filters decide whether it belongs here.
     */
    const topics = user?.tenant ? [`tenant/${user.tenant.id}/module/enrollment/+`] : [];
    useMqtt(topics, (_topic, payload) => {
        const id = payload?.session_id;
        // null = the live poller saying "enrollment changed" (rule 18): re-fetch.
        if (!id) { if (payload === null) load(); return; }

        let known = false;
        setSessions((prev) => {
            known = prev.some((s) => s.id === id);
            if (!known) return prev;
            return prev.map((s) => (s.id === id
                ? { ...s, status: payload.status ?? s.status, message: payload.message ?? s.message }
                : s));
        });

        if (!known) {
            load();
            return;
        }

        // Pull the authoritative record (including `events`) for just this session.
        api.get(`/enrollment-sessions/${id}`)
            .then((res) => setSessions((prev) => prev.map((s) => (s.id === id ? res.data : s))))
            .catch(() => { /* the patched status above is already correct enough */ });
    });

    async function cancelSession(session) {
        const ok = await confirm({
            title: `Cancel this ${session.type === 'card' ? 'card' : 'fingerprint'} capture?`,
            body: [
                `${session.employee_name ?? 'The employee'} will no longer be able to complete it at ${session.device_name ?? 'the terminal'}.`,
                'The terminal is released immediately so it can accept normal punches again.',
            ],
            confirmLabel: 'Cancel capture',
            danger: true,
        });
        if (!ok) return;

        try {
            const res = await api.post(`/enrollment-sessions/${session.id}/cancel`);
            toast.success(res.message);
            setSessions((prev) => prev.map((s) => (s.id === session.id ? res.data : s)));
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not cancel that capture.');
        }
    }

    const activeCount = sessions.filter((s) => s.is_active).length;

    const props = {
        sessions,
        devices,
        meta,
        filters,
        loading,
        activeCount,
        expanded,
        canCancel: can('employees.update'),
        onToggleExpand: (id) => setExpanded((prev) => (prev === id ? null : id)),
        onFilter: (key, value) => setFilters((f) => ({ ...f, [key]: value })),
        onCancel: cancelSession,
        onRefresh: load,
    };

    const Presentation = deviceClass === 'desktop' ? EnrollmentSessionsDesktop : EnrollmentSessionsMobile;

    if (loading && sessions.length === 0) return <SkeletonTable rows={6} cols={6} />;

    return <Presentation {...props} />;
}
