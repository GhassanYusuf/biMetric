<?php

namespace App\Events;

use App\Models\AttendanceLog;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired for every genuinely new attendance punch, whether it arrived via the
 * live realtime device listener or a manual/background bulk sync - both
 * paths funnel through here so publishing (MQTT) only happens in one place.
 */
class PunchRecorded
{
    use Dispatchable;

    public function __construct(public readonly AttendanceLog $log)
    {
    }
}
