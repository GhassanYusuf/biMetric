<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceLogResource;
use App\Models\DeviceRosterEntry;
use App\Services\Attendance\PunchLogQuery;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Employees may only ever see their own punches; everyone else needs
        // the tenant-wide attendance.view permission. Both rules, and the
        // filtering, live in PunchLogQuery so the PDF export applies exactly the
        // same ones — see ExportController::attendanceLog().
        $user = $request->user();

        if (! PunchLogQuery::allowed($user)) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        }

        $filters = $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'device_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $logs = PunchLogQuery::build($user, PunchLogQuery::selfOnly($user), $filters)
            ->with(['employee:id,name', 'device:id,name'])
            ->paginate($request->integer('per_page', 50));

        $this->attachTerminalNames($logs->getCollection());

        return ApiResponse::success(AttendanceLogResource::collection($logs), 'Attendance retrieved.');
    }

    /**
     * For a punch linked to NO employee, the name the TERMINAL has for that PIN, from
     * the last roster read — so the row can say "ali-test" instead of a bare
     * "Unmatched". It is the device's label, not an identity: the resource sends it
     * separately from `employee_name` and the screen marks it "not linked" (rule 10).
     * One query per page, for the unmatched rows only.
     */
    private function attachTerminalNames(Collection $logs): void
    {
        $unmatched = $logs->whereNull('employee_id');
        if ($unmatched->isEmpty()) {
            return;
        }

        $entries = DeviceRosterEntry::query()
            ->whereIn('device_id', $unmatched->pluck('device_id')->unique())
            ->whereIn('device_user_id', $unmatched->pluck('device_user_id')->unique())
            ->get(['device_id', 'device_user_id', 'device_name', 'claim_status'])
            ->keyBy(fn ($e) => $e->device_id.'|'.$e->device_user_id);

        foreach ($unmatched as $log) {
            $entry = $entries->get($log->device_id.'|'.$log->device_user_id);
            $log->setAttribute('terminal_user_name', $entry?->device_name);
            $log->setAttribute('terminal_user_status', $entry?->claim_status);
        }
    }
}
