<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Services\Integration\PunchWebhookRelay;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Punches for outside systems, as a FEED rather than a screen's page.
 *
 * The SPA pages newest-first with offsets, which is wrong for a consumer that wants
 * "everything since I last asked": rows that arrive while it pages shift every offset.
 * This is keyed on the row id instead — `after_id=N` returns ids greater than N, oldest
 * first, and `meta.next_after_id` is the cursor to send next time. Ids only grow, so it
 * never skips or repeats a row, and a punch that was synced late (an old timestamp, a new
 * id) is still delivered. Persist the cursor; that is the whole protocol.
 *
 * Timestamps are UTC ISO-8601 (rule 10). A bare date in `from`/`to` is an OFFICE-LOCAL
 * day, the same convention as the SPA; a full timestamp is taken as given.
 */
class AttendanceController extends Controller
{
    private const MAX_LIMIT = 1000;

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('attendance.view'), 403, 'This action is unauthorized.');

        $f = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'employee_id' => ['nullable', 'integer'],
            'external_id' => ['nullable', 'string', 'max:128'],
            'device_id' => ['nullable', 'integer'],
            'matched' => ['nullable', 'boolean'],
        ]);

        $limit = (int) ($f['limit'] ?? 200);

        $employeeId = $f['employee_id'] ?? null;
        if (isset($f['external_id'])) {
            // An unknown external id matches nothing; it must not widen to everyone.
            $employeeId = Employee::query()->where('external_id', $f['external_id'])->value('id') ?? 0;
        }

        $rows = AttendanceLog::query()
            ->with(['employee:id,name,employee_code,external_id', 'device:id,name'])
            ->where('id', '>', (int) ($f['after_id'] ?? 0))
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId))
            ->when(isset($f['device_id']), fn ($q) => $q->where('device_id', $f['device_id']))
            ->when(isset($f['from']), fn ($q) => $q->where('punched_at', '>=', $this->boundary($f['from'], false)))
            ->when(isset($f['to']), fn ($q) => $q->where('punched_at', '<=', $this->boundary($f['to'], true)))
            ->when($request->has('matched'), fn ($q) => $request->boolean('matched')
                ? $q->whereNotNull('employee_id')
                : $q->whereNull('employee_id'))
            ->orderBy('id')
            ->limit($limit + 1) // one extra row says whether there is more, without a COUNT
            ->get();

        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return ApiResponse::success(
            $rows->map(fn (AttendanceLog $log) => PunchWebhookRelay::punch($log))->values(),
            'Attendance retrieved.',
            [
                'count' => $rows->count(),
                'has_more' => $more,
                // Send this back as `after_id`. Null only when nothing matched at all, in
                // which case keep the cursor you already had.
                'next_after_id' => $rows->last()?->id,
            ],
        );
    }

    /**
     * A date-only string is an office-local day (start or end of it); anything with a
     * time is an instant. Both end up as UTC for the UTC column.
     */
    private function boundary(string $value, bool $end): Carbon
    {
        $hasTime = (bool) preg_match('/\d[T\s]\d|\d:\d/', $value);
        $parsed = Carbon::parse($value, config('attendance.timezone'));

        if (! $hasTime) {
            $parsed = $end ? $parsed->endOfDay() : $parsed->startOfDay();
        }

        return $parsed->utc();
    }
}
