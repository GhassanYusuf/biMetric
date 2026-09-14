<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Services\Attendance\AttendanceSummaryService;
use App\Services\Attendance\PunchLogQuery;
use App\Services\Reports\PayrollReport;
use App\Support\ApiResponse;
use App\Support\VerifyMethod;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF exports: the punch log as filtered, one employee's attendance, and the
 * payroll summary.
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
    ) {}

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
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $selfOnly = PunchLogQuery::selfOnly($user);
        $query = PunchLogQuery::build($user, $selfOnly, $filters);

        $total = (clone $query)->count();

        $logs = $query->with(['employee:id,name', 'device:id,name'])
            ->limit(self::MAX_ROWS)
            ->get();

        $tz = config('attendance.timezone');

        $rows = $logs->map(function ($log) use ($tz) {
            // punched_at is stored UTC (rule 10). Every printed time is moved to
            // office-local here, once, and the footer names the zone.
            $local = $log->punched_at?->copy()->setTimezone($tz);

            return [
                'name' => $log->employee?->name,
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
            $filterLines[] = 'Employee: '.(Employee::find($filters['employee_id'])?->name ?? "#{$filters['employee_id']}");
        }
        if (! empty($filters['device_id'])) {
            $filterLines[] = 'Device: '.(Device::find($filters['device_id'])?->name ?? "#{$filters['device_id']}");
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
     * Shared rendering: every PDF gets the tenant, the timezone it is stated in,
     * and when it was produced.
     */
    private function render(string $view, array $data, string $filename): Response
    {
        $tz = config('attendance.timezone');

        $pdf = Pdf::loadView($view, [
            ...$data,
            'tenantName' => auth()->user()?->tenant?->name,
            'timezone' => $tz,
            'generatedAt' => now($tz)->format('Y-m-d g:i A'),
        ])->setPaper('a4');

        return $pdf->download($filename);
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
