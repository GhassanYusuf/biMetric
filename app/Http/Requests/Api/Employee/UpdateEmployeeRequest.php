<?php

namespace App\Http\Requests\Api\Employee;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    use EmployeeProfileRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $employeeId = $this->route('employee')->id;
        // Only a CHANGED code must meet the digits rule — see codeFormatRule().
        $codeChanged = (string) $this->input('employee_code') !== (string) $this->route('employee')->employee_code;

        return $this->profileRules() + [
            'name' => ['required', 'string', 'max:255'],
            'employee_code' => [
                'required', 'string', 'max:255',
                ...($codeChanged ? [$this->codeFormatRule()] : []),
                Rule::unique('employees', 'employee_code')
                    ->where('tenant_id', Tenancy::id())
                    ->ignore($employeeId),
            ],
            // The caller's own id for this person (integration API). Unique per tenant.
            'external_id' => [
                'nullable', 'string', 'max:128',
                Rule::unique('employees', 'external_id')->where('tenant_id', Tenancy::id())->ignore($employeeId),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'department_id' => [
                'nullable',
                Rule::exists('departments', 'id')->where('tenant_id', Tenancy::id()),
            ],
            'is_active' => ['boolean'],
            'photo' => ['nullable', 'image', 'max:4096'],
        ];
    }
}
