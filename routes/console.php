<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| `app:sync-biometric-devices` is NOT scheduled (2026-09-24). It queued a full
| attendance read on EVERY active terminal every five minutes, duplicating the hourly
| job below; every read transfers the whole table (87,806 rows on Glass Door, ~5 min
| on Camelot), so it would have kept every terminal busy permanently. It stays
| available for manual use.
*/

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
|
| Covers the C3 panels too (owner, 2026-09-24): Glass Door and Entrance had not been
| read since 2026-09-15 / 2026-09-20 because this job was ZKTeco-only — and because
| no cron ran `schedule:run` on this server at all.
|
| A terminal that still has a sync queued or running is SKIPPED: the bus does not
| de-duplicate, and a slow terminal would otherwise pile up a new full read every
| hour behind the one it has not finished.
*/
Schedule::call(function () {
    $bus = app(\App\Services\Devices\DeviceCommandBus::class);

    foreach (\App\Models\Device::withoutGlobalScopes()
        ->whereIn('protocol', [\App\Models\Device::PROTOCOL_BINARY, \App\Models\Device::PROTOCOL_C3])
        ->where('is_active', true)
        ->get() as $device) {
        $pending = \App\Models\DeviceCommand::withoutGlobalScopes()
            ->where('device_id', $device->id)
            ->where('command', \App\Models\DeviceCommand::SYNC_ATTENDANCE)
            ->whereIn('status', [\App\Models\DeviceCommand::STATUS_QUEUED, \App\Models\DeviceCommand::STATUS_RUNNING])
            ->exists();

        if ($pending) {
            continue;
        }

        \App\Support\Tenancy::set($device->tenant_id);
        $bus->dispatch($device, \App\Models\DeviceCommand::SYNC_ATTENDANCE);
        \App\Support\Tenancy::clear();
    }
})->hourly()->name('sync-attendance-all')->withoutOverlapping();
