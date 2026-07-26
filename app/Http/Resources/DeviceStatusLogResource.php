<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\DeviceStatusLog
 *
 * Only transitions are recorded, so every row here is a change worth seeing. A
 * device polled every five minutes does not produce a row saying "still online".
 */
class DeviceStatusLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'status' => $this->status,
            'previous_status' => $this->previous_status,
            'latency_ms' => $this->latency_ms,
            'reason' => $this->reason,
            'observed_at' => $this->observed_at?->toIso8601String(),
        ];
    }
}
