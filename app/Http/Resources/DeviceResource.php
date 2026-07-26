<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Device
 *
 * Secrets (comm_key, web_password) are intentionally never exposed — they're
 * already hidden on the model and omitted here as a second line of defence.
 */
class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ip_address' => $this->ip_address,
            'port' => $this->port,
            'protocol' => $this->protocol,
            'location' => $this->location,
            // Exposed so the edit form can round-trip it. Not a secret; the
            // matching web_password is never sent back.
            'web_username' => $this->web_username,
            'is_active' => (bool) $this->is_active,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_sync_status' => $this->last_sync_status,
            'last_sync_error' => $this->last_sync_error,
            'attendance_logs_count' => $this->whenCounted('attendanceLogs'),

            // --- Identity read off the hardware (null until READ_INFO has run).
            'model' => $this->model,
            'firmware_version' => $this->firmware_version,
            'platform' => $this->platform,
            'serial_number' => $this->serial_number,
            'mac_address' => $this->mac_address,
            'user_capacity' => $this->user_capacity,
            'log_capacity' => $this->log_capacity,
            'info_read_at' => $this->info_read_at?->toIso8601String(),
            'timezone' => $this->timezone,

            /*
             * --- Health.
             *
             * `is_online` is DERIVED from last_seen_at, not stored. A device that
             * simply stops answering never gets the chance to mark itself offline, so
             * a stored flag would keep claiming it is up.
             */
            'health_status' => $this->health_status,
            'is_online' => $this->isOnline(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_latency_ms' => $this->last_latency_ms,
            'consecutive_failures' => $this->consecutive_failures,

            // --- Attendance sync position.
            'attendance_watermark' => $this->attendance_watermark?->toIso8601String(),
            'attendance_imported_total' => $this->attendance_imported_total,

            /*
             * --- Capabilities, so the UI can hide what this firmware cannot do
             * rather than offering a button that returns 502.
             *
             * Four levels, not two: confirmed / implemented / not_implemented /
             * unknown. See docs/device-capabilities.md.
             */
            'capabilities' => $this->capabilitySet()->forUi(),
            'capabilities_probed_at' => $this->capabilities_probed_at?->toIso8601String(),

            'groups' => DeviceGroupResource::collection($this->whenLoaded('groups')),
            // Booleans telling the UI whether secrets are set, without leaking them.
            'has_comm_key' => filled($this->comm_key),
            'has_web_credentials' => filled($this->web_username),
        ];
    }
}
