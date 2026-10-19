import { useCallback, useRef, useState } from 'react';
import { api, ApiError } from './api';
import { useAuth } from './auth';
import { useMqtt } from './useMqtt';
import { useToast } from './toast';
import ReportRangeDialog from './ReportRangeDialog';

/*
| The "All employees" exports, offered from both the Attendance and the Reports screens
| (owner, 2026-10-19: "the export option must be in /app/attendance"). One hook so the two
| buttons cannot drift apart:
|
|   'pdf'    every person's report in one PDF, built in the background (ReportExportController)
|   'excel'  every person's Monthly Statement as one workbook, downloaded at once
|            (ExportController::monthlyStatementExcel)
|
| Returns `choose(format)` to open the range dialog, `excelBusy` for the menu item's
| spinner, and `dialog` to render. A PDF started here announces itself with a toast when
| it lands, on whichever page the user is then on that uses this hook, by watching the
| reports module (rule 18); `onPdfStarted` lets the Reports screen refresh its file list.
*/
export function useAllEmployeesExport({ departments = null, initialRange = null, initialDepartment = '', onPdfStarted } = {}) {
    const { user } = useAuth();
    const toast = useToast();
    const [format, setFormat] = useState(null);
    const [excelBusy, setExcelBusy] = useState(false);
    const watching = useRef(new Set());

    const announce = useCallback(async () => {
        if (watching.current.size === 0) return;
        try {
            const res = await api.get('/exports/employee-reports');
            (res.data ?? []).forEach((run) => {
                if (run.is_finished && watching.current.has(run.id)) {
                    watching.current.delete(run.id);
                    if (run.status === 'done') toast.success(`Employee reports ready: ${run.message} Download it from Reports.`);
                    else toast.error(run.message);
                }
            });
        } catch {
            // The next change to the reports module asks again.
        }
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    useMqtt(user?.tenant ? [`tenant/${user.tenant.id}/module/reports/+`] : [], (topic) => {
        // `live/reports` is the polling carrier's name for the same module (useMqtt).
        if (topic === 'live/reports' || /\/module\/reports\//.test(topic ?? '')) announce();
    });

    async function startPdf(range) {
        try {
            const res = await api.post('/exports/employee-reports', range);
            watching.current.add(res.data.id);
            toast.info(`${res.message} You can leave this page — you will be notified when the file is ready.`);
            setFormat(null);
            onPdfStarted?.(res.data);
        } catch (err) {
            if (!(err instanceof ApiError && err.errors && Object.keys(err.errors).length)) toast.error(err instanceof ApiError ? err.message : 'Could not start the export.');
            throw err;
        }
    }

    // Synchronous: a whole company is a few seconds, unlike the PDF. A 422 throws back to
    // the dialog, which shows it on the field (rule 2).
    async function downloadExcel(range) {
        setExcelBusy(true);
        try {
            const params = new URLSearchParams({ from: range.from, to: range.to });
            if (range.department_id) params.set('department_id', range.department_id);
            const name = await api.download(`/exports/monthly-statement/excel?${params}`, 'monthly-statement.xlsx');
            toast.success(`Saved ${name}.`);
            setFormat(null);
        } catch (err) {
            if (!(err instanceof ApiError && err.errors && Object.keys(err.errors).length)) toast.error(err instanceof ApiError ? err.message : 'Could not produce the Excel file.');
            throw err;
        } finally {
            setExcelBusy(false);
        }
    }

    const dialog = format && (
        <ReportRangeDialog
            title={format === 'excel' ? 'All employees · Excel' : 'All employees · PDF'}
            intro={format === 'excel'
                ? 'Every person\'s Monthly Statement in one workbook, in the ZKTeco layout: a block per person, a row per day, and a Summary sheet of totals. Downloads straight away.'
                : 'Every person\'s report — charts and every punch — in one PDF with a contents page. It is built in the background; large companies take a minute or two.'}
            confirmLabel={format === 'excel' ? 'Download Excel' : 'Build PDF'}
            initial={initialRange}
            departments={departments}
            initialDepartment={initialDepartment}
            onSubmit={format === 'excel' ? downloadExcel : startPdf}
            onClose={() => setFormat(null)}
        />
    );

    /** The two menu items, for an ActionMenu. */
    const items = [
        { key: 'all-pdf', label: 'All employees · PDF', description: 'Every person\'s report in one PDF, built in the background', onSelect: () => setFormat('pdf') },
        { key: 'all-excel', label: 'All employees · Excel', description: 'Monthly Statement for everyone, one workbook', onSelect: () => setFormat('excel'), loading: excelBusy },
    ];

    return { items, excelBusy, dialog };
}
