<?php

namespace App\Http\Requests\Api\Device;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'ip_address' => ['required', 'ip'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'protocol' => ['required', Rule::in([Device::PROTOCOL_BINARY, Device::PROTOCOL_CSL_HTTP])],
            'comm_key' => ['nullable', 'string', 'max:255'],
            'web_username' => ['nullable', 'string', 'max:255'],
            'web_password' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
