import { useCallback, useEffect, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useToast } from '../../shared/toast';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useMqtt } from '../../shared/useMqtt';
import { useAllEmployeesExport } from '../../shared/useAllEmployeesExport';
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
    const { user, can } = useAuth();
    const toast = useToast();
    const [rows, setRows] = useState([]);
    const [devices, setDevices] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [filters, setFilters] = useState({ employee_id: '', device_id: '', department_id: '', search: '', from: '', to: '' });
    // What is typed in the search box. Debounced into `filters.search`, so the list
    // does not refetch on every keystroke.
    const [searchText, setSearchText] = useState('');
    // The Company › Department tree (2026-10-11), the same one the Employees page has.
    const [departments, setDepartments] = useState([]);
    const [unassignedCount, setUnassignedCount] = useState(0);
    const [departmentsLoaded, setDepartmentsLoaded] = useState(false);
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

    // Without permission there is simply no tree, as on the Employees page.
    const loadDepartments = useCallback(() => {
        api.get('/departments')
            .then((res) => {
                setDepartments(res.data ?? []);
                setUnassignedCount(res.meta?.unassigned_count ?? 0);
            })
            .catch(() => setDepartments([]))
            .finally(() => setDepartmentsLoaded(true));
    }, []);

    useEffect(() => { loadDepartments(); }, [loadDepartments]);

    // Live: a punch on this tenant lands at the top without a reload, but only
    // while viewing page 1 unfiltered — otherwise it would violate the filter.
    // Departments live under the employees module, so the tree follows that one.
    const topics = user?.tenant
        ? [`tenant/${user.tenant.id}/module/attendance/+`, `tenant/${user.tenant.id}/module/employees/+`]
        : [];
    useMqtt(topics, (topic) => {
        if (topic.includes('/employees')) {
            loadDepartments();
            return;
        }
        const unfiltered = !filters.employee_id && !filters.device_id && !filters.department_id && !filters.search && !filters.from && !filters.to;
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

    // "All employees" PDF / Excel (owner, 2026-10-19: "the export option must be in
    // /app/attendance"). The same hook as the Reports screen, with this page's range and
    // department as the dialog's starting point. Needs reports.view, as on the server.
    const allEmployees = useAllEmployeesExport({
        departments,
        initialRange: filters.from && filters.to ? { from: filters.from, to: filters.to } : null,
        initialDepartment: filters.department_id,
    });

    const exportItems = [
        { key: 'punches', label: 'These punches · PDF', description: 'Every punch matching the filters, not only this page', onSelect: exportPdf, loading: exporting },
        ...(can('reports.view') ? [{ separator: true, key: 'sep' }, ...allEmployees.items] : []),
    ];

    function applyFilters(next) {
        setPage(1);
        setFilters(next);
        // "Clear filters" empties the box too, not only the query behind it.
        setSearchText(next.search ?? '');
    }

    useEffect(() => {
        if (searchText === (filters.search ?? '')) return undefined;
        const t = setTimeout(() => {
            setPage(1);
            setFilters((f) => ({ ...f, search: searchText }));
        }, 300);
        return () => clearTimeout(t);
    }, [searchText, filters.search]);

    const presentationProps = {
        rows,
        devices,
        departments,
        unassignedCount,
        departmentsLoaded,
        filters,
        onFilter: applyFilters,
        search: searchText,
        onSearch: setSearchText,
        loading,
        pagination,
        onPage: setPage,
        // Unmatched punches have no employee record to open, so the row stays
        // inert rather than opening a dialog with nothing in it.
        onSelect: (log) => log.employee_id && setSelected(log),
        exportItems,
    };

    const Presentation = deviceClass === 'desktop' ? AttendanceDesktop : AttendanceMobile;

    if (loading && rows.length === 0 && !pagination) {
        return <div className="flex justify-center py-20 text-violet-600"><Spinner className="h-8 w-8" /></div>;
    }

    return (
        <>
            <Presentation {...presentationProps} />
            {selected && <PunchEmployeeModal log={selected} onClose={() => setSelected(null)} />}
            {allEmployees.dialog}
        </>
    );
}
