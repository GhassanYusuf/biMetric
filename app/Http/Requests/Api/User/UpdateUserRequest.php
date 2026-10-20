<?php

namespace App\Http\Requests\Api\User;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Edit a user's name, e-mail, level, linked employee or active state. The password has its own endpoint. */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('user')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'role' => ['sometimes', 'required', 'string', Rule::exists('roles', 'name')->where('team_id', Tenancy::id())->where('guard_name', 'web')],
            'employee_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('employees', 'id')->where('tenant_id', Tenancy::id()),
                Rule::unique('users', 'employee_id')->ignore($id),
            ],
            'is_active' => ['sometimes', 'boolean'],
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
