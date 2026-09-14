import { useCallback, useEffect, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useToast } from '../../shared/toast';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useMqtt } from '../../shared/useMqtt';
import { Spinner } from '../../shared/ui';
import AttendanceDesktop from './AttendanceDesktop';
import AttendanceMobile from './AttendanceMobile';
import PunchEmployeeModal from './PunchEmployeeModal';

const PER_PAGE = 50;

// Owns the punch log: server-side filtering (employee / device / date range),
// pagination via meta.pagination, and a live prepend when a new punch arrives
// over MQTT for this tenant.
export default function Attendance() {
    const deviceClass = useDeviceClass();
    const { user } = useAuth();
    const toast = useToast();
    const [rows, setRows] = useState([]);
    const [devices, setDevices] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [filters, setFilters] = useState({ device_id: '', from: '', to: '' });
    // The punch whose employee is open. Held as the whole row, not an id: the
    // dialog leads with the punch that was clicked, and a live refresh can
    // renumber the page under it.
    const [selected, setSelected] = useState(null);
    const [exporting, setExporting] = useState(false);

    const load = useCallback(async (pageNum, active) => {
        setLoading(true);
        try {
            const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(pageNum) });
            Object.entries(active).forEach(([k, v]) => {
                if (v) params.set(k, v);
            });
            const res = await api.get(`/attendance?${params}`);
            setRows(res.data);
            setPagination(res.meta?.pagination ?? null);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load(page, filters);
    }, [load, page, filters]);

    // The device filter needs the device list. Operators/admins can read it;
    // a plain employee cannot, so a 403 here is expected and non-fatal.
    useEffect(() => {
        api.get('/devices')
            .then((res) => setDevices(res.data))
            .catch(() => setDevices([]));
    }, []);

    // Live: a punch on this tenant lands at the top without a reload, but only
    // while viewing page 1 unfiltered — otherwise it would violate the filter.
    const topics = user?.tenant ? [`tenant/${user.tenant.id}/module/attendance/+`] : [];
    useMqtt(topics, () => {
        const unfiltered = !filters.device_id && !filters.from && !filters.to;
        if (page === 1 && unfiltered) load(1, filters);
    });

    /*
     * The PDF is produced from the SAME filters, server-side, over every matching
     * punch — not the 50 rows on screen. Exporting what happens to be paged in
     * would hand somebody a document that silently stops at row 50.
     */
    async function exportPdf() {
        setExporting(true);
        try {
            const params = new URLSearchParams();
            Object.entries(filters).forEach(([k, v]) => {
                if (v) params.set(k, v);
            });

            const name = await api.download(`/exports/attendance?${params}`, 'attendance.pdf');
            toast.success(`Saved ${name}.`);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not produce the PDF.');
        } finally {
            setExporting(false);
        }
    }

    function applyFilters(next) {
        setPage(1);
        setFilters(next);
    }

    const presentationProps = {
        rows,
        devices,
        filters,
        onFilter: applyFilters,
        loading,
        pagination,
        onPage: setPage,
        // Unmatched punches have no employee record to open, so the row stays
        // inert rather than opening a dialog with nothing in it.
        onSelect: (log) => log.employee_id && setSelected(log),
        onExport: exportPdf,
        exporting,
    };

    const Presentation = deviceClass === 'desktop' ? AttendanceDesktop : AttendanceMobile;

    if (loading && rows.length === 0 && !pagination) {
        return <div className="flex justify-center py-20 text-violet-600"><Spinner className="h-8 w-8" /></div>;
    }

    return (
        <>
            <Presentation {...presentationProps} />
            {selected && <PunchEmployeeModal log={selected} onClose={() => setSelected(null)} />}
        </>
    );
}
