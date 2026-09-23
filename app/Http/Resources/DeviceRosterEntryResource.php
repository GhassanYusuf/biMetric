<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DeviceRosterEntry */
class DeviceRosterEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'device_name' => $this->whenLoaded('device', fn () => $this->device?->name),
            // The device-internal slot. Stable per device, NOT portable between them.
            'device_uid' => $this->device_uid,
            // The PIN the device files this person under, and what punches carry.
            'device_user_id' => $this->device_user_id,
            // What the device shows on screen — 24 bytes, often abbreviated. A hint for
            // the operator making the claim, never a basis for matching.
            'device_held_name' => $this->device_name,
            'device_privilege' => $this->device_privilege,
            'card_number' => $this->card_number,
            'fingerprint_slots' => $this->fingerprint_slots,
            /*
             * null means the template table would not decode — UNKNOWN, not none.
             * The UI must keep those two visually distinct (rule 10): a wrong "0"
             * tells an operator somebody is unenrolled when they are not, and here
             * that could lead to an unnecessary re-enrollment.
             */
            'fingerprint_count' => $this->fingerprintCount(),
            'claim_status' => $this->claim_status,
            'claimed_employee_id' => $this->claimed_employee_id,
            'claimed_employee_name' => $this->whenLoaded('claimedEmployee', fn () => $this->claimedEmployee?->name),
            'ignore_reason' => $this->ignore_reason,
            'first_seen_at' => $this->first_seen_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
