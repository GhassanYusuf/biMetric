<?php

namespace App\Http\Requests\Api\Employee;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    use EmployeeProfileRules;

    public function authorize(): bool
    {
        return true; // policy is enforced in the controller
    }

    public function rules(): array
    {
        return $this->profileRules() + [
            'name' => ['required', 'string', 'max:255'],
            // Uniqueness is per-tenant: the raw unique rule ignores the global
            // scope, so we pin tenant_id explicitly to avoid false collisions
            // with another company's identical employee code.
            'employee_code' => [
                'required', 'string', 'max:255', $this->codeFormatRule(),
                Rule::unique('employees', 'employee_code')->where('tenant_id', Tenancy::id()),
            ],
            // The caller's own id for this person (integration API). Unique per tenant.
            'external_id' => [
                'nullable', 'string', 'max:128',
                Rule::unique('employees', 'external_id')->where('tenant_id', Tenancy::id()),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'department_id' => [
                'nullable',
                Rule::exists('departments', 'id')->where('tenant_id', Tenancy::id()),
            ],
            'photo' => ['nullable', 'image', 'max:4096'],
        ];
    }
}
