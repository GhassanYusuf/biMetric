<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_super_admin' => (bool) $this->is_super_admin,
            'is_active' => (bool) $this->is_active,
            'tenant' => $this->whenLoaded('tenant', fn () => [
                'id' => $this->tenant?->id,
                'name' => $this->tenant?->name,
                'slug' => $this->tenant?->slug,
                'timezone' => $this->tenant?->timezone,
                'primary_color' => $this->tenant?->primary_color,
            ]),
            'employee_id' => $this->employee_id,
            // Roles + effective permissions so the SPA can gate UI without extra calls.
            'roles' => $this->getRoleNames(),
            // Less delete when this user's "Can delete" is off (2026-11-05).
            'permissions' => $this->effectivePermissionNames(),
            'can_delete' => $this->can_delete !== false,
            // The companies they may see; null = every company (CompanyAccess).
            'company_ids' => $this->companyIds(),
        ];
    }
}
