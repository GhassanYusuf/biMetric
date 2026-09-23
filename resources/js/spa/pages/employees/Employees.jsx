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
import MergeModal from './MergeModal';
import PushModal from './PushModal';
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
    // Server-side filters. Kept as plain strings so they map straight onto the query
    // string and an empty value means "no filter" without a second flag per field.
    const [filters, setFilters] = useState({});
    const [departments, setDepartments] = useState([]);
    const [formOpen, setFormOpen] = useState(false);
    const [enrolling, setEnrolling] = useState(null); // employee being enrolled
    const [editing, setEditing] = useState(null); // employee being edited
    const [pushing, setPushing] = useState(null); // employee being pushed to terminals
    const [merging, setMerging] = useState(null); // employee being merged with duplicates
    const [viewing, setViewing] = useState(null); // employee whose enrollment is open
    const [readingDevice, setReadingDevice] = useState(false);
    const [total, setTotal] = useState(null);
    const debounce = useRef(null);

    // Matches the roster's one-page view; see the truncation notice in load().
    const PER_PAGE = 100;

    const load = useCallback(async (term = '', active = {}) => {
        setLoading(true);
        try {
            const params = new URLSearchParams({ per_page: String(PER_PAGE) });
            if (term) params.set('search', term);
            if (active.national_id) params.set('has_national_id', active.national_id);
            if (active.department_id) params.set('department_id', active.department_id);
            if (active.is_active) params.set('is_active', active.is_active);
            // "On no terminal" is a different question from "on terminal N", so it is a
            // separate parameter rather than a magic device id.
            if (active.device === 'none') params.set('unenrolled', '1');
            else if (active.device) params.set('device_id', active.device);

            const res = await api.get(`/employees?${params.toString()}`);
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
        load(search, filters);
        // Filters apply immediately; only the free-text search is debounced, and it
        // calls load() itself.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [load, filters]);

    // Departments for the filter. Absent permission simply means no department filter
    // is offered, rather than an error the operator can do nothing about.
    useEffect(() => {
        api.get('/departments').then((res) => setDepartments(res.data ?? [])).catch(() => setDepartments([]));
    }, []);

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
        debounce.current = setTimeout(() => load(term, filters), 300);
    }

    /**
     * Saves one national ID.
     *
     * Throws on failure rather than toasting, so the inline editor can keep the error
     * on the field with the half-typed value still in it (rule 2: a 422 is not a toast).
     *
     * The row is patched in place rather than reloading the list: if the operator is
     * filtered to "missing a national ID", a reload would make the row they just filled
     * vanish from under the cursor mid-run.
     */
    async function saveNationalId(employee, nationalId) {
        try {
            const res = await api.put(`/employees/${employee.id}/national-id`, { national_id: nationalId });
            setRows((prev) => prev.map((r) => (r.id === employee.id ? res.data : r)));
            toast.success(res.message);
        } catch (err) {
            const message = err instanceof ApiError ? err.message : 'Could not save the national ID.';
            throw new Error(message);
        }
    }

    /**
     * Saves, then writes them onto the terminals the form selected.
     *
     * TWO STEPS ON PURPOSE, and reported as two. The save either worked or came
     * back 422 for the form to render; the push reaches each device separately and
     * can succeed on some and fail on others. A single "Saved!" covering both would
     * hide the case that actually needs the operator: the record is right here and
     * still wrong on the hardware.
     *
     * The push never fails the save. By the time it runs the employee exists, and
     * throwing here would leave the form open over a record that was already
     * written — so its failure is a toast, not an exception.
     */
    async function propagate(employee, deviceIds) {
        if (!deviceIds?.length) return;

        try {
            const res = await api.post(`/employees/${employee.id}/propagate`, { device_ids: deviceIds });
            /*
             * The message names every terminal written, failed and skipped with why,
             * so it is deliberately not shortened here. A run with any failure is an
             * ERROR toast even though the request succeeded — the HTTP status is
             * about the request, and the operator cares about the hardware.
             */
            const failed = (res.data?.failed ?? []).length;
            if (failed > 0) toast.error(res.message);
            else toast.success(res.message);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Saved, but the terminals could not be written to.');
        }
    }

    async function createEmployee(payload, deviceIds) {
        const res = await api.post('/employees', payload); // throws ApiError(422) for the form to show
        setRows((prev) => [res.data, ...prev]);
        setFormOpen(false);
        await propagate(res.data, deviceIds);
    }

    async function updateEmployee(payload, deviceIds) {
        const res = await api.put(`/employees/${editing.id}`, payload); // 422 → inline errors
        setRows((prev) => prev.map((r) => (r.id === editing.id ? res.data : r)));
        const saved = editing;
        setEditing(null);
        await propagate(saved, deviceIds);
        // The push changes what the terminals hold, which the roster reports. Refetch
        // rather than guessing — the commands run on a worker and land shortly after.
        await load(search, filters);
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
            await load(search, filters);
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
        filters,
        onFilters: setFilters,
        departments,
        devices,
        canSetNationalId: can('employees.update'),
        onSaveNationalId: saveNationalId,
        canCreate: can('employees.create'),
        canUpdate: can('employees.update'),
        canDelete: can('employees.delete'),
        canEnroll: can('employees.push'),
        canReadDevice: can('devices.sync') && devices.some((d) => d.protocol === 'binary' && d.is_active),
        readingDevice,
        total,
        perPage: PER_PAGE,
        onAdd: () => setFormOpen(true),
        onDelete: deleteEmployee,
        onEnroll: (employee) => setEnrolling(employee),
        onEdit: (employee) => setEditing(employee),
        onPush: (employee) => setPushing(employee),
        onMerge: (employee) => setMerging(employee),
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
                <EmployeeFormModal
                    devices={devices}
                    onSubmit={createEmployee}
                    onClose={() => setFormOpen(false)}
                />
            )}
            {pushing && (
                <PushModal
                    employee={rows.find((r) => r.id === pushing.id) ?? pushing}
                    devices={devices}
                    onSubmit={async (deviceIds) => {
                        await propagate(pushing, deviceIds);
                        setPushing(null);
                        // The push changes what the terminals hold, which the roster
                        // reports. Refetch rather than guess — the commands run on a
                        // worker and land shortly after.
                        await load(search, filters);
                    }}
                    onClose={() => setPushing(null)}
                />
            )}
            {merging && (
                <MergeModal
                    employee={rows.find((r) => r.id === merging.id) ?? merging}
                    onMerged={async (message) => {
                        toast.success(message);
                        setMerging(null);
                        // Rows disappeared and one absorbed their history — nothing
                        // local can be patched up to match. Refetch.
                        await load(search, filters);
                    }}
                    onClose={() => setMerging(null)}
                />
            )}
            {editing && (
                <EmployeeFormModal
                    // The row from the last fetch, so `enrollments` is the current
                    // one rather than whatever was on screen when Edit was pressed.
                    employee={rows.find((r) => r.id === editing.id) ?? editing}
                    devices={devices}
                    onSubmit={updateEmployee}
                    onClose={() => setEditing(null)}
                />
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
                    onRefreshed={() => load(search, filters)}
                />
            )}
            {enrolling && (
                <EnrollModal
                    employee={rows.find((r) => r.id === enrolling.id) ?? enrolling}
                    devices={devices}
                    onClose={() => setEnrolling(null)}
                    // A capture changes fingerprint_count / has_card server-side,
                    // so refetch rather than guessing the new values locally.
                    onEnrolled={() => load(search, filters)}
                />
            )}
        </>
    );
}

export { ApiError };
