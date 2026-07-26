<?php

namespace App\Http\Requests\Api\Holiday;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => [
                'required', 'date',
                Rule::unique('holidays', 'date')
                    ->where('tenant_id', Tenancy::id())
                    ->ignore($this->route('holiday')->id),
            ],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
