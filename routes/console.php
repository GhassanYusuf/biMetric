<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:sync-biometric-devices')
    ->everyFiveMinutes()
    ->withoutOverlapping();

/*
| Device health heartbeat.
|
| Fans out one queued command per device rather than polling them in-line, so one
| unreachable terminal cannot delay every other check behind its timeout. The job
| itself skips a device that already has a check queued, so a slow device cannot
| accumulate a backlog faster than it drains.
*/
Schedule::job(new \App\Jobs\CheckAllDeviceHealth())
    ->everyFiveMinutes()
    ->withoutOverlapping();

/*
| Reaps enrollment sessions whose worker died mid-capture.
|
| Without this a killed worker leaves a session "waiting" forever and the employee
| can never start a fresh attempt. Runs often because a stuck session blocks a real
| person standing at a terminal.
*/
Schedule::call(function () {
    $expired = app(\App\Services\Devices\EnrollmentOrchestratorService::class)->expireStale();

    if ($expired > 0) {
        \Illuminate\Support\Facades\Log::info("Expired {$expired} stale enrollment session(s).");
    }
})->everyMinute()->name('expire-stale-enrollments')->withoutOverlapping();

/*
| Incremental attendance import.
|
| Hourly, not continuous: the protocol cannot be asked for "punches since X" — every
| read transfers the whole table — so polling aggressively costs bandwidth without
| gaining freshness. Immediacy comes from the realtime listener instead.
*/
Schedule::call(function () {
    $bus = app(\App\Services\Devices\DeviceCommandBus::class);

    foreach (\App\Models\Device::withoutGlobalScopes()
        ->where('protocol', \App\Models\Device::PROTOCOL_BINARY)
        ->where('is_active', true)
        ->get() as $device) {
        \App\Support\Tenancy::set($device->tenant_id);
        $bus->dispatch($device, \App\Models\DeviceCommand::SYNC_ATTENDANCE);
        \App\Support\Tenancy::clear();
    }
})->hourly()->name('sync-attendance-all')->withoutOverlapping();
