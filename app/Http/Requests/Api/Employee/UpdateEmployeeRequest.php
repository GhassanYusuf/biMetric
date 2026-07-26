<?php

namespace App\Http\Requests\Api\Employee;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $employeeId = $this->route('employee')->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'employee_code' => [
                'required', 'string', 'max:255',
                Rule::unique('employees', 'employee_code')
                    ->where('tenant_id', Tenancy::id())
                    ->ignore($employeeId),
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
