<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportExport;
use App\Services\Attendance\AttendanceSummaryService;
use App\Services\Reports\EmployeeReportsExport;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Report files built in the background (2026-10-18): today, every employee's report
 * for one period as one PDF. Start one, watch it, download it.
 *
 * Same permission as the Reports screen (`reports.view`) on every action — the file
 * holds every employee's attendance. Rows are tenant-scoped by BelongsToTenant, so a
 * company never sees, or downloads, another's.
 */
class ReportExportController extends Controller
{
    /** Longest period, as for the synchronous reports. */
    private const MAX_DAYS = 366;

    /** Older than this, a run still "running" is presumed dead and stops blocking a new one. */
    private const STALE_MINUTES = 60;

    public function __construct(private readonly EmployeeReportsExport $exports) {}

    /** The most recent runs, newest first. */
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        $runs = ReportExport::query()->with('user:id,name')->latest('id')->limit(8)->get();

        return ApiResponse::success($runs->map(fn ($r) => EmployeeReportsExport::present($r))->all(), 'Report exports.');
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        // No future days: an unhappened work day would be counted as an absence.
        $today = now(config('attendance.timezone'))->toDateString();
        $validated = $request->validate([
            'from' => ['required', 'date', 'before_or_equal:'.$today],
            'to' => ['required', 'date', 'after_or_equal:from', 'before_or_equal:'.$today],
            'department_id' => ['nullable', 'integer'],
        ]);

        [$from, $to] = AttendanceSummaryService::window($validated['from'], $validated['to']);
        if ($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1 > self::MAX_DAYS) {
            return ApiResponse::error('Choose a range of a year or less.', ['to' => ['Choose a range of a year or less.']], 422);
        }

        if (! EmployeeReportsExport::canJoin()) {
            return ApiResponse::error('This server cannot join PDFs (pdfunite, from poppler-utils, is not installed).', [], 503);
        }

        // One at a time per company: they share the single queue worker with the terminals.
        $busy = ReportExport::query()
            ->whereIn('status', [ReportExport::STATUS_QUEUED, ReportExport::STATUS_RUNNING])
            ->where('created_at', '>=', now()->subMinutes(self::STALE_MINUTES))
            ->exists();
        if ($busy) {
            return ApiResponse::error('Another set of employee reports is still being built. Wait for it to finish.', [], 409);
        }

        $export = $this->exports->start(
            $request->user(),
            $validated['from'],
            $validated['to'],
            isset($validated['department_id']) ? (int) $validated['department_id'] : null,
        );

        if ($export->total === 0) {
            $export->forceFill(['status' => ReportExport::STATUS_FAILED, 'finished_at' => now(), 'message' => 'Nobody to report on: no tracked active employee in that department for those dates.'])->save();

            return ApiResponse::error($export->message, [], 422);
        }

        return ApiResponse::success(EmployeeReportsExport::present($export->load('user:id,name')), "Building {$export->total} reports.", [], 202);
    }

    public function show(Request $request, ReportExport $reportExport): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        return ApiResponse::success(EmployeeReportsExport::present($reportExport->load('user:id,name')), 'Report export.');
    }

    public function download(Request $request, ReportExport $reportExport): Response
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        $disk = Storage::disk('local');
        if ($reportExport->status !== ReportExport::STATUS_DONE || ! $reportExport->file_path || ! $disk->exists($reportExport->file_path)) {
            return ApiResponse::error('That file is not available — it is not finished, or it was cleared after '.ReportExport::KEEP_DAYS.' days.', [], 404);
        }

        return $disk->download($reportExport->file_path, $reportExport->filename, ['Content-Type' => 'application/pdf']);
    }

    /**
     * Removes one file before its seven days are up. Not while it is being built: the job
     * would write into a folder that no longer exists and fail with a disk error.
     */
    public function destroy(Request $request, ReportExport $reportExport): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        if (in_array($reportExport->status, [ReportExport::STATUS_QUEUED, ReportExport::STATUS_RUNNING], true)
            && $reportExport->created_at->gte(now()->subMinutes(self::STALE_MINUTES))) {
            return ApiResponse::error('That file is still being built. Delete it once it has finished.', [], 409);
        }

        EmployeeReportsExport::remove($reportExport);

        return ApiResponse::success(null, 'Report file deleted.');
    }

    private function deny(Request $request): ?JsonResponse
    {
        return $request->user()->can('reports.view') ? null : ApiResponse::error('This action is unauthorized.', [], 403);
    }
}
