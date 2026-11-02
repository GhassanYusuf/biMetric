<?php

namespace App\Http\Requests\Api\Company;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A company (2026-11-03): until then a department with no parent. */
class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy is enforced in the controller
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('companies', 'name')->where('tenant_id', Tenancy::id())->whereNull('deleted_at'),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'manager_id' => ['nullable', Rule::exists('employees', 'id')->where('tenant_id', Tenancy::id())],
        ];
    }
}
