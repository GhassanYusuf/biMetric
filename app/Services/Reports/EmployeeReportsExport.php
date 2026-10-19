<?php

namespace App\Services\Reports;

use App\Jobs\BuildEmployeeReports;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Attendance\AttendanceSummaryService;
use App\Services\Attendance\EmployeeReportService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Starts the bulk export: every employee's PersonalReport for one period, as ONE PDF
 * (owner's choice, 2026-10-18 — a contents page, then each person on new pages).
 *
 * Here: decide WHO is in it and freeze that list on the row. BuildEmployeeReports
 * renders them a few at a time and joins the files with `pdfunite` (poppler-utils).
 *
 * Who: active employees in the department (and everything beneath it) or the whole
 * company, ordered by department then name — the order of the contents page. A
 * person never tracked by the end of the period (no hire date, no punch by then) has
 * no day to report on; they are left out and NAMED on the contents page rather than
 * given three pages of blanks.
 */
class EmployeeReportsExport
{
    public function __construct(private readonly AttendanceSummaryService $summaries) {}

    /** Whether this server can join PDFs at all. Checked before a run is accepted. */
    public static function canJoin(): bool
    {
        return (new ExecutableFinder)->find('pdfunite') !== null;
    }

    public function start(User $user, ?string $from, ?string $to, ?int $departmentId): ReportExport
    {
        self::prune();

        [$fromDate, $toDate] = AttendanceSummaryService::window($from, $to);
        $to = $toDate->format('Y-m-d');
        $departments = Department::query()->with('parent')->get()->keyBy('id');
        $scope = $departmentId !== null ? Department::subtreeIds($departmentId) : null;

        $employees = Employee::query()
            ->where('is_active', true)
            ->when($scope !== null, fn ($q) => $q->whereIn('department_id', $scope))
            ->get();

        $firstPunch = $employees->isEmpty() ? collect() : AttendanceLog::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->groupBy('employee_id')
            ->selectRaw('employee_id, min(punched_at) as first_punch')
            ->pluck('first_punch', 'employee_id');

        $label = fn (Employee $e) => ($departments[$e->department_id] ?? null)?->path() ?? ($e->department ?: 'Unassigned');
        [$tracked, $untracked] = $employees
            ->sortBy([fn ($a, $b) => $label($a) <=> $label($b), fn ($a, $b) => strcasecmp($a->fullName(), $b->fullName())])
            ->partition(function (Employee $e) use ($firstPunch, $to) {
                $from = EmployeeReportService::trackingFromValues($e->hired_date, $firstPunch[$e->id] ?? null);

                return $from !== null && $from <= $to;
            });

        $export = ReportExport::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'kind' => ReportExport::KIND_EMPLOYEE_REPORTS,
            'params' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $to,
                'department_id' => $departmentId,
                'scope' => $departmentId !== null ? (($departments[$departmentId] ?? null)?->path() ?? "#{$departmentId}") : null,
                'employee_ids' => $tracked->pluck('id')->values()->all(),
                'not_tracked' => $untracked->map(fn ($e) => ['name' => $e->fullName(), 'employee_code' => $e->employee_code, 'department' => $label($e)])->values()->all(),
            ],
            'status' => ReportExport::STATUS_QUEUED,
            'total' => $tracked->count(),
            'manifest' => [],
            'message' => 'Waiting to start.',
        ]);

        BuildEmployeeReports::dispatch($export->id);

        return $export;
    }

    /** The shape the Reports screen lists. */
    public static function present(ReportExport $export): array
    {
        $params = $export->params ?? [];

        return [
            'id' => $export->id,
            'status' => $export->status,
            'is_finished' => $export->isFinished(),
            'from' => $params['from'] ?? null,
            'to' => $params['to'] ?? null,
            'scope' => $params['scope'] ?? null,
            'total' => $export->total,
            'done' => $export->done,
            'message' => $export->message,
            'filename' => $export->filename,
            'file_size' => $export->file_size,
            'requested_by' => $export->user?->name,
            'created_at' => $export->created_at?->toIso8601String(),
            'finished_at' => $export->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Clears exports older than KEEP_DAYS, files first, for every company.
     *
     * Runs when a new export starts AND daily from the scheduler (2026-10-19). Run only
     * on start, a company that stopped exporting kept its files for ever, and the screen
     * promised "Kept 7 days" while holding every employee's attendance on disk past it.
     * Age is the whole rule, so no tenant is needed — hence withoutGlobalScopes.
     *
     * @return int how many were cleared
     */
    public static function prune(): int
    {
        $old = ReportExport::withoutGlobalScopes()
            ->where('created_at', '<', now()->subDays(ReportExport::KEEP_DAYS))
            ->get();

        $old->each(fn (ReportExport $export) => self::remove($export));

        return $old->count();
    }

    /** One export gone: its folder first, then the row, so a row never points at nothing. */
    public static function remove(ReportExport $export): void
    {
        Storage::disk('local')->deleteDirectory($export->directory());
        $export->delete();
    }
}
