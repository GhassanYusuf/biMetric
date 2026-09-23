<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AttendanceSyncRun */
class AttendanceSyncRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'device_name' => $this->whenLoaded('device', fn () => $this->device?->name),
            'mode' => $this->mode,
            'status' => $this->status,
            'needs_attention' => $this->needsAttention(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms,
            /*
             * Granular on purpose. A bare "imported 3" hides whether the device
             * offered 3 records or 9,000 of which 8,997 were already known — and
             * that difference is the whole story when a sync looks wrong.
             *
             * `undecodable` is the one to watch: a non-zero value means the device
             * offered punches this decoder could not read, and they are archived in
             * attendance_raw_logs for reparsing rather than lost.
             */
            'counts' => [
                'read' => $this->records_read,
                'imported' => $this->records_imported,
                'duplicate' => $this->records_duplicate,
                'undecodable' => $this->records_undecodable,
                'unmatched' => $this->records_unmatched,
                /*
                 * Read off the device but outside the window this run was asked for.
                 * Always 0 on a full or incremental run, and the number that stops a
                 * range read's "read 9,579, imported 640" looking like a failure.
                 */
                'out_of_range' => $this->records_out_of_range,
            ],
            'watermark_before' => $this->watermark_before?->toIso8601String(),
            'watermark_after' => $this->watermark_after?->toIso8601String(),
            /*
             * Plain Y-m-d, not ISO instants. These are device-local CALENDAR DAYS —
             * the two dates an operator typed — and an ISO string would invite the
             * browser to shift them into its own timezone and show the wrong day.
             */
            'range_from' => $this->range_from?->format('Y-m-d'),
            'range_to' => $this->range_to?->format('Y-m-d'),
            'message' => $this->message,
            'error' => $this->error,
        ];
    }
}
