<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\ClientErrorController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceEnrollmentController;
use App\Http\Controllers\Api\DeviceGroupController;
use App\Http\Controllers\Api\DeviceReconciliationController;
use App\Http\Controllers\Api\DeviceSyncController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\WaiverController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (JSON, versioned under /api)
|--------------------------------------------------------------------------
| Business endpoints only. All authenticated routes run `auth:sanctum` then
| `tenant` (ResolveTenant) so the acting tenant is established before any
| controller/service runs. Domain modules are added in Phase 4.
|
| CRITICAL: every `apiResource` here MUST pass `->names('api.<resource>')`.
| Left to itself it registers `employees.index/store/update/destroy` — the exact
| names routes/web.php already uses for the legacy Inertia pages. Ziggy resolves
| a duplicated name to whichever route registered FIRST, and withRouting()
| registers api.php BEFORE web.php, so every `route('employees.store')` in the
| Inertia app silently pointed at /api/employees. That request carries no bearer
| token, so `auth:sanctum` returned the 401 JSON envelope and Inertia died with
| "All Inertia requests must receive a valid Inertia response".
|
| Do NOT fix this with a group-level `->name('api.')`: Laravel applies a group
| name prefix to UNNAMED routes too, so every plain Route::get/post below would
| be registered under the single bogus name `api.`.
*/

Route::prefix('auth')->group(function () {
    // Rate-limited to blunt credential-stuffing / brute force.
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

/*
| Browser crash reports from the SPA's ErrorBoundary and global handlers.
|
| Outside the `tenant` group on purpose: the screen that crashed may be the one
| that was resolving the tenant, and an error report that itself 500s tells us
| nothing. auth:sanctum still applies, so reports are attributable.
|
| Throttled hard. A React render error can re-throw every frame, and an
| unthrottled reporter turns one broken component into a self-inflicted flood.
*/
Route::middleware(['auth:sanctum', 'throttle:20,1'])
    ->post('client-errors', [ClientErrorController::class, 'store']);

// All tenant-scoped domain endpoints live behind auth + tenant resolution.
// Per-route `permission:` middleware is a fast first gate; controllers/policies
// enforce the same permissions again (defence in depth).
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {

    // Departments
    Route::apiResource('departments', DepartmentController::class)->names('api.departments');

    // Employees + attendance summary + device enrollment actions
    // Must precede the resource: `employees/next-code` would otherwise be matched
    // by `employees/{employee}` and 404 on model binding.
    Route::get('employees/next-code', [EmployeeController::class, 'nextCode']);

    Route::apiResource('employees', EmployeeController::class)->names('api.employees');
    Route::get('employees/{employee}/attendance-summary', [EmployeeController::class, 'attendanceSummary']);
    Route::post('employees/{employee}/devices/{device}/push', [EmployeeController::class, 'pushToDevice']);
    Route::post('employees/{employee}/devices/{device}/remove', [EmployeeController::class, 'removeFromDevice']);

    // Live read of one employee's enrollment (which finger slots, which card).
    Route::get('employees/{employee}/devices/{device}/enrollment', [EmployeeController::class, 'enrollment']);

    // Live capture on the terminal. Both block while a person interacts with the
    // hardware, so they sit outside the default throttle expectations.
    Route::post('employees/{employee}/devices/{device}/enroll-fingerprint', [EmployeeController::class, 'enrollFingerprint']);
    Route::post('employees/{employee}/devices/{device}/enroll-card', [EmployeeController::class, 'enrollCard']);

    // Removes one fingerprint slot, leaving the employee's other fingers and card.
    Route::delete('employees/{employee}/devices/{device}/fingerprints/{slot}', [EmployeeController::class, 'deleteFingerprint'])
        ->whereNumber('slot');

    // Clears the card, leaving the record and fingerprints intact.
    Route::delete('employees/{employee}/devices/{device}/card', [EmployeeController::class, 'deleteCard']);

    // Devices + operations
    // Read a device's identity before it is saved, so the add form can auto-select
    // the model. Declared before the resource so it is not read as devices/{device}.
    Route::post('devices/identify', [DeviceController::class, 'identify']);
    Route::apiResource('devices', DeviceController::class)->names('api.devices');
    Route::post('devices/{device}/test-connection', [DeviceController::class, 'testConnection']);
    Route::post('devices/{device}/sync-attendance', [DeviceController::class, 'syncAttendance']);
    // Polled roughly once a second by a progress bar while a sync is running.
    // Kept read-only and deliberately cheap — see DeviceSyncController::syncProgress.
    Route::get('devices/{device}/sync-progress', [DeviceSyncController::class, 'syncProgress']);
    Route::post('devices/{device}/sync-enrollment', [DeviceController::class, 'syncEnrollment']);

    /*
    |----------------------------------------------------------------------------
    | Device groups
    |----------------------------------------------------------------------------
    | Membership is a pivot, so a terminal can belong to a site AND a function.
    | A group command queues ONE command per member, not one for the group, so a
    | half-successful run stays legible per device.
    */
    Route::apiResource('device-groups', DeviceGroupController::class)
        ->parameters(['device-groups' => 'deviceGroup'])
        ->names('api.device-groups');
    Route::put('device-groups/{deviceGroup}/devices', [DeviceGroupController::class, 'syncDevices']);
    Route::post('device-groups/{deviceGroup}/devices/{device}', [DeviceGroupController::class, 'addDevice']);
    Route::delete('device-groups/{deviceGroup}/devices/{device}', [DeviceGroupController::class, 'removeDevice']);
    Route::post('device-groups/{deviceGroup}/commands', [DeviceGroupController::class, 'dispatchCommand']);

    /*
    |----------------------------------------------------------------------------
    | Sync history, command queue, health history
    |----------------------------------------------------------------------------
    | Device work is QUEUED. These endpoints return a command id; the outcome
    | arrives over MQTT or by polling the command. Nothing here blocks on hardware.
    */
    Route::get('device-sync-runs', [DeviceSyncController::class, 'runs']);
    Route::get('device-sync-runs/{run}', [DeviceSyncController::class, 'run']);
    // Erases run history (and the raw payloads those runs archived) for the same
    // device_id/status filter the listing takes. Never touches imported punches,
    // the incremental watermark, or a run that is still in progress.
    Route::delete('device-sync-runs', [DeviceSyncController::class, 'clearRuns']);
    Route::get('device-commands', [DeviceSyncController::class, 'commands']);
    Route::get('device-commands/{command}', [DeviceSyncController::class, 'command']);
    Route::post('device-commands/{command}/retry', [DeviceSyncController::class, 'retry']);
    Route::post('device-commands/{command}/cancel', [DeviceSyncController::class, 'cancel']);
    Route::post('devices/{device}/commands', [DeviceSyncController::class, 'dispatchCommand']);
    Route::get('devices/{device}/status-history', [DeviceSyncController::class, 'statusHistory']);

    /*
    |----------------------------------------------------------------------------
    | Enrollment sessions
    |----------------------------------------------------------------------------
    | Captures block on a human at the terminal, so they are queued and tracked as
    | a durable session. Progress streams on tenant/{id}/module/enrollment/{id}.
    */
    Route::get('enrollment-sessions', [DeviceEnrollmentController::class, 'index']);
    Route::get('enrollment-sessions/{session}', [DeviceEnrollmentController::class, 'show']);
    Route::post('enrollment-sessions/{session}/cancel', [DeviceEnrollmentController::class, 'cancel']);
    Route::post('employees/{employee}/devices/{device}/enrollment/fingerprint', [DeviceEnrollmentController::class, 'fingerprint']);
    Route::post('employees/{employee}/devices/{device}/enrollment/card', [DeviceEnrollmentController::class, 'card']);
    Route::delete('employees/{employee}/devices/{device}/enrollment/fingerprints/{slot}', [DeviceEnrollmentController::class, 'deleteFingerprint'])
        ->whereNumber('slot');
    Route::delete('employees/{employee}/devices/{device}/enrollment/card', [DeviceEnrollmentController::class, 'deleteCard']);

    /*
    |----------------------------------------------------------------------------
    | Reconciliation
    |----------------------------------------------------------------------------
    | READ-ONLY reporting. Auto-resolving would mean deciding whose version wins,
    | which either deletes a real enrollment or restores a departed employee.
    */
    Route::get('device-maps', [DeviceReconciliationController::class, 'index']);
    Route::get('device-maps/summary', [DeviceReconciliationController::class, 'summary']);
    Route::post('devices/{device}/reconcile', [DeviceReconciliationController::class, 'reconcile']);
    Route::post('devices/{device}/reconcile/import-unknown', [DeviceReconciliationController::class, 'importUnknown']);
    Route::post('devices/{device}/reconcile/employees/{employee}/push', [DeviceReconciliationController::class, 'pushEmployee']);
    Route::post('devices/{device}/import-employees', [DeviceController::class, 'importEmployees']);

    // Attendance punch log + waivers
    Route::get('attendance', [AttendanceController::class, 'index']);
    Route::post('employees/{employee}/waivers', [WaiverController::class, 'toggle']);

    // Holidays (index/store/update/destroy — no individual show needed)
    Route::apiResource('holidays', HolidayController::class)->except('show')->names('api.holidays');

    // Dashboard + reports
    Route::get('dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('reports/attendance', [ReportController::class, 'attendance']);

    /*
    | PDF exports. Each one re-applies the permission rule of the screen it
    | mirrors (ExportController documents why), so these need no permission
    | middleware of their own beyond auth + tenant.
    */
    Route::get('exports/attendance', [ExportController::class, 'attendanceLog']);
    Route::get('exports/employees/{employee}/attendance', [ExportController::class, 'employeeAttendance']);
    Route::get('exports/payroll', [ExportController::class, 'payrollSummary']);

    // Audit trail
    Route::get('audit-logs', [AuditLogController::class, 'index']);

    // Notifications (per-user)
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
});
