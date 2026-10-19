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
| THE SCHEDULE IS A SETTING NOW (2026-10-05), not `->hourly()`. The cost of this job
| is not obvious from the code: the protocol cannot be asked for "punches since X", so
| every run transfers a device's WHOLE attendance table — 87,806 rows on Glass Door,
| ~5 min on Camelot. Hourly was a defensible default for four LAN terminals and a bad
| one for anything larger, and the answer should not need a deploy.
|
| WHY `everyMinute()` RATHER THAN `->cron($expression)`:
| `->cron()` needs its argument while routes/console.php is being PARSED, which happens
| on every artisan command in the app — migrate, config:cache, queue:work. Reading a
| tenant setting there would mean a settings query on all of them, and would fatal on a
| fresh install whose settings table does not exist yet. So the event ticks every
| minute and the closure below decides whether this minute is actually due. Checking
| per minute is required for the setting to have sub-hourly resolutions at all, and it
| costs one settings row per minute.
|
| `attendance.sync_enabled` defaults to OFF, deliberately. This server had no
| `schedule:run` wired to cron until 2026-10-05 — there is no crontab for root — so
| this whole definition had never executed. Enabling the setting is the deliberate act
| that starts real traffic at real terminals.
|
| THE SCHEDULE IS PER TENANT, in the settings table, and is read inside the tenant's
| own scope: the scheduler runs with no tenancy bound, so asking for the setting before
| choosing a tenant would read nobody's.
|
| The expression is evaluated in the COMPANY's timezone (`system.timezone`), NOT the
| server's. app.timezone is UTC on this host, so evaluating there would make "0 3 * * *"
| fire at 06:00 Bahrain time. An operator who types 3am means 3am where they are.
|
| C3 panels are included (owner, 2026-09-24): Glass Door and Entrance had not been read
| since 2026-09-15 / 2026-09-20 because this job used to be ZKTeco-only.
|
| A terminal that still has a sync queued or running is SKIPPED: the bus does not
| de-duplicate, and a slow terminal would otherwise pile up another full read behind
| the one it has not finished.
*/
Schedule::call(function () {
    $devices = \App\Models\Device::withoutGlobalScopes()
        ->whereIn('protocol', [\App\Models\Device::PROTOCOL_BINARY, \App\Models\Device::PROTOCOL_C3])
        ->where('is_active', true)
        ->get()
        // Grouped so each company's own schedule decides its own terminals, and so
        // tenancy is bound once per company rather than once per device.
        ->groupBy('tenant_id');

    foreach ($devices as $tenantId => $tenantDevices) {
        \App\Support\Tenancy::set($tenantId);

        try {
            $settings = app(\App\Services\Settings\TenantSettings::class);

            if (! $settings->get('attendance.sync_enabled')) {
                continue;
            }

            // Resolved inside the tenant's scope on purpose: this is the company's
            // own timezone, and it is what "03:00" means to whoever typed it.
            $companyTz = $settings->get('system.timezone');

            $due = (new \Cron\CronExpression($settings->get('attendance.sync_schedule')))
                ->isDue(now($companyTz), $companyTz);

            if (! $due) {
                continue;
            }

            $bus = app(\App\Services\Devices\DeviceCommandBus::class);

            foreach ($tenantDevices as $device) {
                $pending = \App\Models\DeviceCommand::withoutGlobalScopes()
                    ->where('device_id', $device->id)
                    ->where('command', \App\Models\DeviceCommand::SYNC_ATTENDANCE)
                    ->whereIn('status', [\App\Models\DeviceCommand::STATUS_QUEUED, \App\Models\DeviceCommand::STATUS_RUNNING])
                    ->exists();

                if ($pending) {
                    continue;
                }

                $bus->dispatch($device, \App\Models\DeviceCommand::SYNC_ATTENDANCE);
            }
        } finally {
            \App\Support\Tenancy::clear();
        }
    }
})->everyMinute()->name('sync-attendance-all')->withoutOverlapping();

/*
| Nightly roster read on every terminal, which also stores everyone's fingerprints in
| the system (2026-10-11, owner: "read the fingerprint, store it in the webapp, and
| whenever needed write it to a device"). Akram's finger existed only on IT Room until
| the day he was removed from it; a copy taken that morning would have put it back.
|
| Through the bus like every device action (rule 11) — queued, audited, one command at
| a time per terminal — at 02:30 UTC, outside working hours and clear of the hourly
| attendance read. Read-only on the terminals. A terminal with a roster read already
| queued or running is skipped rather than given a second one.
*/
Schedule::call(function () {
    $devices = \App\Models\Device::withoutGlobalScopes()
        ->whereIn('protocol', [\App\Models\Device::PROTOCOL_BINARY, \App\Models\Device::PROTOCOL_C3])
        ->where('is_active', true)
        ->get();

    foreach ($devices as $device) {
        \App\Support\Tenancy::set($device->tenant_id);

        try {
            $pending = \App\Models\DeviceCommand::withoutGlobalScopes()
                ->where('device_id', $device->id)
                ->where('command', \App\Models\DeviceCommand::SYNC_ENROLLMENT)
                ->whereIn('status', [\App\Models\DeviceCommand::STATUS_QUEUED, \App\Models\DeviceCommand::STATUS_RUNNING])
                ->exists();

            if (! $pending) {
                app(\App\Services\Devices\DeviceCommandBus::class)->dispatch($device, \App\Models\DeviceCommand::SYNC_ENROLLMENT);
            }
        } finally {
            \App\Support\Tenancy::clear();
        }
    }
})->dailyAt('02:30')->name('fingerprint-copies-nightly')->withoutOverlapping();

/*
| Daily morning routine (owner, 2026-10-11; casebook DC-019): "every day sync the time
| from server to the biometrics, at 5 am" and "after that, read attendance from all
| devices from the past day". App\Services\Devices\DeviceMorningRoutine queues, per
| terminal, the clock sync (server NTP time → terminal zone → model's own command → read
| back, failing if >60 s off) and THEN a range read of yesterday and today. IT ROOM, On The Road, Glass Door and Entrance
| had been found ~10¾ minutes slow, reset that way by something outside this app.
|
| Checked every minute because the time is each COMPANY's setting
| (`devices.clock_sync_schedule`, default 05:00) in the company's own timezone — the
| scheduler runs in UTC, and 05:00 there would be 08:00 in Bahrain.
*/
Schedule::call(fn () => app(\App\Services\Devices\DeviceMorningRoutine::class)->dispatchDue())
    ->everyMinute()->name('device-morning-routine')->withoutOverlapping();

/*
| Hands new punches to webhook subscribers (2026-10-08, docs/integration-api.md).
|
| The live path (RelayPunchWebhooks) covers a punch as it happens; this sweep covers
| everything that path cannot see — backfills, and any punch recorded while a worker was
| down. Each endpoint keeps its own cursor, so running it more often is harmless.
*/
Schedule::call(fn () => app(\App\Services\Integration\PunchWebhookRelay::class)->relay())
    ->name('webhooks:relay-punches')
    ->everyMinute()
    ->withoutOverlapping();

/*
| Employee report files are kept 7 days (ReportExport::KEEP_DAYS), as the Reports screen
| says. Before 2026-10-19 they were only cleared when somebody started a new export, so
| a quiet company kept every employee's attendance on disk indefinitely. Daily at 03:15
| UTC, clear of the 02:30 fingerprint read.
*/
Schedule::call(fn () => \App\Services\Reports\EmployeeReportsExport::prune())
    ->dailyAt('03:15')->name('report-exports-prune')->withoutOverlapping();
