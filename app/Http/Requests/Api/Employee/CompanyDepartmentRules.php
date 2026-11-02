<?php

namespace App\Http\Requests\Api\Employee;

use App\Models\Company;
use App\Models\Department;
use App\Support\Tenancy;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An employee's company and department (2026-11-03: two columns since the companies table).
 * Shared by the website's forms and the v1 API, so they cannot disagree.
 *
 * Sent together, the department must be in the company. Either alone is fine: a department
 * sets its company, and a company that does not hold the current department clears it. That
 * part is the model's (Employee::booted), so imports follow it too.
 */
trait CompanyDepartmentRules
{
    /** @return array<string, list<mixed>> */
    protected function companyDepartmentRules(): array
    {
        $tenant = Tenancy::id();

        return [
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->hasAny(['company_id', 'department_id']) || ! $this->filled('department_id') || ! $this->filled('company_id')) {
                return;
            }
            $department = Department::query()->with('company:id,name')->find($this->integer('department_id'));
            if ($department !== null && (int) $department->company_id !== $this->integer('company_id')) {
                $asked = Company::query()->whereKey($this->integer('company_id'))->value('name');
                $v->errors()->add('department_id', "{$department->name} belongs to {$department->company?->name}, not {$asked}.");
            }
        });
    }

    /**
     * `"Male"`, `"MALE"` and `" male "` are `male` (owner, 2026-11-02): other systems
     * capitalise it. Anything else is still refused by the rule.
     */
    protected function normaliseGender(): void
    {
        if (is_string($this->input('gender'))) {
            $this->merge(['gender' => strtolower(trim($this->input('gender')))]);
        }
    }
}
