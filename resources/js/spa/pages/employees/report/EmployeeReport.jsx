import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useClockFormat } from '../../../shared/clockFormat';
import { useParams } from 'react-router-dom';
import { api, ApiError } from '../../../shared/api';
import { useAuth } from '../../../shared/auth';
import { useDeviceClass } from '../../../shared/useDeviceClass';
import { useMqtt } from '../../../shared/useMqtt';
import { useToast } from '../../../shared/toast';
import { deriveReport } from './model';
import { sampleReport } from './sampleReport';
import ReportSkeleton from './ReportSkeleton';
import ReportRangeDialog from '../../../shared/ReportRangeDialog';
import EmployeeReportDesktop from './EmployeeReportDesktop';
import EmployeeReportMobile from './EmployeeReportMobile';

/*
| One employee's report — the "Orbit" screen (2026-09-27). Owns the data; the
| Desktop and Mobile files only render (rule 5).
|
| Everything on it comes from GET /api/employees/{id}/report, which judges every day
| with the same AttendanceSummaryService the payroll report uses. The browser only
| groups and draws (see model.js).
|
| /employees/sample/report shows generated SAMPLE data in the same layout, labelled
| as such on screen, so the design can be seen before real attendance exists.
|
| LIVE (rule 18): a punch, holiday, employee edit or terminal read anywhere refreshes
| it in place — same period, no skeleton flash once something is on screen.
*/
export default function EmployeeReport() {
    const { id } = useParams();
    const sample = id === 'sample';
    const deviceClass = useDeviceClass();
    const toast = useToast();
    const { user } = useAuth();

    const [period, setPeriod] = useState(30);
    const [report, setReport] = useState(null);
    const [loading, setLoading] = useState(true);
    const [failure, setFailure] = useState(null);
    const [exporting, setExporting] = useState(false);
    const [choosingRange, setChoosingRange] = useState(false);
    const shown = useRef(false);

    const load = useCallback(async () => {
        if (sample) {
            setReport(sampleReport(period));
            setLoading(false);
            shown.current = true;
            return;
        }
        setLoading(true);
        try {
            const res = await api.get(`/employees/${id}/report?period=${period}`);
            setReport(res.data);
            setFailure(null);
            shown.current = true;
        } catch (err) {
            const message = err instanceof ApiError ? err.message : 'Could not build the report.';
            // Nothing on screen yet: that is page state. A failed refresh of a report
            // already showing is an event, and the old figures stay (rule 2).
            if (shown.current) toast.error(message);
            else setFailure(err instanceof ApiError && err.status === 404 ? 'This employee does not exist, or is not in your company.' : message);
        } finally {
            setLoading(false);
        }
    }, [id, period, sample]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        load();
    }, [load]);

    const t = user?.tenant?.id;
    useMqtt(!sample && t ? [
        `tenant/${t}/module/attendance/+`,
        `tenant/${t}/module/employees/+`,
        `tenant/${t}/module/holidays/+`,
        `tenant/${t}/module/devices/+`,
    ] : [], () => load());

    // The derived text bakes clock times in, so it is rebuilt when the format flips.
    const [clockFormat] = useClockFormat();
    const derived = useMemo(() => (report ? deriveReport(report) : null), [report, clockFormat]);

    // Any period as a document (ExportController::employeeReport, PersonalReport) —
    // the dialog opens on the period shown here. The sample has no server-side twin,
    // so it offers no export.
    async function exportPdf({ from, to }) {
        setExporting(true);
        try {
            const name = await api.download(`/exports/employees/${id}/report?from=${from}&to=${to}`, 'employee-report.pdf');
            toast.success(`Saved ${name}.`);
            setChoosingRange(false);
        } catch (err) {
            // A 422 goes back to the dialog's fields; anything else is an event.
            if (!(err instanceof ApiError && err.errors)) toast.error(err instanceof ApiError ? err.message : 'Could not produce the PDF.');
            throw err;
        } finally {
            setExporting(false);
        }
    }

    const props = {
        report, derived, period, onPeriod: setPeriod, refreshing: loading && Boolean(report), sample, failure,
        exporting, onExport: sample ? null : () => setChoosingRange(true),
    };
    const Presentation = deviceClass === 'desktop' ? EmployeeReportDesktop : EmployeeReportMobile;

    if (!report && !failure) return <ReportSkeleton compact={deviceClass !== 'desktop'} period={period} onPeriod={setPeriod} />;

    return (
        <>
            <Presentation {...props} />
            {choosingRange && report && (
                <ReportRangeDialog
                    title={`Report for ${report.employee.name}`}
                    intro="A PDF with charts and every punch of the period."
                    confirmLabel="Download PDF"
                    initial={{ from: report.period.from, to: report.period.to }}
                    onSubmit={exportPdf}
                    onClose={() => setChoosingRange(false)}
                />
            )}
        </>
    );
}
