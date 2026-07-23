<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // App\Listeners\PublishPunchToMqtt is wired to App\Events\PunchRecorded
        // automatically by Laravel's event auto-discovery (its handle() method
        // is type-hinted with the event) - no explicit Event::listen() needed,
        // and adding one here would double-register it.
    }
}
