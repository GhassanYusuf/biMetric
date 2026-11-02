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
            // Every department belongs to a company (2026-11-03); departments are flat under it.
            'company_id' => [
                'required', 'integer',
                Rule::exists('companies', 'id')->where('tenant_id', Tenancy::id())->whereNull('deleted_at'),
            ],
            // Unique per company: "Finance" may exist in two companies.
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('departments', 'name')->where('tenant_id', Tenancy::id())
                    ->where('company_id', $this->integer('company_id'))
                    ->whereNull('deleted_at'),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'manager_id' => [
                'nullable',
                Rule::exists('employees', 'id')->where('tenant_id', Tenancy::id()),
            ],
        ];
    }
}
