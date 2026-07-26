import { useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../../shared/api';
import { useAuth } from '../../shared/auth';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useConfirm } from '../../shared/confirm';
import { useToast } from '../../shared/toast';
import { SkeletonTable } from '../../shared/ui';
import EmployeesDesktop from './EmployeesDesktop';
import EmployeesMobile from './EmployeesMobile';
import EmployeeFormModal from './EmployeeFormModal';
import EnrollModal from './EnrollModal';
import EmployeeDetailModal from './EmployeeDetailModal';

// Owns employee data + all mutations. Every action updates local state in place
// (no page reload) per the standards; the device-specific file only renders.
export default function Employees() {
    const deviceClass = useDeviceClass();
    const { can } = useAuth();
    const confirm = useConfirm();
    const toast = useToast();
    const [rows, setRows] = useState([]);
    const [devices, setDevices] = useState([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [formOpen, setFormOpen] = useState(false);
    const [enrolling, setEnrolling] = useState(null); // employee being enrolled
    const [viewing, setViewing] = useState(null); // employee whose enrollment is open
    const [readingDevice, setReadingDevice] = useState(false);
    const [total, setTotal] = useState(null);
    const debounce = useRef(null);

    // Matches the roster's one-page view; see the truncation notice in load().
    const PER_PAGE = 100;

    const load = useCallback(async (term = '') => {
        setLoading(true);
        try {
            const res = await api.get(`/employees?per_page=${PER_PAGE}${term ? `&search=${encodeURIComponent(term)}` : ''}`);
            setRows(res.data);
            // The endpoint paginates, so this list can be a truncated view. Keep
            // the total so the UI can say so rather than quietly showing 100 of
            // 300 people as if that were everyone.
            setTotal(res.meta?.pagination?.total ?? null);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not load employees.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    // Terminals for the enroll dialog. A user who may push employees but not
    // view devices simply gets no terminals offered, which the dialog explains.
    useEffect(() => {
        if (!can('devices.view')) return;
        api.get('/devices').then((res) => setDevices(res.data ?? [])).catch(() => setDevices([]));
    }, [can]);

    // Debounced server-side search — no full reload, just the list re-queries.
    function onSearch(term) {
        setSearch(term);
        clearTimeout(debounce.current);
        debounce.current = setTimeout(() => load(term), 300);
    }

    async function createEmployee(payload) {
        const res = await api.post('/employees', payload); // throws ApiError(422) for the form to show
        setRows((prev) => [res.data, ...prev]);
        setFormOpen(false);
    }

    /**
     * Deletes the employee from THIS APP only. Their record on the terminal is
     * untouched, so their fingerprints keep working and they can still clock in —
     * which is exactly the kind of thing a bare "Remove X?" prompt hides. The
     * confirmation says so, and points at the fix.
     */
    async function deleteEmployee(employee) {
        const onDevice = employee.enrollment_synced_at != null;

        const ok = await confirm({
            title: `Delete ${employee.name} from the payroll app?`,
            body: [
                `This removes their record and their ${(employee.attendance_logs_count ?? 0).toLocaleString()} attendance punches from this app.`,
                onDevice
                    ? 'It does NOT remove them from the biometric terminal — their fingerprints will keep working '
                      + 'and punches will keep arriving. Remove them from the terminal first if that is what you want.'
                    : 'They are not on a terminal, so nothing else needs doing.',
            ],
            confirmLabel: 'Delete employee',
            danger: true,
        });
        if (!ok) return;

        try {
            await api.delete(`/employees/${employee.id}`);
            setRows((prev) => prev.filter((r) => r.id !== employee.id));
            setTotal((t) => (t == null ? t : Math.max(0, t - 1)));
            toast.success(`${employee.name} deleted.`);
        } catch (err) {
            // Previously unhandled: a 403 or a failed request vanished silently
            // and the row stayed put with no explanation.
            toast.error(err instanceof ApiError ? err.message : `Could not delete ${employee.name}.`);
        }
    }

    /**
     * Refreshes the whole roster's fingerprint and card counts from a terminal in
     * ONE device conversation. Reading per row would open a connection per
     * employee, which the hardware would not survive on a 100-row page.
     */
    async function readFromDevice() {
        const target = devices.find((d) => d.protocol === 'binary' && d.is_active);
        if (!target) {
            toast.error('No active Binary/TCP-IP terminal to read from.');
            return;
        }

        setReadingDevice(true);
        try {
            const res = await api.post(`/devices/${target.id}/sync-enrollment`);
            // fingerprints_known false means the card numbers are fresh but the
            // counts are not — the API message already says so; keep its wording.
            if (res.data?.fingerprints_known) toast.success(res.message);
            else toast.info(res.message);
            await load(search);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not read from the terminal.');
        } finally {
            setReadingDevice(false);
        }
    }

    const presentationProps = {
        rows,
        loading,
        search,
        onSearch,
        canCreate: can('employees.create'),
        canDelete: can('employees.delete'),
        canEnroll: can('employees.push'),
        canReadDevice: can('devices.sync') && devices.some((d) => d.protocol === 'binary' && d.is_active),
        readingDevice,
        total,
        perPage: PER_PAGE,
        onAdd: () => setFormOpen(true),
        onDelete: deleteEmployee,
        onEnroll: (employee) => setEnrolling(employee),
        onView: (employee) => setViewing(employee),
        onReadFromDevice: readFromDevice,
    };

    const Presentation = deviceClass === 'desktop' ? EmployeesDesktop : EmployeesMobile;

    return (
        <>
            {loading && rows.length === 0 ? (
                <SkeletonTable rows={8} cols={7} />
            ) : (
                <Presentation {...presentationProps} />
            )}
            {formOpen && (
                <EmployeeFormModal onSubmit={createEmployee} onClose={() => setFormOpen(false)} />
            )}
            {viewing && !enrolling && (
                <EmployeeDetailModal
                    employee={rows.find((r) => r.id === viewing.id) ?? viewing}
                    devices={devices}
                    canEnroll={can('employees.push')}
                    onClose={() => setViewing(null)}
                    onEnroll={(employee) => setEnrolling(employee)}
                    // An on-demand device read is persisted server-side, so pull
                    // the roster back in step with it.
                    onRefreshed={() => load(search)}
                />
            )}
            {enrolling && (
                <EnrollModal
                    employee={rows.find((r) => r.id === enrolling.id) ?? enrolling}
                    devices={devices}
                    onClose={() => setEnrolling(null)}
                    // A capture changes fingerprint_count / has_card server-side,
                    // so refetch rather than guessing the new values locally.
                    onEnrolled={() => load(search)}
                />
            )}
        </>
    );
}

export { ApiError };
