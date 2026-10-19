<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\Settings\TenantSettings;
use App\Support\Timezone;
use App\Support\Tenancy;
use Cron\CronExpression;
use Promoseven\Biometric\Capability\Capability;

/**
 * The daily morning routine on every terminal (owner, 2026-10-11; casebook DC-019,
 * docs/device-models.md "Method: keeping terminal clocks right"):
 *
 *   1. CLOCK SYNC — "every day sync the time from server to the biometrics, at 5 am".
 *      Server NTP time → the terminal's own zone → that model's own clock command →
 *      read back; the command fails if the clock is >60 s off (SYNC_TIME, rule 10).
 *   2. PAST-DAY ATTENDANCE READ — "after that, a job to read attendance from all
 *      devices from the past day". Yesterday and today, as dates in the terminal's own
 *      zone. A range read leaves the watermark alone and the unique punch index makes
 *      a re-read punch a no-op, so it can never duplicate or skip anything.
 *
 * ORDER IS GUARANTEED PER TERMINAL: both are queued in this order on the terminal's own
 * queue, which runs one command at a time (rule 11), so the read starts only after the
 * clock command finished.
 *
 * When: each company's `devices.clock_sync_schedule` (default "0 5 * * *") in the
 * COMPANY's timezone, checked every minute. Each half has its own switch
 * (`devices.clock_sync_enabled`, `devices.daily_read_enabled`, both on by default). A
 * terminal that already has the same command queued or running is not given another.
 */
class DeviceMorningRoutine
{
    public function __construct(private readonly DeviceCommandBus $bus) {}

    /** @return array{clocks:int, reads:int} commands queued */
    public function dispatchDue(?\DateTimeInterface $now = null): array
    {
        $queued = ['clocks' => 0, 'reads' => 0];
        $byTenant = Device::withoutGlobalScopes()->where('is_active', true)->get()->groupBy('tenant_id');

        foreach ($byTenant as $tenantId => $devices) {
            Tenancy::set((int) $tenantId);

            try {
                $settings = app(TenantSettings::class);
                $clocks = (bool) $settings->get('devices.clock_sync_enabled');
                $reads = (bool) $settings->get('devices.daily_read_enabled');
                if (! $clocks && ! $reads) {
                    continue;
                }

                $zone = (string) $settings->get('system.timezone');
                $at = $now !== null
                    ? \DateTimeImmutable::createFromInterface($now)->setTimezone(new \DateTimeZone($zone))
                    : new \DateTimeImmutable('now', new \DateTimeZone($zone));

                if (! (new CronExpression((string) $settings->get('devices.clock_sync_schedule')))->isDue($at, $zone)) {
                    continue;
                }

                foreach ($devices as $device) {
                    // 1. The clock first …
                    if ($clocks && $device->supports(Capability::SET_TIME) && ! $this->pending($device, DeviceCommand::SYNC_TIME)) {
                        $this->bus->dispatch($device, DeviceCommand::SYNC_TIME);
                        $queued['clocks']++;
                    }

                    // 2. … then the past day, as the terminal's own dates.
                    if ($reads && $device->supports(Capability::ATTENDANCE_READ) && ! $this->pending($device, DeviceCommand::SYNC_ATTENDANCE)) {
                        $local = $at->setTimezone(new \DateTimeZone(Timezone::forDevice($device)));
                        $this->bus->dispatch($device, DeviceCommand::SYNC_ATTENDANCE, [
                            'from' => $local->modify('-1 day')->format('Y-m-d'),
                            'to' => $local->format('Y-m-d'),
                        ]);
                        $queued['reads']++;
                    }
                }
            } finally {
                Tenancy::clear();
            }
        }

        return $queued;
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
