<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EmployeeDeviceMap */
class EmployeeDeviceMapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee?->name),
            'device_id' => $this->device_id,
            'device_name' => $this->whenLoaded('device', fn () => $this->device?->name),
            // The device-internal slot. Stable per device, NOT portable between them.
            'device_uid' => $this->device_uid,
            // The PIN attendance records actually carry.
            'device_user_id' => $this->device_user_id,
            // What the device holds as their name, which can drift from ours.
            'device_held_name' => $this->device_name,
            'card_number' => $this->card_number,
            'fingerprint_slots' => $this->fingerprint_slots,
            'fingerprint_count' => $this->fingerprintCount(),
            'reconciliation_status' => $this->reconciliation_status,
            'reconciliation_detail' => $this->reconciliation_detail,
            'needs_attention' => $this->needsAttention(),
            'synced_at' => $this->synced_at?->toIso8601String(),
            /*
             * Whether this mapping is CURRENT. A closed one is a PIN they used to
             * hold on that device, kept so their old punches still resolve to them —
             * so a screen offering "update them on these terminals" must not offer
             * it, or the write would land on whoever holds that slot now.
             */
            'is_live' => $this->valid_to === null,
            'closed_at' => $this->valid_to?->toIso8601String(),
        ];
    }
}
