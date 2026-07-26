<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\AuditLog
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => $this->whenLoaded('user', fn () => $this->user?->name),
            'auditable_type' => class_basename((string) $this->auditable_type),
            'auditable_id' => $this->auditable_id,
            'changes' => $this->changes,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
