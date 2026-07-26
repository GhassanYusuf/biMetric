<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceLogResource;
use App\Models\AttendanceLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Employees may only ever see their own punches; everyone else needs
        // the tenant-wide attendance.view permission.
        $user = $request->user();
        $selfOnly = ! $user->can('attendance.view');

        if ($selfOnly && ! $user->can('attendance.view.self')) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        }

        $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'device_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $logs = AttendanceLog::query()
            ->with(['employee:id,name', 'device:id,name'])
            ->when($selfOnly, fn ($q) => $q->where('employee_id', $user->employee_id))
            ->when($request->filled('employee_id') && ! $selfOnly, fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->integer('device_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('punched_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('punched_at', '<=', $request->date('to')))
            ->latest('punched_at')
            ->paginate($request->integer('per_page', 50));

        return ApiResponse::success(AttendanceLogResource::collection($logs), 'Attendance retrieved.');
    }
}
