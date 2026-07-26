<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EnrollmentSession */
class EnrollmentSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee?->name),
            'device_id' => $this->device_id,
            'device_name' => $this->whenLoaded('device', fn () => $this->device?->name),
            'type' => $this->type,
            'finger_slot' => $this->finger_slot,
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'requested_at' => $this->requested_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'timeout_seconds' => $this->timeout_seconds,
            'captured_card_number' => $this->captured_card_number,
            'device_error_code' => $this->device_error_code,
            /*
             * The device's own progress trail: beeped, waiting_for_finger,
             * finger_placed_sample_1, sample_score_N, enrolled.
             *
             * This is the only evidence available when somebody reports that nothing
             * happened — it shows whether the terminal ever beeped, whether a finger
             * was placed, and what it scored.
             */
            'events' => $this->events ?? [],
            'message' => $this->message,
        ];
    }
}
