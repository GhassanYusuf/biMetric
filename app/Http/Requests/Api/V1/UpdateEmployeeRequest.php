<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\Employee\UpdateEmployeeRequest as BaseRequest;
use App\Models\Employee;

/**
 * PATCH semantics for the integration API: send only what changed.
 *
 * The SPA's update request marks `name` and `employee_code` required because the form
 * always submits the whole record. A system syncing one changed field should not have to
 * round-trip the rest, so every `required` becomes `sometimes` — present fields are
 * validated exactly as before, absent ones are left alone.
 */
class UpdateEmployeeRequest extends BaseRequest
{
    use CompanyDepartmentFields;

    public function rules(): array
    {
        $rules = collect(parent::rules())
            ->map(fn (array $rule) => array_map(fn ($r) => $r === 'required' ? 'sometimes' : $r, $rule))
            ->all();

        return $this->companyDepartmentRules() + $rules + StoreEmployeeRequest::deviceRules() + [
            // After saving, re-push the change to every terminal they are currently on.
            'push_to_mapped_devices' => ['sometimes', 'boolean'],
        ];
    }

    public function employeeData(): array
    {
        // PATCH names them by Personnel ID; the upsert by the caller's external_id.
        $current = $this->route('employee');
        if (! $current instanceof Employee && $this->route('externalId') !== null) {
            $current = Employee::query()->where('external_id', $this->route('externalId'))->first();
        }

        return $this->foldCompanyDepartment(
            collect($this->safe()->except(['photo', 'access_level_ids', 'push_to_device_ids', 'push_to_mapped_devices']))->all(),
            $current instanceof Employee ? $current : null,
        );
    }
}
