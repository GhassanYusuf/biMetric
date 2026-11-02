<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A department of one company (2026-11-03; companies have their own resource).
 *
 * @mixin \App\Models\Department
 */
class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'company_id' => (int) $this->company_id,
            'company_name' => $this->whenLoaded('company', fn () => $this->company?->name),
            'name' => $this->name,
            'code' => $this->code,
            'manager_id' => $this->manager_id === null ? null : (int) $this->manager_id,
            'manager_name' => $this->whenLoaded('manager', fn () => $this->manager?->name),
            'employees_count' => $this->whenCounted('employees'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
