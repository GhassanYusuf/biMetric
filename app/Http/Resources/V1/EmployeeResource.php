<?php

namespace App\Http\Resources\V1;

use App\Http\Resources\EmployeeResource as SpaEmployeeResource;
use Illuminate\Http\Request;

/**
 * The integration API's employee: the website's resource, with company and department as
 * two separate fields (owner, 2026-11-02, option 1).
 *
 *   company_id      the company, always (the top of the branch); null when none is set
 *   department_id   the department, or null when the employee only has a company
 *
 * The website keeps the shared resource, whose `department_id` is the stored column (the
 * department, or the company itself when there is no department).
 */
class EmployeeResource extends SpaEmployeeResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        if (! $this->relationLoaded('department')) {
            return $data;
        }

        $node = $this->getRelation('department');
        $company = $node?->company();
        $isDepartment = $node !== null && $node->parent_id !== null;

        $data['company_id'] = $company === null ? null : (int) $company->id;
        $data['department_id'] = $isDepartment ? (int) $node->id : null;
        $data['department_name'] = $isDepartment ? $node->name : null;

        return $data;
    }
}
