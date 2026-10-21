<?php

namespace App\Http\Resources;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Device
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
            // SYSTEM punches: what this app has stored for the terminal.
            'attendance_logs_count' => $this->whenCounted('attendanceLogs'),
            /*
             * DEVICE punches: what the terminal itself holds, as it last said (2026-10-21).
             * Null = never counted — not 0 (rule 10). The two differ after a clear or a
             * wipe, which is the point of showing both.
             */
            'terminal_punch_count' => $this->terminal_punch_count === null ? null : (int) $this->terminal_punch_count,
            'terminal_punch_counted_at' => $this->terminal_punch_counted_at?->toIso8601String(),

            /*
             * ADOPTION — whether this terminal will accept a write at all.
             *
             * It was missing from this payload entirely, which is why a device being
             * read-only was invisible until a push came back refused: every screen
             * offered actions the hardware would reject, and rule 9 says a UI must
             * hide what cannot work. `write_refusal` is the sentence the operator
             * needs, produced by the same method the propagator refuses with, so the
             * screen and the server cannot say different things.
             */
            'adoption_status' => $this->adoption_status,
            'is_writable' => $this->isWritable(),
            'write_refusal' => $this->userWriteRefusal(),
            'roster_read_at' => $this->roster_read_at?->toIso8601String(),
            // Roster entries nobody has claimed or ignored. Adoption is refused while
            // this is above zero, and it is the number an operator has to work down.
            'unclaimed_roster_entries' => $this->whenCounted('unclaimedRosterEntries'),

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
