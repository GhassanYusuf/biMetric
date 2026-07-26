<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceWaiver;
use App\Models\Employee;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Toggles an attendance waiver (excuses an absence, e.g. approved leave) for
 * one employee on one date. Toggling on notifies the tenant's HR managers so
 * the change is visible without a refresh.
 */
class WaiverController extends Controller
{
    public function toggle(Request $request, Employee $employee, NotificationService $notifications): JsonResponse
    {
        // Reuse the attendance.waive permission for this workflow action.
        if (! $request->user()->can('attendance.waive')) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        }

        $data = $request->validate([
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $existing = AttendanceWaiver::query()
            ->where('employee_id', $employee->id)
            ->where('date', $data['date'])
            ->first();

        if ($existing) {
            $existing->delete();

            return ApiResponse::success(['waived' => false], 'Waiver removed.');
        }

        AttendanceWaiver::create([
            'employee_id' => $employee->id,
            'date' => $data['date'],
            'reason' => $data['reason'] ?? null,
        ]);

        // Alert HR managers (persisted + live). Fail-safe inside the service.
        $notifications->notifyMany(
            User::role('hr-manager')->get(),
            [
                'type' => 'attendance.waived',
                'level' => 'info',
                'title' => 'Absence waived',
                'body' => "{$employee->name} was excused on {$data['date']}"
                    . ($data['reason'] ? " ({$data['reason']})" : ''),
                'data' => ['employee_id' => $employee->id, 'date' => $data['date']],
            ],
        );

        return ApiResponse::success(['waived' => true], 'Day waived.');
    }
}
