<?php

namespace App\Http\Requests\Api\Company;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy is enforced in the controller
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('companies', 'name')->where('tenant_id', Tenancy::id())->whereNull('deleted_at')
                    ->ignore($this->route('company')->id),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'manager_id' => ['nullable', Rule::exists('employees', 'id')->where('tenant_id', Tenancy::id())],
        ];
    }
}
