<?php

namespace App\Services\Reports;

use App\Models\Employee;
use App\Services\Attendance\AttendanceSummaryService;
use Illuminate\Support\Collection;

/**
 * Payroll hours and attendance counts for every active employee over a range.
 *
 * Lifted out of ReportController so the JSON endpoint and the PDF export render
 * from ONE computation. Two code paths producing "payroll hours" is how a report
 * and its printout come to disagree, and the printed one is the copy that ends
 * up in somebody's file.
 *
 * Every figure comes from AttendanceSummaryService, which is also what the
 * per-employee screens use, so a total here always reconciles with the days
 * behind it. All queries run under the current tenant scope.
 */
class PayrollReport
{
    public function __construct(private readonly AttendanceSummaryService $summaries) {}

    /**
     * @return array{from:string,to:string,rows:Collection<int,array>,totals:array{employees:int,payroll_hours:float}}
     */
    public function build(?string $from = null, ?string $to = null, ?int $departmentId = null): array
    {
        // Resolve the window FIRST and pass it down explicitly. Two things need
        // it: every employee must be summarised over the same dates, and the
        // caller has to be able to print the range it actually got rather than
        // the empty one it asked with.
        [$fromDate, $toDate] = AttendanceSummaryService::window($from, $to);
        $from = $fromDate->format('Y-m-d');
        $to = $toDate->format('Y-m-d');

        $employees = Employee::query()
            ->where('is_active', true)
            ->when($departmentId !== null, fn ($q) => $q->where('department_id', $departmentId))
            ->orderBy('name')
            ->get();

        $rows = $employees->map(function (Employee $employee) use ($from, $to) {
            $summary = $this->summaries->summary($employee, $from, $to);

            return [
                'employee_id' => $employee->id,
                'name' => $employee->name,
                'employee_code' => $employee->employee_code,
                'department' => $employee->department,
                'total_payroll_hours' => $summary['total_payroll_hours'],
                'total_punches' => $summary['total_punches'],
                'present_days' => $summary['categories']['early'] + $summary['categories']['come_late'],
                'absent_days' => $summary['categories']['absent'],
                'late_days' => $summary['categories']['come_late'],
                'excused_days' => $summary['categories']['excused'],
            ];
        });

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => [
                'employees' => $rows->count(),
                'payroll_hours' => round($rows->sum('total_payroll_hours'), 1),
            ],
        ];
    }
}
