<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Attendance\DashboardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function summary(Request $request): JsonResponse
    {
        // `days` drives the trend chart only; the KPI tiles are always "today"
        // and are labelled as such, so the two never contradict each other.
        $validated = $request->validate([
            'days' => ['nullable', 'integer', Rule::in(DashboardService::RANGES)],
        ]);

        $days = (int) ($validated['days'] ?? 7);

        return ApiResponse::success([
            'kpis' => $this->dashboard->summary(),
            'punch_series' => $this->dashboard->punchSeries($days),
            'recent_activity' => $this->dashboard->recentActivity(),
        ], 'Dashboard summary generated.', ['range_days' => $days]);
    }
}
