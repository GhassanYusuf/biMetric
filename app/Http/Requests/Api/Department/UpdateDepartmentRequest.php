<?php

namespace App\Http\Requests\Api\Department;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $department = $this->route('department');
        // Moving it to another company is allowed; leaving company_id out keeps it where it is.
        $companyId = $this->filled('company_id') ? $this->integer('company_id') : (int) $department->company_id;

        return [
            'company_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('companies', 'id')->where('tenant_id', Tenancy::id())->whereNull('deleted_at'),
            ],
            // Unique per company: "Finance" may exist in two companies.
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('departments', 'name')
                    ->where('tenant_id', Tenancy::id())
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->ignore($department->id),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'manager_id' => [
                'nullable',
                Rule::exists('employees', 'id')->where('tenant_id', Tenancy::id()),
            ],
        ];
    }
}
