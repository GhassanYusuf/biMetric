<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a device attendance sync fails. A listener turns this into an
 * operator alert (persisted notification + live MQTT). Kept as an event so the
 * alerting concern stays out of the sync service itself.
 */
class DeviceSyncFailed
{
    use Dispatchable;

    public function __construct(
        public readonly Device $device,
        public readonly string $reason,
    ) {
    }
}
