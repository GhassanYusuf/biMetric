<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Services\Attendance\AttendanceSummaryService;
use App\Services\Attendance\EmployeeReportService;
use App\Services\Attendance\PunchLogQuery;
use App\Services\Employees\EmployeeRosterQuery;
use App\Services\Reports\AttendanceReport;
use App\Services\Reports\EmployeeRoster;
use App\Services\Reports\EmployeeRosterWorkbook;
use App\Services\Reports\MonthlyStatement;
use App\Services\Reports\MonthlyStatementWorkbook;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\PersonalReport;
use App\Services\Reports\PayrollReport;
use App\Support\ApiResponse;
use App\Support\VerifyMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF exports: the punch log as filtered, one employee's attendance, the
 * payroll summary, the reports, and the employee list (PDF and Excel).
 *
 * THREE RULES HOLD HERE, and they are the whole reason this is a controller of
 * its own rather than a `?format=pdf` branch in each existing one:
 *
 * 1. NO SECOND SOURCE OF TRUTH. Every figure comes from the same class the
 *    screen reads — PunchLogQuery, AttendanceSummaryService, PayrollReport. A
 *    printout that disagrees with the screen is worse than no printout, because
 *    it is the copy that gets filed and argued over months later.
 *
 * 2. THE SAME PERMISSIONS. An export is a copy of data leaving the system, so
 *    it re-applies the exact rule the list applies — an employee-role user gets
 *    their own punches here too, never the company's.
 *
 * 3. THE PAGE STATES WHAT IT IS. Timezone, range, filters, generation time and
 *    any truncation are printed on the document. A PDF outlives the screen that
 *    produced it and nobody can re-query the context it was made in.
 *
 * Rendering is synchronous: these are read-only queries, unlike device work,
 * which rule 11 keeps out of web requests. MAX_ROWS is what keeps that safe.
 */
class ExportController extends Controller
{
    /**
     * Most punches one PDF will carry.
     *
     * 5,000 rows is roughly 100 pages and a few seconds of dompdf. Beyond that a
     * request-time render stops being reasonable, so the export truncates and
     * SAYS SO on the page rather than quietly printing a partial log — a
     * silently short attendance record is exactly the kind of thing somebody
     * later treats as evidence of absence.
     */
    private const MAX_ROWS = 5000;

    public function __construct(
        private readonly AttendanceSummaryService $summaries,
        private readonly PayrollReport $payroll,
        private readonly AttendanceReport $attendanceReport,
        private readonly PersonalReport $personal,
        private readonly PdfRenderer $pdf,
        private readonly MonthlyStatement $statement,
    ) {}

    /**
     * Longest range the company report renders in a request. A year of a 235-person
     * roster (two years read, for the comparison) measured ~4 s on 2026-10-18.
     */
    private const MAX_REPORT_DAYS = 366;

    /**
     * Most people one Monthly Statement renders in a request: a page each, and dompdf
     * took 18.6 s for 40 of them on 2026-10-19 (the data itself is 0.3 s for all 235).
     * Beyond this the caller is asked for a department rather than left waiting.
     */
    private const MAX_STATEMENT_EMPLOYEES = 60;

    /** The punch log, honouring the screen's device/date/employee filters. */
    public function attendanceLog(Request $request): Response
    {
        $user = $request->user();

        if (! PunchLogQuery::allowed($user)) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        }

        $filters = $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'device_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'regex:/^(none|\d+)$/'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $selfOnly = PunchLogQuery::selfOnly($user);
        $query = PunchLogQuery::build($user, $selfOnly, $filters);

        $total = (clone $query)->count();

        $logs = $query->with(['employee:id,name,last_name', 'device:id,name'])
            ->limit(self::MAX_ROWS)
            ->get();

        $tz = config('attendance.timezone');

        $rows = $logs->map(function ($log) use ($tz) {
            // punched_at is stored UTC (rule 10). Every printed time is moved to
            // office-local here, once, and the footer names the zone.
            $local = $log->punched_at?->copy()->setTimezone($tz);

            return [
                'name' => $log->employee?->fullName(),
                'code' => $log->device_user_id,
                'device' => $log->device?->name,
                'method' => VerifyMethod::label($log->verify_method),
                'date' => $local?->format('Y-m-d'),
                'time' => $local?->format('g:i A'),
            ];
        });

        $filterLines = [];
        if ($selfOnly) {
            $filterLines[] = 'Your own punches only';
        } elseif (! empty($filters['employee_id'])) {
            $filterLines[] = 'Employee: '.(Employee::find($filters['employee_id'])?->fullName() ?? "#{$filters['employee_id']}");
        }
        if (! empty($filters['device_id'])) {
            $filterLines[] = 'Device: '.(Device::find($filters['device_id'])?->name ?? "#{$filters['device_id']}");
        }
        if (! empty($filters['search'])) {
            $filterLines[] = 'Search: “'.$filters['search'].'”';
        }
        if (($filters['department_id'] ?? null) === 'none') {
            $filterLines[] = 'Department: unassigned';
        } elseif (! empty($filters['department_id'])) {
            // The full path, so a PDF says which company's "Finance" it is.
            $filterLines[] = 'Department: '.(Department::find($filters['department_id'])?->path() ?? "#{$filters['department_id']}").' (and below)';
        }

        return $this->render('pdf.attendance-log', [
            'title' => 'Attendance log',
            'rangeLabel' => $this->rangeLabel($filters['from'] ?? null, $filters['to'] ?? null),
            'filterLines' => $filterLines,
            'rows' => $rows,
            'total' => $total,
            'shown' => $rows->count(),
            'truncated' => $total > $rows->count(),
            'maxRows' => self::MAX_ROWS,
        ], $this->filename('attendance', $filters));
    }

    /**
     * One employee's attendance over a range: who they are, the totals, and the
     * day-by-day breakdown behind those totals.
     *
     * Built from AttendanceSummaryService, so "payroll hours" here is the same
     * number the payroll summary adds up, computed the same way.
     */
    public function employeeAttendance(Request $request, Employee $employee): Response
    {
        $this->authorize('view', $employee);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $summary = $this->summaries->summary($employee, $validated['from'] ?? null, $validated['to'] ?? null);

        return $this->render('pdf.employee-attendance', [
            'title' => 'Attendance record',
            'rangeLabel' => $this->rangeLabel($summary['from'], $summary['to']),
            'employee' => $summary['employee'],
            'summary' => $summary,
        ], $this->filename('attendance-'.Str::slug($employee->name ?: "employee-{$employee->id}"), $validated));
    }

    /** Payroll hours and day counts for every active employee. */
    public function payrollSummary(Request $request): Response
    {
        if (! $request->user()->can('reports.view')) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        }

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'department_id' => ['nullable', 'integer'],
        ]);

        $report = $this->payroll->build(
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            isset($validated['department_id']) ? (int) $validated['department_id'] : null,
        );

        // PayrollReport defaults an absent range to the summary service's own
        // window (the last 30 days). Naming the dates the rows were actually
        // computed for beats printing "all time", which would be false.
        $filterLines = [];
        if (! empty($validated['department_id'])) {
            $department = Department::find($validated['department_id']);
            $filterLines[] = 'Department: '.($department?->name ?? "#{$validated['department_id']}");
        }

        return $this->render('pdf.payroll-summary', [
            'title' => 'Payroll summary',
            'rangeLabel' => $this->rangeLabel($report['from'], $report['to']),
            'filterLines' => $filterLines,
            'rows' => $report['rows'],
            'totals' => $report['totals'],
        ], $this->filename('payroll-summary', $validated));
    }

    /**
     * The company attendance report: headline figures against the previous period,
     * the period day by day, departments, arrival times, who needs attention, and
     * everyone as an appendix. Same permission as the Reports screen.
     */
    public function attendanceReport(Request $request): Response
    {
        if (! $request->user()->can('reports.view')) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        }

        $validated = $request->validate([
            ...$this->periodRules(),
            'department_id' => ['nullable', 'integer'],
        ]);

        if ($error = $this->rangeTooLong($validated)) {
            return $error;
        }

        $report = $this->attendanceReport->build(
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            isset($validated['department_id']) ? (int) $validated['department_id'] : null,
        );

        return $this->render('pdf.report.attendance', [
            'title' => 'Attendance report',
            'rangeLabel' => $this->rangeLabel($report['from'], $report['to']),
            'r' => $report,
        ], $this->filename('attendance-report', ['from' => $report['from'], 'to' => $report['to']]));
    }

    /**
     * One person's attendance report for any period: `from`/`to`, or `period` (30, 90
     * or 365 days ending today, the Employee Report screen's choices). The same
     * PersonalReport and the same page as each person gets in the bulk export.
     */
    public function employeeReport(Request $request, Employee $employee): Response
    {
        $this->authorize('view', $employee);

        $validated = $request->validate([
            ...$this->periodRules(),
            'period' => ['nullable', 'integer', 'in:'.implode(',', EmployeeReportService::PERIODS)],
        ]);

        if (! isset($validated['from']) && isset($validated['period'])) {
            $validated['from'] = now(config('attendance.timezone'))->subDays($validated['period'] - 1)->toDateString();
        }
        if ($error = $this->rangeTooLong($validated)) {
            return $error;
        }

        [$report] = $this->personal->build([$employee], $validated['from'] ?? null, $validated['to'] ?? null);
        $period = $report['period'];

        return $this->render('pdf.report.employee', [
            'title' => 'Employee report · '.$report['employee']['name'],
            'rangeLabel' => $this->rangeLabel($period['from'], $period['to']),
            'p' => $report,
        ], $this->filename('report-'.Str::slug($report['employee']['name'] ?: "employee-{$employee->id}"), $period));
    }

    /**
     * The Monthly Statement Report: every active employee (or one department's), one
     * page each, a row per calendar day, in the ZKTeco column layout the company
     * already files (draft/OTR-SEP.xlsx). Same permission as the payroll summary.
     */
    public function monthlyStatement(Request $request): Response
    {
        if (! $request->user()->can('reports.view')) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        }

        $validated = $request->validate([...$this->periodRules(), ...$this->statementScopeRules()]);

        if ($error = $this->rangeTooLong($validated)) {
            return $error;
        }

        $statement = $this->buildStatement($validated);
        if ($error = $this->nobodyChosen($statement, $validated)) {
            return $error;
        }

        $count = count($statement['employees']);
        if ($count > self::MAX_STATEMENT_EMPLOYEES) {
            $picked = ! empty($validated['employee_ids']);
            $message = "That is {$count} people, a page each — ".($picked ? 'choose fewer' : 'choose a department').' (up to '.self::MAX_STATEMENT_EMPLOYEES.' people per statement).';

            $field = $picked ? 'employee_ids' : (isset($validated['department_ids']) ? 'department_ids' : 'department_id');

            return ApiResponse::error($message, [$field => [$message]], 422);
        }

        $fmt = fn (string $d) => Carbon::parse($d)->format('m/d/Y');
        $days = collect($statement['shift']['work_days'])->map(fn (int $d) => Carbon::create()->startOfWeek(Carbon::SUNDAY)->addDays($d)->format('D'))->implode(' ');
        $filterLines = ["Shift {$statement['shift']['start']}–{$statement['shift']['end']} · {$days}"];
        if ($departmentIds = $this->statementDepartmentIds($validated)) {
            $names = Department::whereIn('id', $departmentIds)->orderBy('name')->pluck('name');
            $filterLines[] = (count($departmentIds) === 1 ? 'Department: ' : 'Departments: ').$names->implode(', ');
        }
        if (! empty($validated['employee_ids'])) {
            $filterLines[] = $count === 1 ? 'Employee: '.$statement['employees'][0]['employee']['name'] : "{$count} chosen employees";
        }

        return $this->render('pdf.monthly-statement', [
            'title' => 'Monthly Statement Report',
            'rangeLabel' => 'From '.$fmt($statement['from']).' To '.$fmt($statement['to']),
            'filterLines' => $filterLines,
            's' => $statement,
        ], $this->filename('monthly-statement', ['from' => $statement['from'], 'to' => $statement['to']]));
    }

    /**
     * The Monthly Statement for every employee, one company or department, or chosen
     * people, as an Excel workbook:
     * the same MonthlyStatement figures as the PDF, written by MonthlyStatementWorkbook.
     * No 60-person cap — a workbook streams in a couple of seconds where dompdf took 18.6 s
     * for 40 pages, which is why the Reports screen's "all employees" Excel is synchronous
     * while its PDF is built in the background.
     */
    public function monthlyStatementExcel(Request $request, MonthlyStatementWorkbook $workbook): Response
    {
        if (! $request->user()->can('reports.view')) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        }

        $validated = $request->validate([...$this->periodRules(), ...$this->statementScopeRules()]);

        if ($error = $this->rangeTooLong($validated)) {
            return $error;
        }

        $statement = $this->buildStatement($validated);
        if ($error = $this->nobodyChosen($statement, $validated)) {
            return $error;
        }

        $path = tempnam(sys_get_temp_dir(), 'statement-');
        $workbook->write(
            $statement,
            auth()->user()?->tenant?->name,
            $path,
            now(config('attendance.timezone'))->format('m/d/Y H:i'),
        );

        $name = str_replace('.pdf', '.xlsx', $this->filename('monthly-statement', ['from' => $statement['from'], 'to' => $statement['to']]));

        return response()->download($path, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * The employee list as filtered on the Employees screen (owner, 2026-10-21): the
     * tree's "All employees" exports everyone, a company or department exports that
     * branch, and search, terminal and status narrow it as they narrow the screen.
     * Same permission as the list. A roster is one short row per person — 362 people
     * is ~10 pages — so it renders in the request, under MAX_ROWS like the punch log.
     */
    public function employees(Request $request, EmployeeRoster $roster): Response
    {
        $this->authorize('viewAny', Employee::class);
        $request->validate(EmployeeRosterQuery::RULES);

        $data = $roster->build($request);
        $count = count($data['rows']);
        if ($count > self::MAX_ROWS) {
            $message = "That is {$count} people — narrow the list, or export it as Excel.";

            return ApiResponse::error($message, [], 422);
        }

        // Landscape: nine columns, and a terminal list that is a line per terminal.
        return $this->pdf->make('pdf.employee-roster', [
            'title' => 'Employees',
            'rangeLabel' => 'As of '.now(config('attendance.timezone'))->format('j M Y'),
            ...$data,
        ], auth()->user()?->tenant?->name, 'landscape')->download($this->filename('employees', []));
    }

    /** The same list as employees(), as an Excel workbook. No cap: it streams. */
    public function employeesExcel(Request $request, EmployeeRoster $roster, EmployeeRosterWorkbook $workbook): Response
    {
        $this->authorize('viewAny', Employee::class);
        $request->validate(EmployeeRosterQuery::RULES);

        $path = tempnam(sys_get_temp_dir(), 'employees-');
        $workbook->write(
            $roster->build($request),
            auth()->user()?->tenant?->name,
            $path,
            now(config('attendance.timezone'))->format('Y-m-d g:i A'),
        );

        return response()->download($path, str_replace('.pdf', '.xlsx', $this->filename('employees', [])), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * Who a Monthly Statement covers: everyone, one or more companies / departments (with
     * the ones below each), or people picked by id (owner, 2026-10-19) — departments or
     * people, never both. `department_id` is the single form the Reports screen's PDF
     * button sends; `department_ids[]` the dialog's multiple choice. Ids go through the
     * tenant-scoped Employee query, so another company's id simply drops out.
     */
    private function statementScopeRules(): array
    {
        return [
            'department_id' => ['nullable', 'integer', 'prohibits:employee_ids'],
            'department_ids' => ['nullable', 'array', 'min:1', 'max:200', 'prohibits:employee_ids'],
            'department_ids.*' => ['integer', 'distinct'],
            'employee_ids' => ['nullable', 'array', 'min:1', 'max:1000'],
            'employee_ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return list<int>|null  null = no department filter */
    private function statementDepartmentIds(array $validated): ?array
    {
        $ids = array_map('intval', [...($validated['department_ids'] ?? []), ...(isset($validated['department_id']) ? [$validated['department_id']] : [])]);

        return $ids === [] ? null : array_values(array_unique($ids));
    }

    private function buildStatement(array $validated): array
    {
        return $this->statement->build(
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            $this->statementDepartmentIds($validated),
            isset($validated['employee_ids']) ? array_map('intval', $validated['employee_ids']) : null,
        );
    }

    /** A 422 when people were picked but none of them is an employee here. */
    private function nobodyChosen(array $statement, array $validated): ?Response
    {
        if (! empty($validated['employee_ids']) && $statement['employees'] === []) {
            $message = 'None of the chosen people were found.';

            return ApiResponse::error($message, ['employee_ids' => [$message]], 422);
        }

        return null;
    }

    /**
     * The date rules every period report shares. No future days: a work day that has
     * not happened has no punches yet, and the summary would count it as an absence.
     */
    private function periodRules(): array
    {
        $today = now(config('attendance.timezone'))->toDateString();

        return [
            'from' => ['nullable', 'date', 'before_or_equal:'.$today],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'before_or_equal:'.$today],
        ];
    }

    /** A 422 when the resolved range is longer than a report renders in reasonable time. */
    private function rangeTooLong(array $validated): ?Response
    {
        [$fromDate, $toDate] = AttendanceSummaryService::window($validated['from'] ?? null, $validated['to'] ?? null);
        if ($fromDate->copy()->startOfDay()->diffInDays($toDate->copy()->startOfDay()) + 1 > self::MAX_REPORT_DAYS) {
            return ApiResponse::error('Choose a range of a year or less.', ['to' => ['Choose a range of a year or less.']], 422);
        }

        return null;
    }

    /** Shared rendering: the tenant, timezone and generation time, page-numbered. */
    private function render(string $view, array $data, string $filename): Response
    {
        return $this->pdf->make($view, $data, auth()->user()?->tenant?->name)->download($filename);
    }

    /** "1 Sep 2026 – 14 Sep 2026", or an open/absent end stated as such. */
    private function rangeLabel(?string $from, ?string $to): string
    {
        $fmt = fn (string $d) => Carbon::parse($d)->format('j M Y');

        return match (true) {
            $from && $to => $fmt($from).' – '.$fmt($to),
            (bool) $from => 'From '.$fmt($from),
            (bool) $to => 'Up to '.$fmt($to),
            default => 'All dates',
        };
    }

    /** A filename that says what is in the file without being opened. */
    private function filename(string $prefix, array $filters): string
    {
        $parts = array_filter([
            $prefix,
            $filters['from'] ?? null,
            $filters['to'] ?? null,
        ]);

        if (count($parts) === 1) {
            $parts[] = now(config('attendance.timezone'))->format('Y-m-d');
        }

        return implode('_', $parts).'.pdf';
    }
}
