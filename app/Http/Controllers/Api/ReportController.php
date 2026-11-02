<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reports\PayrollReport;
use App\Support\ApiResponse;
use App\Support\OrgScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly PayrollReport $report) {}

    /**
     * Payroll-hours + attendance breakdown for every (optionally filtered)
     * employee across a date range. Tenant-scoped throughout.
     *
     * The computation lives in PayrollReport so this and the PDF export cannot
     * drift apart — see ExportController::payroll().
     */
    public function attendance(Request $request): JsonResponse
    {
        if (! $request->user()->can('reports.view')) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        }

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            ...OrgScope::rules(),
        ]);

        return ApiResponse::success(
            $this->report->build(
                $validated['from'] ?? null,
                $validated['to'] ?? null,
                OrgScope::from($validated),
            ),
            'Attendance report generated.',
        );
    }
}
