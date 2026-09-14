<?php

namespace App\Http\Requests\Api\Device;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an operator's request to read a device's identity BEFORE it is saved.
 *
 * Same connection fields the store form collects, minus everything that is not
 * needed to open a session. No name, no location — this only reaches out and asks
 * the hardware what it is.
 */
class IdentifyDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ip_address' => ['required', 'ip'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'protocol' => ['required', Rule::in(Device::protocols())],
            'comm_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}
