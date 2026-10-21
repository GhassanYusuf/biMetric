<?php

use App\Http\Controllers\Api\AccessLevelController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceEnrollmentController;
use App\Http\Controllers\Api\DeviceSyncController;
use App\Http\Controllers\Api\EmployeeController as SpaEmployeeController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\PingController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Integration API — /api/v1  (2026-10-08, docs/integration-api.md)
|--------------------------------------------------------------------------
| For OTHER SOFTWARE calling this one: an HRMS, ERP or payroll system. Required from
| routes/api.php, so it inherits the `api` prefix and the JSON envelope.
|
| WHAT IS NEW vs WHAT IS REUSED (rule 13). Employees, attendance, webhooks and tokens
| have v1 controllers, because an integration needs things the SPA does not: a caller's
| own id, partial updates, a cursor feed, queued device work reported as command ids,
| signed callbacks. Everything else points at the SAME controller the SPA uses, so
| validation, policies and resources are identical and cannot drift — those routes just
| add an ability check and the integration rate limit.
|
| AUTH. Bearer token (Sanctum). A token needs the route's ability AND its user needs the
| matching permission: a token can only ever narrow a user, never widen one.
|
| EVERY route name starts `api.v1.` — an unprefixed `apiResource` name collides with the
| legacy Inertia routes (see the warning at the top of routes/api.php).
|
| `idempotent` replays the original answer when a write is retried with the same
| `Idempotency-Key` header, so a timed-out POST can be repeated safely.
*/
Route::prefix('v1')->middleware(['auth:sanctum', 'tenant', 'throttle:integration', 'idempotent'])->group(function () {

    Route::get('ping', PingController::class)->name('api.v1.ping');

    // --- Tokens: minted by a person signed in, not by another integration token.
    Route::get('token-abilities', [TokenController::class, 'abilities'])->name('api.v1.tokens.abilities');
    Route::get('tokens', [TokenController::class, 'index'])->name('api.v1.tokens.index');
    Route::post('tokens', [TokenController::class, 'store'])->name('api.v1.tokens.store');
    Route::delete('tokens/{token}', [TokenController::class, 'destroy'])->whereNumber('token')->name('api.v1.tokens.destroy');

    // --- Reference data an employee refers to.
    Route::middleware('abilities:employees:read')->group(function () {
        Route::get('departments', [DepartmentController::class, 'index'])->name('api.v1.departments.index');
        Route::get('departments/{department}', [DepartmentController::class, 'show'])->name('api.v1.departments.show');
        Route::get('access-levels', [AccessLevelController::class, 'index'])->name('api.v1.access-levels.index');
    });
    Route::middleware('abilities:employees:write')->group(function () {
        Route::post('departments', [DepartmentController::class, 'store'])->name('api.v1.departments.store');
        Route::match(['put', 'patch'], 'departments/{department}', [DepartmentController::class, 'update'])->name('api.v1.departments.update');
        Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->name('api.v1.departments.destroy');
    });

    // --- Employees.
    Route::middleware('abilities:employees:read')->group(function () {
        Route::get('employees', [EmployeeController::class, 'index'])->name('api.v1.employees.index');
        Route::get('employees/next-code', [SpaEmployeeController::class, 'nextCode'])->name('api.v1.employees.next-code');
        // Both fixed paths MUST precede `employees/{employee}`.
        Route::get('employees/by-external-id/{externalId}', [EmployeeController::class, 'showByExternalId'])->name('api.v1.employees.show-by-external-id');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->whereNumber('employee')->name('api.v1.employees.show');
    });
    Route::middleware('abilities:employees:write')->group(function () {
        Route::post('employees', [EmployeeController::class, 'store'])->name('api.v1.employees.store');
        Route::put('employees/by-external-id/{externalId}', [EmployeeController::class, 'upsert'])->name('api.v1.employees.upsert');
        Route::match(['put', 'patch'], 'employees/{employee}', [EmployeeController::class, 'update'])->whereNumber('employee')->name('api.v1.employees.update');
        // Personnel ID, system AND terminals (2026-10-21). Fixed path, before `{employee}`.
        Route::delete('employees/by-code/{code}', [EmployeeController::class, 'destroyByCode'])->name('api.v1.employees.destroy-by-code');
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->whereNumber('employee')->name('api.v1.employees.destroy');
        Route::post('employees/{employee}/photo', [SpaEmployeeController::class, 'uploadPhoto'])->name('api.v1.employees.photo.store');
        Route::delete('employees/{employee}/photo', [SpaEmployeeController::class, 'removePhoto'])->name('api.v1.employees.photo.destroy');
    });

    // --- Terminals and device work. Everything here is queued: the response is a
    // command (or session) id to follow, never a promise that the hardware has done it.
    Route::middleware('abilities:devices:read')->group(function () {
        Route::get('devices', [DeviceController::class, 'index'])->name('api.v1.devices.index');
        Route::get('devices/{device}', [DeviceController::class, 'show'])->name('api.v1.devices.show');
        Route::get('device-commands', [DeviceSyncController::class, 'commands'])->name('api.v1.device-commands.index');
        Route::get('device-commands/{command}', [DeviceSyncController::class, 'command'])->name('api.v1.device-commands.show');
        Route::get('device-sync-runs', [DeviceSyncController::class, 'runs'])->name('api.v1.device-sync-runs.index');
        Route::get('device-sync-runs/{run}', [DeviceSyncController::class, 'run'])->name('api.v1.device-sync-runs.show');
        Route::get('enrollment-sessions', [DeviceEnrollmentController::class, 'index'])->name('api.v1.enrollment-sessions.index');
        Route::get('enrollment-sessions/{session}', [DeviceEnrollmentController::class, 'show'])->name('api.v1.enrollment-sessions.show');
    });
    Route::middleware('abilities:devices:write')->group(function () {
        // sync_attendance, sync_enrollment, import_employees, health_check, sync_time …
        Route::post('devices/{device}/commands', [DeviceSyncController::class, 'dispatchCommand'])->name('api.v1.devices.commands.store');
        Route::post('device-commands/{command}/retry', [DeviceSyncController::class, 'retry'])->name('api.v1.device-commands.retry');
        Route::post('device-commands/{command}/cancel', [DeviceSyncController::class, 'cancel'])->name('api.v1.device-commands.cancel');

        Route::post('employees/{employee}/devices/{device}/push', [EmployeeController::class, 'pushToDevice'])->name('api.v1.employees.devices.push');
        Route::post('employees/{employee}/devices/{device}/remove', [EmployeeController::class, 'removeFromDevice'])->name('api.v1.employees.devices.remove');

        // Enrollment: queued, tracked as a session, finishes with an `enrollment.finished` webhook.
        Route::post('employees/{employee}/devices/{device}/enrollment/fingerprint', [DeviceEnrollmentController::class, 'fingerprint'])->name('api.v1.enrollment.fingerprint');
        Route::post('employees/{employee}/devices/{device}/enrollment/card', [DeviceEnrollmentController::class, 'card'])->name('api.v1.enrollment.card');
        Route::delete('employees/{employee}/devices/{device}/enrollment/fingerprints/{slot}', [DeviceEnrollmentController::class, 'deleteFingerprint'])->whereNumber('slot')->name('api.v1.enrollment.fingerprint.destroy');
        Route::delete('employees/{employee}/devices/{device}/enrollment/card', [DeviceEnrollmentController::class, 'deleteCard'])->name('api.v1.enrollment.card.destroy');
        Route::post('enrollment-sessions/{session}/cancel', [DeviceEnrollmentController::class, 'cancel'])->name('api.v1.enrollment-sessions.cancel');

        // SYNCHRONOUS: writes a typed card number and reads it back before answering
        // (the SPA's own endpoint — there is no queued form of it yet). May take seconds
        // and answer 502 if the terminal is unreachable.
        Route::put('employees/{employee}/devices/{device}/card', [SpaEmployeeController::class, 'assignCard'])->name('api.v1.employees.devices.card.assign');
    });

    // --- Attendance.
    Route::middleware('abilities:attendance:read')->group(function () {
        Route::get('attendance', [AttendanceController::class, 'index'])->name('api.v1.attendance.index');
        Route::get('employees/{employee}/attendance-summary', [SpaEmployeeController::class, 'attendanceSummary'])->whereNumber('employee')->name('api.v1.employees.attendance-summary');
        Route::get('reports/attendance', [ReportController::class, 'attendance'])->name('api.v1.reports.attendance');
    });

    // --- Webhooks.
    Route::middleware('abilities:webhooks:manage')->group(function () {
        Route::get('webhook-events', [WebhookController::class, 'events'])->name('api.v1.webhooks.events');
        Route::get('webhooks', [WebhookController::class, 'index'])->name('api.v1.webhooks.index');
        Route::post('webhooks', [WebhookController::class, 'store'])->name('api.v1.webhooks.store');
        Route::get('webhooks/{webhook}', [WebhookController::class, 'show'])->name('api.v1.webhooks.show');
        Route::match(['put', 'patch'], 'webhooks/{webhook}', [WebhookController::class, 'update'])->name('api.v1.webhooks.update');
        Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('api.v1.webhooks.destroy');
        Route::post('webhooks/{webhook}/rotate-secret', [WebhookController::class, 'rotateSecret'])->name('api.v1.webhooks.rotate-secret');
        Route::post('webhooks/{webhook}/test', [WebhookController::class, 'test'])->name('api.v1.webhooks.test');
        Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries'])->name('api.v1.webhooks.deliveries');
        Route::post('webhook-deliveries/{delivery}/redeliver', [WebhookController::class, 'redeliver'])->name('api.v1.webhooks.redeliver');
    });
});
