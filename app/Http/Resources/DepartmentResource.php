<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Department
 */
class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            /*
             * A company is a department with no parent (2026-10-12). Said outright so an
             * integration need not know that rule (owner, 2026-11-02, option 1).
             */
            'is_company' => $this->parent_id === null,
            'company_id' => $this->parent_id === null ? (int) $this->id : (int) $this->resource->company()->id,
            'name' => $this->name,
            'code' => $this->code,
            'manager_id' => $this->manager_id,
            'manager_name' => $this->whenLoaded('manager', fn () => $this->manager?->name),
            'employees_count' => $this->whenCounted('employees'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
