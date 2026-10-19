<?php

namespace App\Http\Requests\Api\Device;

use App\Models\Device;
use App\Rules\ValidTimezone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceRequest extends FormRequest
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
            'protocol' => ['required', Rule::in(Device::protocols())],
            'model' => ['nullable', 'string', 'max:255'],
            'comm_key' => ['nullable', 'string', 'max:255'],
            'web_username' => ['nullable', 'string', 'max:255'],
            'web_password' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            /*
             | This terminal's OWN time zone, for a machine in another country (2026-10-05).
             | null = inherit system.timezone, which is what almost every terminal should
             | be left at. Not sent to the device as a zone — a terminal has no zone
             | database — it is the offset the device clock is written in.
             */
            'timezone' => ['nullable', new ValidTimezone()],
        ];
    }
}
