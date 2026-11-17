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
| The Cron jobs screen's jobs (owner, 2026-10-20) — App\Services\Devices\ScheduledJobRunner.
|
| Each company's own rows in `scheduled_jobs`: an action from a fixed list (never a
| command line), a cron expression in the COMPANY's timezone, all terminals or some.
| They replaced two definitions that lived here: `sync-attendance-all` (the full read,
| `attendance.sync_*` settings, off) and `device-morning-routine` (clock then past-day
| read, `devices.clock_sync_*`, 05:00 and 10:00 Bahrain); the migration carried their
| settings over as rows.
|
| WHY `everyMinute()` RATHER THAN `->cron($expression)`: `->cron()` needs its argument
| while this file is being PARSED, which happens on every artisan command — migrate,
| config:cache, queue:work — and would fatal on a fresh install whose tables do not
| exist yet. So the event ticks every minute and the runner decides what is due.
|
| The jobs below this one are the system's own and are not on that screen to change:
| the nightly fingerprint copy is rule 19's guarantee and must not be deletable.
*/
Schedule::call(fn () => app(\App\Services\Devices\ScheduledJobRunner::class)->dispatchDue())
    ->everyMinute()->name('scheduled-jobs')->withoutOverlapping();

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

/*
 * End terminal access on the day it is due (owner, 2026-11-17): resignations whose date has arrived,
 * and valid periods that have ended. Just after midnight office time, so a resignation dated 29 Nov
 * takes them off the terminals early on the 29th. Idempotent: whoever is still on a terminal (offline
 * that night, a refusal) is taken again the next night. See App\Services\Employees\AccessExpiry.
 */
Artisan::command('employees:end-access', function () {
    $r = app(\App\Services\Employees\AccessExpiry::class)->run();
    $this->info("Resigned today or earlier: {$r['resigned']}; valid period ended: {$r['expired']}; removal jobs queued: {$r['jobs']}.");
    foreach ($r['skipped'] as $terminal => $why) {
        $this->warn("Not writable: {$terminal} ({$why})");
    }
})->purpose('Take people off the terminals once their resignation date or valid period end is reached');

Schedule::command('employees:end-access')
    ->dailyAt('00:05')
    ->timezone(config('attendance.timezone'))
    ->name('employees-end-access')
    ->withoutOverlapping();
