<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'employee_code' => $this->employee_code,
            'email' => $this->email,
            'department' => $this->department,
            'department_id' => $this->department_id,
            'department_name' => $this->whenLoaded('department', fn () => $this->department?->name),
            'photo_url' => $this->photo_url,
            'is_active' => (bool) $this->is_active,
            'card_number' => $this->card_number,
            'has_card' => $this->has_card,
            // A ZKTeco user record carries exactly one card field, so this is
            // structurally 0 or 1. Exposed as a count because that is what the
            // roster column shows, alongside the fingerprint count.
            'card_count' => $this->has_card ? 1 : 0,
            'fingerprint_count' => $this->fingerprint_count,
            // Which slots hold a template, e.g. [1, 6], so the roster can draw
            // each person's enrolled fingers. Null = unknown, [] = none.
            'fingerprint_slots' => $this->fingerprint_slots,
            'enrollment_synced_at' => $this->enrollment_synced_at?->toIso8601String(),
            /*
             * Per-terminal enrollment, so a screen can say WHICH device holds
             * these fingerprints and this card.
             *
             * The four fields above are a flat snapshot of whichever terminal was
             * read last; with two or more devices they cannot be attributed. These
             * rows can, and callers should prefer them whenever present. Absent
             * from the payload (rather than empty) when the relation was not
             * loaded, so a consumer can tell "not asked for" from "not enrolled
             * anywhere" — rule 10 again.
             */
            'enrollments' => EmployeeDeviceMapResource::collection(
                $this->whenLoaded('deviceMaps')
            ),
            'attendance_logs_count' => $this->whenCounted('attendanceLogs'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
