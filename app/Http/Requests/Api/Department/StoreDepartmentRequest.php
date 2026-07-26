<?php

namespace App\Http\Requests\Api\Department;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('departments', 'name')->where('tenant_id', Tenancy::id())->whereNull('deleted_at'),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'manager_id' => [
                'nullable',
                Rule::exists('employees', 'id')->where('tenant_id', Tenancy::id()),
            ],
        ];
    }
}
