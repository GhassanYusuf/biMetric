import { useCallback, useEffect, useMemo, useState } from 'react';
import { useAuth } from '../../shared/auth';
import { useMqtt } from '../../shared/useMqtt';
import { api, ApiError } from '../../shared/api';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useToast } from '../../shared/toast';
import { useConfirm } from '../../shared/confirm';
import { SkeletonTable } from '../../shared/ui';
import ReportsDesktop from './ReportsDesktop';
import ReportsMobile from './ReportsMobile';
import { useAllEmployeesExport } from '../../shared/useAllEmployeesExport';

/*
| Payroll hours and attendance counts per employee over a range.
|
| The screen and its PDF are the same report: both come from PayrollReport on the
| server, which builds every row from AttendanceSummaryService. Nothing is
| totalled in the browser, so the figure on screen and the figure in the file
| cannot drift — and neither can be "fixed" in one place only.
|
| An absent range is NOT "all time": the server defaults to the last 30 days and
| returns the dates it used, which is what the header states.
|
| Two PDFs leave from here with the same filters: the payroll summary (this table as
| a document) and the attendance report (AttendanceReport, 2026-10-18 — charts and
| findings for management, with this table's people as its appendix).
*/
const EXPORTS = {
    report: { path: '/exports/attendance-report', fallback: 'attendance-report.pdf' },
    payroll: { path: '/exports/payroll', fallback: 'payroll-summary.pdf' },
    // ZKTeco's Monthly Statement layout, the format the company already files (MonthlyStatement).
    statement: { path: '/exports/monthly-statement', fallback: 'monthly-statement.pdf' },
};

export default function Reports() {
    const deviceClass = useDeviceClass();
    const toast = useToast();
    const confirm = useConfirm();

    const [report, setReport] = useState(null);
    const [departments, setDepartments] = useState([]);
    const [loading, setLoading] = useState(true);
    // Which PDF is being produced ('report' | 'payroll' | 'statement'), so only its button spins.
    const [exporting, setExporting] = useState(null);
    const [filters, setFilters] = useState({ from: '', to: '', department_id: '' });
    // Every employee's report for a period, built in the background (ReportExportController).
    const [runs, setRuns] = useState([]);
    const [downloading, setDownloading] = useState(null);
    const [deleting, setDeleting] = useState(null);

    const query = useMemo(() => {
        const params = new URLSearchParams();
        Object.entries(filters).forEach(([k, v]) => {
            if (v) params.set(k, v);
        });
        return params.toString();
    }, [filters]);

    const { user } = useAuth();
    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await api.get(`/reports/attendance?${query}`);
            setReport(res.data);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not build the report.');
        } finally {
            setLoading(false);
        }
    }, [query]); // eslint-disable-line react-hooks/exhaustive-deps

    const loadRuns = useCallback(async () => {
        try {
            const res = await api.get('/exports/employee-reports');
            // When a run started from here lands, useAllEmployeesExport says so.
            setRuns(res.data ?? []);
        } catch {
            // The list is secondary; the next live change asks again.
        }
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    // LIVE (CLAUDE.md rule 18): refresh in place when attendance, employees, holidays change anywhere;
    // the export list when a report file moves (module/reports).
    useMqtt(user?.tenant ? [`tenant/${user.tenant.id}/module/attendance/+`, `tenant/${user.tenant.id}/module/employees/+`, `tenant/${user.tenant.id}/module/holidays/+`, `tenant/${user.tenant.id}/module/reports/+`] : [], (topic) => {
        // `live/reports` is the polling carrier's name for the same module (useMqtt).
        if (topic === 'live/reports' || /\/module\/reports\//.test(topic ?? '')) loadRuns();
        else load();
    });

    useEffect(() => {
        loadRuns();
    }, [loadRuns]);

    useEffect(() => {
        load();
    }, [load]);

    // Departments drive the filter. A user who may read reports but not
    // departments simply gets no filter options, which is not an error.
    useEffect(() => {
        api.get('/departments').then((res) => setDepartments(res.data ?? [])).catch(() => setDepartments([]));
    }, []);

    async function exportPdf(kind) {
        setExporting(kind);
        try {
            const name = await api.download(`${EXPORTS[kind].path}?${query}`, EXPORTS[kind].fallback);
            toast.success(`Saved ${name}.`);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not produce the PDF.');
        } finally {
            setExporting(null);
        }
    }

    // "All employees ▾" — PDF or Excel — shared with the Attendance screen.
    const allEmployees = useAllEmployeesExport({
        departments,
        initialRange: filters.from && filters.to ? { from: filters.from, to: filters.to } : null,
        initialDepartment: filters.department_id,
        onPdfStarted: () => loadRuns(),
    });

    async function downloadRun(run) {
        setDownloading(run.id);
        try {
            const name = await api.download(`/exports/employee-reports/${run.id}/download`, run.filename || 'employee-reports.pdf');
            toast.success(`Saved ${name}.`);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not download the file.');
        } finally {
            setDownloading(null);
        }
    }

    async function deleteRun(run) {
        const ok = await confirm({
            title: 'Delete this report file?',
            body: `${run.scope ?? 'Every employee'}, ${run.from} to ${run.to}. The PDF is removed from the server; you can build it again at any time.`,
            confirmLabel: 'Delete file',
            danger: true,
        });
        if (!ok) return;

        setDeleting(run.id);
        try {
            const res = await api.delete(`/exports/employee-reports/${run.id}`);
            setRuns((prev) => prev.filter((r) => r.id !== run.id));
            toast.success(res.message);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not delete the file.');
        } finally {
            setDeleting(null);
        }
    }

    const presentationProps = {
        runs,
        downloading,
        onDownloadRun: downloadRun,
        deleting,
        onDeleteRun: deleteRun,
        allEmployeesItems: allEmployees.items,
        report,
        departments,
        filters,
        onFilter: setFilters,
        loading,
        exporting,
        onExport: exportPdf,
    };

    const Presentation = deviceClass === 'desktop' ? ReportsDesktop : ReportsMobile;

    if (loading && !report) {
        return <SkeletonTable rows={8} cols={8} />;
    }

    return (
        <>
            <Presentation {...presentationProps} />
            {allEmployees.dialog}
        </>
    );
}
