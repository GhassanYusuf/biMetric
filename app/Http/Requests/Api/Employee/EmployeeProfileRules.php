<?php

namespace App\Http\Requests\Api\Employee;

use App\Models\Employee;
use App\Support\Tenancy;
use Illuminate\Validation\Rule;

/**
 * Rules for the Personnel-dialog fields, shared by create and update so the two
 * cannot drift. Every field is optional. None of them reaches a terminal: the
 * device push still builds its record from name, code and card only.
 */
trait EmployeeProfileRules
{
    private const PHONE = 'regex:/^\+?[0-9 ()\-]+$/';

    protected function profileRules(): array
    {
        $tenant = Tenancy::id();

        return [
            'last_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            // "+973 33445566" from the form's code picker (2026-10-12); a leading + is optional
            // so an integration's local numbers still save.
            'mobile_phone' => ['nullable', 'string', 'max:32', self::PHONE],
            'hired_date' => ['nullable', 'date'],
            'birthday' => ['nullable', 'date', 'before:today'],
            /*
             * The terminal keypad password: 1 to 8 digits. OMITTED means "leave it as
             * it is" and an explicit null clears it — the stored value is never sent
             * back to the screen, so an edit that does not touch the field must not
             * wipe it.
             */
            'device_password' => ['nullable', 'string', 'regex:/^\d{1,8}$/'],
            'privilege' => ['nullable', Rule::in(Employee::PRIVILEGES)],

            /*
             * Employment (2026-10-12). Resignation is a date already reached: saving it
             * takes the person off every terminal NOW, so a future date would remove
             * them during their notice period. Nothing runs later to do it on the day.
             */
            'employment_type' => ['sometimes', Rule::in(Employee::EMPLOYMENT_TYPES)],
            'resigned_at' => ['nullable', 'date', 'before_or_equal:today'],

            'job_title' => ['nullable', 'string', 'max:255'],
            'office' => ['nullable', 'string', 'max:255'],
            'education' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'home_phone' => ['nullable', 'string', 'max:32', self::PHONE],
            'political_status' => ['nullable', 'string', 'max:255'],
            'race' => ['nullable', 'string', 'max:255'],
            'home_address' => ['nullable', 'string', 'max:500'],
            'work_address' => ['nullable', 'string', 'max:500'],

            // Tenant-pinned: a bare exists rule would accept another company's id.
            'access_level_ids' => ['sometimes', 'array'],
            'access_level_ids.*' => ['integer', 'distinct', Rule::exists('access_levels', 'id')->where('tenant_id', $tenant)],
            'multi_card_group_id' => ['nullable', 'integer', Rule::exists('multi_card_groups', 'id')->where('tenant_id', $tenant)],

            'valid_period_enabled' => ['nullable', 'boolean'],
            'access_valid_from' => ['nullable', 'date', 'required_if_accepted:valid_period_enabled'],
            'access_valid_to' => array_values(array_filter([
                'nullable', 'date', 'required_if_accepted:valid_period_enabled',
                // Only comparable when there is a start to compare against.
                $this->filled('access_valid_from') ? 'after:access_valid_from' : null,
            ])),
        ];
    }

    /**
     * Digits only, up to 9 — what a terminal keypad can type and a PIN field holds.
     *
     * Applied to NEW codes only: on 2026-09-23, 101 of 356 existing codes looked like
     * "10-12" (imported roster keys). Enforcing this on every save would make those
     * people uneditable, so an edit is only checked when the code actually changes.
     */
    protected function codeFormatRule(): string
    {
        return 'regex:/^\d{1,9}$/';
    }

    public function messages(): array
    {
        return [
            'employee_code.regex' => 'The employee code must be digits only, up to 9.',
            'device_password.regex' => 'The device password must be 1 to 8 digits.',
            'access_valid_to.after' => 'The end date must be after the start date.',
            'resigned_at.before_or_equal' => 'Enter the resignation date once it has been reached — saving it takes them off the terminals straight away.',
            'employment_type.in' => 'Choose full-time or part-time.',
            'mobile_phone.regex' => 'Use digits only, after the country code.',
            'home_phone.regex' => 'Use digits only, after the country code.',
            'access_valid_from.required_if_accepted' => 'Give a start date, or turn the valid period off.',
            'access_valid_to.required_if_accepted' => 'Give an end date, or turn the valid period off.',
        ];
    }
}
