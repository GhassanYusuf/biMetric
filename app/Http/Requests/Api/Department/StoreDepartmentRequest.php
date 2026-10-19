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
                Rule::unique('departments', 'name')->where('tenant_id', Tenancy::id())
                    ->where(fn ($q) => $this->filled('parent_id') ? $q->where('parent_id', $this->integer('parent_id')) : $q->whereNull('parent_id'))
                    ->whereNull('deleted_at'),
            ],
            // Unique per parent: "Finance" may exist under two companies.
            'parent_id' => [
                'nullable',
                Rule::exists('departments', 'id')->where('tenant_id', Tenancy::id())->whereNull('deleted_at'),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'manager_id' => [
                'nullable',
                Rule::exists('employees', 'id')->where('tenant_id', Tenancy::id()),
            ],
        ];
    }
}
