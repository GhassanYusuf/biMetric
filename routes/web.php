<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
| The SPA is the application. Web routes ship the shell, the account pages and
| authentication; everything else is /api.
|
| WHAT USED TO BE HERE: a parallel Inertia app with its own Devices, Employees,
| Attendance and Holidays screens, backed by its own controllers and a second
| device-communication stack (app/Services/Zkteco). It was kept alive until the
| SPA covered the same modules. It does, so it is gone — along with the duplicate
| ZKTeco protocol implementation that only it still used.
*/

Route::get('/', fn () => redirect()->route('spa'));

/*
| Kept as a NAMED route rather than deleted: Breeze's auth flows redirect here
| after login and email verification, and so do the tests that cover them. It
| now hands off to the SPA instead of rendering a second dashboard.
*/
Route::redirect('/dashboard', '/app/dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

/*
| Account pages. Still Inertia, because the SPA has no profile, password-change
| or account-deletion screen yet, and Breeze's session-based flows are what the
| password-reset and email-verification links depend on. This is the remaining
| piece of the Inertia app and the last thing to migrate.
*/
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
| The SPA shell. A catch-all under /app lets client-side routing own every
| sub-path (/app/employees, /app/dashboard, …). Auth is handled client-side via
| the Sanctum token, so there is no server auth middleware here.
*/
Route::view('/app/{path?}', 'spa')->where('path', '.*')->name('spa');

require __DIR__.'/auth.php';
