<?php

use App\Http\Controllers\AttendanceLogController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

/*
| The legacy Inertia app stays the default landing page until the SPA covers
| every module it carries in its nav (Devices, Attendance, Holidays). Do not
| redirect this to /app/dashboard before then — it strips those modules out of
| reach without deleting anything, which is exactly how they "disappear".
*/
Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::resource('devices', DeviceController::class)->except(['create', 'edit', 'show']);
    Route::post('/devices/{device}/test-connection', [DeviceController::class, 'testConnection'])->name('devices.test-connection');
    Route::post('/devices/{device}/sync-attendance', [DeviceController::class, 'syncAttendance'])->name('devices.sync-attendance');
    Route::post('/devices/{device}/import-employees', [DeviceController::class, 'importEmployees'])->name('devices.import-employees');

    Route::resource('employees', EmployeeController::class)->except(['create', 'edit', 'show']);
    Route::post('/employees/{employee}/push-to-device/{device}', [EmployeeController::class, 'pushToDevice'])->name('employees.push-to-device');
    Route::post('/employees/{employee}/remove-from-device/{device}', [EmployeeController::class, 'removeFromDevice'])->name('employees.remove-from-device');
    Route::post('/employees/{employee}/enroll-fingerprint/{device}', [EmployeeController::class, 'enrollFingerprint'])->name('employees.enroll-fingerprint');
    Route::post('/employees/{employee}/enroll-card/{device}', [EmployeeController::class, 'enrollCard'])->name('employees.enroll-card');
    Route::get('/employees/{employee}/attendance-summary', [EmployeeController::class, 'attendanceSummary'])->name('employees.attendance-summary');
    Route::post('/employees/{employee}/waive-day', [EmployeeController::class, 'waiveDay'])->name('employees.waive-day');

    Route::get('/attendance', [AttendanceLogController::class, 'index'])->name('attendance.index');

    Route::get('/holidays', [HolidayController::class, 'index'])->name('holidays.index');
    Route::post('/holidays', [HolidayController::class, 'store'])->name('holidays.store');
    Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');
});

/*
| The new API-first SPA shell. Web routes only ship the shell HTML — all data
| comes from /api. A catch-all under /app lets client-side routing own every
| sub-path (e.g. /app/employees, /app/dashboard). Auth is handled client-side
| via the Sanctum token, so no server auth middleware here.
*/
Route::view('/app/{path?}', 'spa')->where('path', '.*')->name('spa');

require __DIR__.'/auth.php';
