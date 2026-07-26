<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\AttendanceLog
 */
class AttendanceLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee?->name),
            'employee_code' => $this->device_user_id,
            'device_id' => $this->device_id,
            'device_name' => $this->whenLoaded('device', fn () => $this->device?->name),
            'punched_at' => $this->punched_at?->toIso8601String(),
            'verify_method' => $this->verify_method,
            'status' => $this->status,
        ];
    }
}
