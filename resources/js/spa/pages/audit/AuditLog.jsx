import { useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useMqtt } from '../../shared/useMqtt';
import { useToast } from '../../shared/toast';
import AuditLogDesktop from './AuditLogDesktop';
import AuditLogMobile from './AuditLogMobile';

/*
| Audit log (owner, 2026-11-09: "build two new features: audit logs and error logs"). Who did
| what, to whom and when, from audit_logs (AuditLogController). People's actions by default;
| the system's own bookkeeping (61,000 of 64,000 rows) only when asked. Read-only. Live
| (rule 18) on the `audit` stamp; a refresh keeps the filters, the page and what is open.
*/
const START = { who: 'people', action: '', from: '', to: '', search: '' };

export default function AuditLog() {
    const { user: me } = useAuth();
    const toast = useToast();
    const deviceClass = useDeviceClass();
    const [filters, setFilters] = useState(START);
    const [query, setQuery] = useState(START);
    const [page, setPage] = useState(1);
    const [rows, setRows] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [users, setUsers] = useState([]);
    const [actions, setActions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loaded, setLoaded] = useState(false);
    const seq = useRef(0);

    // Typing in Search waits a moment; every other filter applies at once.
    useEffect(() => {
        const t = setTimeout(() => { setQuery(filters); setPage(1); }, filters.search === query.search ? 0 : 350);
        return () => clearTimeout(t);
    }, [filters]); // eslint-disable-line react-hooks/exhaustive-deps

    const load = useCallback(async () => {
        const mine = ++seq.current;
        setLoading(true);
        const q = new URLSearchParams({ page: String(page), per_page: '30' });
        Object.entries(query).forEach(([k, v]) => { if (v) q.set(k, v); });
        try {
            const res = await api.get(`/audit-logs?${q}`);
            if (mine !== seq.current) return;
            setRows(res.data ?? []);
            setPagination(res.meta?.pagination ?? null);
            setUsers(res.meta?.users ?? []);
            setActions(res.meta?.actions ?? []);
        } catch (err) {
            if (mine === seq.current) toast.error(err instanceof ApiError ? err.message : 'Could not read the audit log.');
        } finally {
            if (mine === seq.current) { setLoading(false); setLoaded(true); }
        }
    }, [page, query, toast]);

    useEffect(() => { load(); }, [load]);
    useMqtt(me?.tenant ? [`tenant/${me.tenant.id}/module/audit/+`] : [], () => load());

    const props = {
        rows, pagination, users, actions, loading, loaded,
        filters, onFilter: setFilters, onClear: () => setFilters(START),
        onPage: setPage,
    };

    return deviceClass === 'desktop' ? <AuditLogDesktop {...props} /> : <AuditLogMobile {...props} />;
}
