<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\ScheduledJob;
use App\Services\Settings\TenantSettings;
use App\Support\Timezone;
use App\Support\Tenancy;
use Cron\CronExpression;
use Promoseven\Biometric\Capability\Capability;

/**
 * Runs the Cron jobs screen's jobs (owner, 2026-10-20). Was DeviceMorningRoutine, which
 * ran one fixed job from settings: the daily clock sync and past-day read (owner,
 * 2026-10-11; casebook DC-019, docs/device-models.md "Method: keeping terminal clocks
 * right"). That job is now a row like any other, and so is the old `sync-attendance-all`
 * full read.
 *
 * Checked every minute by the scheduler. A job is due when its cron expression matches
 * this minute in the COMPANY's timezone (`system.timezone`) — the scheduler runs in UTC,
 * and 05:00 there would be 08:00 in Bahrain.
 *
 * ORDER IS GUARANTEED PER TERMINAL: a job's commands are queued in ScheduledJob::ACTIONS
 * order on the terminal's own queue, which runs one command at a time (rule 11), so in
 * "set clocks, then read" the read starts only after the clock command finished.
 *
 * A terminal that already has the same command queued or running is not given another
 * (the bus does not de-duplicate, and a slow full read would pile up behind itself), nor
 * one it cannot do (rule 9).
 */
class ScheduledJobRunner
{
    public function __construct(private readonly DeviceCommandBus $bus) {}

    /** @return array<string, int> commands queued, by command */
    public function dispatchDue(?\DateTimeInterface $now = null): array
    {
        $queued = [];
        $byTenant = ScheduledJob::withoutGlobalScopes()->where('is_enabled', true)->get()->groupBy('tenant_id');

        foreach ($byTenant as $tenantId => $jobs) {
            Tenancy::set((int) $tenantId);

            try {
                $zone = (string) app(TenantSettings::class)->get('system.timezone');
                $at = $now !== null
                    ? \DateTimeImmutable::createFromInterface($now)->setTimezone(new \DateTimeZone($zone))
                    : new \DateTimeImmutable('now', new \DateTimeZone($zone));

                foreach ($jobs as $job) {
                    if (CronExpression::isValidExpression($job->schedule) && (new CronExpression($job->schedule))->isDue($at, $zone)) {
                        foreach ($this->run($job, $at) as $command => $n) {
                            $queued[$command] = ($queued[$command] ?? 0) + $n;
                        }
                    }
                }
            } finally {
                Tenancy::clear();
            }
        }

        return $queued;
    }

    /**
     * Queues one job's commands now, on its terminals. Also what "Run now" calls; the
     * caller has bound the job's tenant.
     *
     * @return array<string, int> commands queued, by command
     */
    public function run(ScheduledJob $job, ?\DateTimeImmutable $at = null): array
    {
        $at ??= new \DateTimeImmutable('now');
        $steps = ScheduledJob::ACTIONS[$job->action]['steps'] ?? [];
        $devices = Device::withoutGlobalScopes()
            ->where('tenant_id', $job->tenant_id)
            ->where('is_active', true)
            ->when($job->device_ids !== null, fn ($q) => $q->whereIn('id', $job->device_ids))
            ->get();

        $queued = [];
        $skipped = 0;

        foreach ($devices as $device) {
            foreach ($steps as [$command, $range]) {
                if (! $this->canRun($device, $command) || $this->pending($device, $command)) {
                    $skipped++;

                    continue;
                }

                $payload = [];
                if ($range) {
                    // Yesterday and today, as the TERMINAL's own dates.
                    $local = $at->setTimezone(new \DateTimeZone(Timezone::forDevice($device)));
                    $payload = ['from' => $local->modify('-1 day')->format('Y-m-d'), 'to' => $local->format('Y-m-d')];
                }

                $this->bus->dispatch($device, $command, $payload);
                $queued[$command] = ($queued[$command] ?? 0) + 1;
            }
        }

        $total = array_sum($queued);
        $job->forceFill([
            'last_run_at' => now(),
            'last_result' => ($total === 0 ? 'Nothing queued' : "Queued {$total} command".($total === 1 ? '' : 's'))
                .' on '.$devices->count().' terminal'.($devices->count() === 1 ? '' : 's')
                .($skipped > 0 ? "; {$skipped} skipped (already queued, or the terminal cannot do it)" : '').'.',
        ])->saveQuietly();

        return $queued;
    }

    private function canRun(Device $device, string $command): bool
    {
        return match ($command) {
            DeviceCommand::SYNC_TIME => $device->supports(Capability::SET_TIME),
            DeviceCommand::SYNC_ATTENDANCE => $device->supports(Capability::ATTENDANCE_READ),
            // As the nightly copy in routes/console.php: the two families whose roster
            // read stores fingers and cards (rule 19).
            DeviceCommand::SYNC_ENROLLMENT => in_array($device->protocol, [Device::PROTOCOL_BINARY, Device::PROTOCOL_C3], true),
            default => false,
        };
    }

    private function pending(Device $device, string $command): bool
    {
        return DeviceCommand::withoutGlobalScopes()
            ->where('device_id', $device->id)
            ->where('command', $command)
            ->whereIn('status', [DeviceCommand::STATUS_QUEUED, DeviceCommand::STATUS_RUNNING])
            ->exists();
    }
}
