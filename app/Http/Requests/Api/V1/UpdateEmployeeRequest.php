<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\Employee\UpdateEmployeeRequest as BaseRequest;

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
    public function rules(): array
    {
        $rules = collect(parent::rules())
            ->map(fn (array $rule) => array_map(fn ($r) => $r === 'required' ? 'sometimes' : $r, $rule))
            ->all();

        return $rules + StoreEmployeeRequest::deviceRules() + [
            // After saving, re-push the change to every terminal they are currently on.
            'push_to_mapped_devices' => ['sometimes', 'boolean'],
        ];
    }

    public function employeeData(): array
    {
        return collect($this->safe()->except(['photo', 'access_level_ids', 'push_to_device_ids', 'push_to_mapped_devices']))->all();
    }
}
