<?php

namespace App\Http\Requests\Api\User;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Add a user to the caller's company (owner, 2026-10-19). The administrator sets the
 * starting password and tells the person; there is no invitation e-mail.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Unique across ALL companies: login is by e-mail alone.
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(8)],
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('team_id', Tenancy::id())->where('guard_name', 'web')],
            'employee_id' => [
                'nullable', 'integer',
                Rule::exists('employees', 'id')->where('tenant_id', Tenancy::id()),
                Rule::unique('users', 'employee_id'),
            ],
            'is_active' => ['sometimes', 'boolean'],
            // Owner, 2026-11-05: per-user delete switch, and the companies they may see
            // (null or [] = every company). UserController refuses giving more than one has.
            'can_delete' => ['sometimes', 'boolean'],
            // Force a password change at the next sign-in (2026-11-18).
            'must_change_password' => ['sometimes', 'boolean'],
            'company_ids' => ['sometimes', 'nullable', 'array'],
            'company_ids.*' => ['integer', 'distinct', Rule::exists('companies', 'id')->where('tenant_id', Tenancy::id())->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Somebody already signs in with this e-mail.',
            'employee_id.unique' => 'This employee already has a user.',
            'role.exists' => 'Choose a permission level.',
        ];
    }
}
