<?php

namespace App\Providers;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Devices\DeviceDriverManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Bind the biometric driver contract.
         *
         * App code depends on BiometricDeviceDriver, never on Promoseven\Biometric
         * directly — that is what keeps the protocol package portable to other
         * platforms instead of quietly re-acquiring Laravel assumptions.
         *
         * Supporting a second vendor means binding a different implementation here,
         * not editing a controller.
         */
        $this->app->singleton(
            BiometricDeviceDriver::class,
            DeviceDriverManager::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Platform super-admins bypass every permission/policy check. Returning
        // null for everyone else lets normal gates/policies decide.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        // Immutable audit trail for the primary domain records.
        Employee::observe(AuditObserver::class);
        Device::observe(AuditObserver::class);
        Department::observe(AuditObserver::class);
        Holiday::observe(AuditObserver::class);

        // App\Listeners\PublishPunchToMqtt is wired to App\Events\PunchRecorded
        // automatically by Laravel's event auto-discovery (its handle() method
        // is type-hinted with the event) - no explicit Event::listen() needed,
        // and adding one here would double-register it.
    }
}
