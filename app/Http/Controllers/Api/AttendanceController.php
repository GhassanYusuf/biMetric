<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceLogResource;
use App\Services\Attendance\PunchLogQuery;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return ApiResponse::success(AttendanceLogResource::collection($logs), 'Attendance retrieved.');
    }
}
