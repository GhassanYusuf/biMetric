<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\Employee\StoreEmployeeRequest as BaseRequest;
use App\Services\Employees\EmployeeCodeAllocator;
use App\Support\Tenancy;
use Illuminate\Validation\Rule;

/**
 * Create, for the integration API. The SPA's rules, so the two cannot disagree about
 * what a valid employee is, with two differences an outside caller needs:
 *
 *  - `employee_code` is OPTIONAL. The caller usually has its own id (`external_id`) and
 *    no opinion about the number a terminal keypad types; we allocate the next free one.
 *  - `push_to_device_ids` queues the person onto those terminals straight after saving.
 */
class StoreEmployeeRequest extends BaseRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->filled('employee_code')) {
            $this->merge(['employee_code' => (string) app(EmployeeCodeAllocator::class)->next()['code']]);
        }
    }

    public function rules(): array
    {
        return parent::rules() + self::deviceRules();
    }

    /** @return array<string,mixed> */
    public static function deviceRules(): array
    {
        return [
            'push_to_device_ids' => ['sometimes', 'array'],
            // Tenant-pinned: a bare exists rule would accept another company's terminal.
            'push_to_device_ids.*' => ['integer', 'distinct', Rule::exists('devices', 'id')->where('tenant_id', Tenancy::id())],
            'is_active' => ['sometimes', 'boolean'],
            // The card the terminal will be given by the next push. Stored here; it reaches
            // a terminal only through a push. Up to 32 bits, as the user record holds.
            'card_number' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ];
    }

    /** Fields of the employee, without the device instructions. */
    public function employeeData(): array
    {
        return collect($this->safe()->except(['photo', 'access_level_ids', 'push_to_device_ids']))->all();
    }
}
