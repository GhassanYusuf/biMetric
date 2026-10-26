<?php

namespace App\Services\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Exception\DeviceException;

/**
 * Devices → Restart terminal (owner, 2026-10-26, casebook DC-034).
 *
 * Camelot three times stuck in a state that refuses every user insert (4995, "Error
 * inserting database") or update (4994) while its data reads normally. A power cycle on
 * site cleared it twice; a remote RESTART cleared it on 2026-10-26, back after 25 s with
 * every table unchanged. This makes that remote restart an operator action.
 *
 * Never reported on the ACK alone (rule 10): it is done when the terminal has stopped
 * answering and answers again. One that never stops answering, or is not back within
 * MAX_WAIT_SECONDS, is a failure that says so; nothing is retried, because a retry would
 * restart it again (the controller dispatches it with one attempt).
 */
class DeviceRestartService
{
    public const MAX_WAIT_SECONDS = 180;

    private const POLL_SECONDS = 2;

    /** @param  ?\Closure(int):void  $sleep  for tests; real seconds otherwise */
    public function __construct(
        private readonly BiometricDeviceDriver $driver,
        private readonly ?\Closure $sleep = null,
    ) {}

    /** @return array{message:string, raw:array<string,mixed>} */
    public function restart(Device $device): array
    {
        $this->driver->capabilities($device)->require(Capability::RESTART);

        $this->driver->restart($device);

        $waited = 0;
        $wentAway = false;

        while ($waited < self::MAX_WAIT_SECONDS) {
            $this->pause(self::POLL_SECONDS);
            $waited += self::POLL_SECONDS;

            try {
                $this->driver->ping($device);
            } catch (DeviceException) {
                $wentAway = true;

                continue;
            }

            if ($wentAway) {
                return [
                    'message' => "{$device->name} restarted and is answering again after about {$waited} s. "
                        .'Its clock may be a few seconds behind until the next clock job.',
                    'raw' => ['seconds_until_back' => $waited],
                ];
            }

            // Answering without ever having gone away: give a slow reboot a moment
            // before calling it, but never call it a restart.
            if ($waited >= 20) {
                throw new \DomainException(
                    "{$device->name} acknowledged the restart but never stopped answering, so it may not have restarted. "
                    .'Nothing else was changed. If writes still fail, restart it at the site.'
                );
            }
        }

        throw new \DomainException(
            "{$device->name} acknowledged the restart but has not answered for ".self::MAX_WAIT_SECONDS.' s. '
            .'Check it at the site: it may need its power switched off and on.'
        );
    }

    private function pause(int $seconds): void
    {
        ($this->sleep ?? static fn (int $s) => sleep($s))($seconds);
    }
}
