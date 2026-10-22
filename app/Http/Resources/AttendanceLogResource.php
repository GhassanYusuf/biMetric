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
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee?->fullName()),
            /*
             * Two numbers, kept apart (2026-10-22). `employee_code` is the person's
             * Personnel ID — null for a punch matched to nobody. `device_user_id` is the
             * number the TERMINAL recorded the punch under, which differs whenever a
             * terminal could not move someone to their Personnel ID (Monir: 230 on
             * Camelot). Until 2026-10-22 `employee_code` carried the terminal number,
             * so the screen's "Code" column showed 230 for him.
             */
            'employee_code' => $this->whenLoaded('employee', fn () => $this->employee?->employee_code),
            'device_user_id' => $this->device_user_id,
            'device_id' => $this->device_id,
            'device_name' => $this->whenLoaded('device', fn () => $this->device?->name),
            'punched_at' => $this->punched_at?->toIso8601String(),
            'verify_method' => $this->verify_method,
            'status' => $this->status,
            // Unmatched punches only: the terminal's own name for the PIN and whether
            // it was claimed/ignored at adoption. NOT an employee — see
            // AttendanceController::attachTerminalNames().
            'terminal_user_name' => $this->when($this->employee_id === null, fn () => $this->terminal_user_name),
            'terminal_user_status' => $this->when($this->employee_id === null, fn () => $this->terminal_user_status),
        ];
    }
}
