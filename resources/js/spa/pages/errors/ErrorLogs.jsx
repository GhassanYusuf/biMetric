import { useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useMqtt } from '../../shared/useMqtt';
import { useToast } from '../../shared/toast';
import ErrorLogsDesktop from './ErrorLogsDesktop';
import ErrorLogsMobile from './ErrorLogsMobile';

/*
| Error logs (owner, 2026-11-09). What went wrong on the server, with the terminals and in
| people's browsers, read from the log files (ErrorLogReader). Repeats grouped by default, so
| one unreachable terminal is one row and not 288 a day. The last 7 days unless dated. Live
| (rule 18) on the `errors` stamp, the log files' newest change.
*/
function weekAgo() {
    const d = new Date();
    d.setDate(d.getDate() - 6);
    return d.toISOString().slice(0, 10);
}

const start = () => ({ source: '', level: 'error', from: weekAgo(), to: new Date().toISOString().slice(0, 10), search: '', group: true });

export default function ErrorLogs() {
    const { user: me } = useAuth();
    const toast = useToast();
    const deviceClass = useDeviceClass();
    const [filters, setFilters] = useState(start);
    const [query, setQuery] = useState(filters);
    const [page, setPage] = useState(1);
    const [rows, setRows] = useState([]);
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(true);
    const [loaded, setLoaded] = useState(false);
    const seq = useRef(0);

    useEffect(() => {
        const t = setTimeout(() => { setQuery(filters); setPage(1); }, filters.search === query.search ? 0 : 350);
        return () => clearTimeout(t);
    }, [filters]); // eslint-disable-line react-hooks/exhaustive-deps

    const load = useCallback(async () => {
        const mine = ++seq.current;
        setLoading(true);
        const q = new URLSearchParams({ page: String(page), per_page: '50', group: query.group ? '1' : '0' });
        ['source', 'level', 'from', 'to', 'search'].forEach((k) => { if (query[k]) q.set(k, query[k]); });
        try {
            const res = await api.get(`/error-logs?${q}`);
            if (mine !== seq.current) return;
            setRows(res.data ?? []);
            setMeta(res.meta ?? null);
        } catch (err) {
            if (mine === seq.current) toast.error(err instanceof ApiError ? err.message : 'Could not read the error logs.');
        } finally {
            if (mine === seq.current) { setLoading(false); setLoaded(true); }
        }
    }, [page, query, toast]);

    useEffect(() => { load(); }, [load]);
    useMqtt(me?.tenant ? [`tenant/${me.tenant.id}/module/errors/+`] : [], () => load());

    const props = {
        rows, meta, grouped: !!meta?.grouped, pagination: meta?.pagination ?? null, loading, loaded,
        filters, onFilter: setFilters, onClear: () => setFilters(start()), onPage: setPage,
    };

    return deviceClass === 'desktop' ? <ErrorLogsDesktop {...props} /> : <ErrorLogsMobile {...props} />;
}
