import { useCallback, useEffect, useMemo, useState } from 'react';
import { useAuth } from '../../shared/auth';
import { useMqtt } from '../../shared/useMqtt';
import { api, ApiError } from '../../shared/api';
import { useDeviceClass } from '../../shared/useDeviceClass';
import { useToast } from '../../shared/toast';
import { SkeletonTable } from '../../shared/ui';
import ReportsDesktop from './ReportsDesktop';
import ReportsMobile from './ReportsMobile';

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
*/
export default function Reports() {
    const deviceClass = useDeviceClass();
    const toast = useToast();

    const [report, setReport] = useState(null);
    const [departments, setDepartments] = useState([]);
    const [loading, setLoading] = useState(true);
    const [exporting, setExporting] = useState(false);
    const [filters, setFilters] = useState({ from: '', to: '', department_id: '' });

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

    // LIVE (CLAUDE.md rule 18): refresh in place when attendance, employees, holidays change anywhere.
    useMqtt(user?.tenant ? [`tenant/${user.tenant.id}/module/attendance/+`, `tenant/${user.tenant.id}/module/employees/+`, `tenant/${user.tenant.id}/module/holidays/+`] : [], () => load());

    useEffect(() => {
        load();
    }, [load]);

    // Departments drive the filter. A user who may read reports but not
    // departments simply gets no filter options, which is not an error.
    useEffect(() => {
        api.get('/departments').then((res) => setDepartments(res.data ?? [])).catch(() => setDepartments([]));
    }, []);

    async function exportPdf() {
        setExporting(true);
        try {
            const name = await api.download(`/exports/payroll?${query}`, 'payroll-summary.pdf');
            toast.success(`Saved ${name}.`);
        } catch (err) {
            toast.error(err instanceof ApiError ? err.message : 'Could not produce the PDF.');
        } finally {
            setExporting(false);
        }
    }

    const presentationProps = {
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

    return <Presentation {...presentationProps} />;
}
