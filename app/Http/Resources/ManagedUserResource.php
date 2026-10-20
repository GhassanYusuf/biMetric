<?php

namespace App\Http\Resources;

use App\Support\PermissionCatalogue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row on the Users screen (2026-10-19). Not UserResource: that one is the SIGNED-IN
 * user's own payload, with the full effective permission list the SPA gates on.
 *
 * `last_active_at` is the newest Sanctum token use — null means never signed in.
 *
 * @mixin \App\Models\User
 */
class ManagedUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->getRoleNames()->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => (bool) $this->is_active,
            'role' => $role,
            'role_label' => $role !== null ? PermissionCatalogue::label($role) : null,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
                'employee_code' => $this->employee->employee_code,
            ] : null),
            'last_active_at' => $this->last_active_at ? \Illuminate\Support\Carbon::parse($this->last_active_at)->toIso8601String() : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'is_self' => $request->user()?->id === $this->id,
        ];
    }
}
