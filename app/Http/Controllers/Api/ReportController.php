<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Attendance\AttendanceSummaryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly AttendanceSummaryService $summaries)
    {
    }

    /**
     * Payroll-hours + attendance breakdown for every (optionally filtered)
     * employee across a date range. Tenant-scoped throughout.
     */
    public function attendance(Request $request): JsonResponse
    {
        if (! $request->user()->can('reports.view')) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        }

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'department_id' => ['nullable', 'integer'],
        ]);

        $employees = Employee::query()
            ->where('is_active', true)
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->orderBy('name')
            ->get();

        $rows = $employees->map(function (Employee $employee) use ($validated) {
            $summary = $this->summaries->summary($employee, $validated['from'] ?? null, $validated['to'] ?? null);

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

        return ApiResponse::success([
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'rows' => $rows,
            'totals' => [
                'employees' => $rows->count(),
                'payroll_hours' => round($rows->sum('total_payroll_hours'), 1),
            ],
        ], 'Attendance report generated.');
    }
}
