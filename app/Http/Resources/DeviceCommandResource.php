<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\DeviceCommand
 *
 * Both `message` and `error`/`raw_response` are exposed on purpose. The message is
 * the sentence an operator reads; the rest is technical detail for whoever debugs a
 * failure. Collapsing them would force one audience to read the other's output.
 */
class DeviceCommandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'device_name' => $this->whenLoaded('device', fn () => $this->device?->name),
            'command' => $this->command,
            'payload' => $this->payload,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'can_retry' => $this->canRetry(),
            'is_finished' => $this->isFinished(),
            'available_at' => $this->available_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'message' => $this->message,
            'error' => $this->error,
            'raw_response' => $this->raw_response,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
