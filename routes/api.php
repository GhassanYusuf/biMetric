<?php

use App\Http\Controllers\Api\AccessLevelController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\ClientErrorController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DeviceAdoptionController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceEnrollmentController;
use App\Http\Controllers\Api\DeviceGroupController;
use App\Http\Controllers\Api\DeviceReconciliationController;
use App\Http\Controllers\Api\DeviceSyncController;
use App\Http\Controllers\Api\DeviceTimezoneController;
use App\Http\Controllers\Api\DuplicateScanController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\LegacyDataController;
use App\Http\Controllers\Api\LiveController;
use App\Http\Controllers\Api\MultiCardGroupController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReportExportController;
use App\Http\Controllers\Api\SettingController;
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

    // "What changed?" stamps per module — the live fallback when MQTT is down (rule 18).
    Route::get('live', [LiveController::class, 'index']);

    // Company settings, allow-listed (TenantSettings::DEFAULTS).
    Route::get('settings', [SettingController::class, 'index']);
    Route::put('settings', [SettingController::class, 'update']);

    /*
     | Time zones and device clocks (2026-10-05). The plan and the clock check are
     | read-only; only `apply` queues the hardware write, and it goes through the
     | command bus rather than running inline.
     */
    Route::get('settings/timezones', [DeviceTimezoneController::class, 'plan']);
    Route::post('settings/timezones/check', [DeviceTimezoneController::class, 'check']);
    Route::get('settings/attendance-schedule', [DeviceTimezoneController::class, 'schedule']);
    Route::post('settings/timezones/apply', [DeviceTimezoneController::class, 'apply']);

    // Access levels + multi-card groups for the employee form. Database only —
    // nothing here writes to a panel (2026-09-23).
    Route::apiResource('access-levels', AccessLevelController::class)->except('show')->names('api.access-levels');
    Route::apiResource('multi-card-groups', MultiCardGroupController::class)->except('show')->names('api.multi-card-groups');

    // Employees + attendance summary + device enrollment actions
    // Must precede the resource: `employees/next-code` would otherwise be matched
    // by `employees/{employee}` and 404 on model binding.
    Route::get('employees/next-code', [EmployeeController::class, 'nextCode']);

    /*
     * Duplicate scanning (2026-10-06). `duplicate-groups` is a fixed path with no
     * employee in it, so it MUST precede the resource below or it is swallowed by
     * `employees/{employee}` and bound as an employee id — the same trap the
     * next-code route above already documents. All three are reads.
     */
    Route::get('employees/duplicate-groups', [DuplicateScanController::class, 'index']);
    Route::post('employees/merge/preview', [DuplicateScanController::class, 'preview']);

    Route::apiResource('employees', EmployeeController::class)->names('api.employees');
    Route::get('employees/{employee}/duplicates', [DuplicateScanController::class, 'forEmployee']);
    Route::get('employees/{employee}/attendance-summary', [EmployeeController::class, 'attendanceSummary']);
    Route::get('employees/{employee}/report', [EmployeeController::class, 'report']);
    Route::post('employees/{employee}/photo', [EmployeeController::class, 'uploadPhoto']);
    Route::delete('employees/{employee}/photo', [EmployeeController::class, 'removePhoto']);
    /*
     * Writes one employee onto SEVERAL terminals, queued one command each. What the
     * add/edit form calls after saving; the single-device route below stays for the
     * enrollment dialog, which pushes as a precondition for capturing a finger and
     * needs the answer in the same breath.
     */
    Route::post('employees/{employee}/propagate', [EmployeeController::class, 'propagate']);
    /*
     * Folds duplicate employment records into one. It addresses no single employee —
     * the keeper and the records folded in are all in the body — so it is a POST to a
     * fixed path. No collision with the resource: that registers `POST employees`
     * (store) and nothing of the shape `POST employees/{something}`. Verified in
     * route:list, not assumed.
     */
    Route::post('employees/merge', [EmployeeController::class, 'merge']);
    Route::post('employees/{employee}/devices/{device}/push', [EmployeeController::class, 'pushToDevice']);
    Route::post('employees/{employee}/devices/{device}/remove', [EmployeeController::class, 'removeFromDevice']);
    Route::put('employees/{employee}/devices/{device}/privilege', [EmployeeController::class, 'setDevicePrivilege']);
    // Frees a number held on the terminal by a record linked to no employee, for this
    // employee — record and fingerprints saved first (casebook DC-014).
    Route::post('employees/{employee}/devices/{device}/release-number', [EmployeeController::class, 'releaseNumber']);

    // Reads their fingerprints from the terminals they are on into the system's
    // encrypted copy. Read-only on the terminals (FingerprintVault, 2026-09-24).
    Route::post('employees/{employee}/fingerprints/read', [EmployeeController::class, 'readFingerprints']);

    // Writes their stored fingerprints to terminals missing them — how a finger enrolled
    // on the F18 reaches the C3 panels, which cannot capture one (2026-09-24). Add-only.
    Route::post('employees/{employee}/fingerprints/push', [EmployeeController::class, 'pushFingerprints']);

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
    // Writes a typed card number, the alternative to waiting for a tap.
    Route::put('employees/{employee}/devices/{device}/card', [EmployeeController::class, 'assignCard']);

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
    // First fingerprint write on a model that never did one; add-only (rule 21).
    Route::post('devices/{device}/fingerprint-write-test', [DeviceController::class, 'fingerprintWriteTest']);
    // "Rebuild from system": preview (read-only), then the queued chain (DeviceRebuildService).
    Route::get('devices/{device}/rebuild-plan', [DeviceController::class, 'rebuildPlan']);
    Route::post('devices/{device}/rebuild', [DeviceController::class, 'rebuild']);

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
    Route::post('devices/{device}/clear-attendance', [DeviceSyncController::class, 'clearAttendance']);
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
    /*
    |----------------------------------------------------------------------------
    | Adoption — bringing an EXISTING terminal under management
    |----------------------------------------------------------------------------
    | A device is read-only until its roster has been read and every person already
    | on it has been claimed or ignored. Pushing to an unadopted terminal is what
    | overwrote real enrollments on 2026-09-15; the gate is Device::assertWritable().
    |
    | `read` contacts the hardware and is read-only. `claim` and `ignore` record a
    | human's decision and write nothing to the device. Only `adopt` changes what the
    | system is allowed to do afterwards.
    */
    Route::get('devices/{device}/roster', [DeviceAdoptionController::class, 'index']);
    Route::post('devices/{device}/roster/read', [DeviceAdoptionController::class, 'read']);
    Route::post('devices/{device}/roster/{entry}/claim', [DeviceAdoptionController::class, 'claim']);
    Route::post('devices/{device}/roster/{entry}/ignore', [DeviceAdoptionController::class, 'ignore']);
    Route::post('devices/{device}/adopt', [DeviceAdoptionController::class, 'adopt']);

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
    Route::get('holidays/rules', [HolidayController::class, 'rules']);
    Route::put('holidays/rules', [HolidayController::class, 'updateRules']);
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
    Route::get('exports/attendance-report', [ExportController::class, 'attendanceReport']);
    Route::get('exports/monthly-statement', [ExportController::class, 'monthlyStatement']);
    Route::get('exports/monthly-statement/excel', [ExportController::class, 'monthlyStatementExcel']);
    Route::get('exports/employees/{employee}/report', [ExportController::class, 'employeeReport']);
    // Every employee's report for a period, as one PDF built in the background.
    Route::get('exports/employee-reports', [ReportExportController::class, 'index']);
    Route::post('exports/employee-reports', [ReportExportController::class, 'store']);
    Route::get('exports/employee-reports/{reportExport}', [ReportExportController::class, 'show']);
    Route::get('exports/employee-reports/{reportExport}/download', [ReportExportController::class, 'download']);
    Route::delete('exports/employee-reports/{reportExport}', [ReportExportController::class, 'destroy']);

    // Audit trail
    Route::get('audit-logs', [AuditLogController::class, 'index']);

    /*
    |----------------------------------------------------------------------------
    | Legacy ZKAccess import and backup snapshot
    |----------------------------------------------------------------------------
    | Import is deliberate two-step: preview stores the upload as canonical JSON
    | and dry-runs it, apply replays exactly those bytes. Export downloads the
    | current state in the same canonical shape so a backup can be re-imported.
    | Both are gated on Employee policy abilities inside the controller.
    */
    Route::post('legacy/import/preview', [LegacyDataController::class, 'previewImport']);
    Route::post('legacy/import/{token}/apply', [LegacyDataController::class, 'applyImport']);
    Route::get('legacy/export', [LegacyDataController::class, 'exportSnapshot']);

    // Notifications (per-user)
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
});

// Integration API (/api/v1) — kept in its own file so its auth, throttle and idempotency
// middleware are declared once. See routes/api_v1.php.
require __DIR__.'/api_v1.php';
