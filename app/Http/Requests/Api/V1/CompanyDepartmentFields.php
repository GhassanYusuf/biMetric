<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Department;
use App\Models\Employee;
use App\Support\Tenancy;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `company_id` and `department_id` as two separate fields in the v1 API (owner, 2026-11-02,
 * option 1 of 2: the API first, a real `companies` table later).
 *
 * Storage is unchanged: `employees.department_id` holds the department when there is one,
 * otherwise the company (a company is a `departments` row with no parent). The website
 * still reads and writes that single field. Here, `company_id` must be a company and
 * `department_id` a real department, and both are folded back into the one column.
 */
trait CompanyDepartmentFields
{
    /** @return array<string, list<mixed>> */
    protected function companyDepartmentRules(): array
    {
        $tenant = Tenancy::id();

        return [
            'company_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('tenant_id', $tenant)->whereNull('parent_id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('tenant_id', $tenant)->whereNotNull('parent_id')],
        ];
    }

    /** The two must agree: a department belongs to exactly one company. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->hasAny(['company_id', 'department_id'])) {
                return;
            }
            $department = $this->filled('department_id') ? Department::query()->with('parent')->find($this->integer('department_id')) : null;
            if ($department === null || ! $this->filled('company_id')) {
                return;
            }
            $company = $department->company();
            if ((int) $company->id !== $this->integer('company_id')) {
                $asked = Department::query()->whereKey($this->integer('company_id'))->value('name');
                $v->errors()->add('department_id', "{$department->name} belongs to {$company->name}, not {$asked}.");
            }
        });
    }

    /**
     * Folds `company_id` / `department_id` into the stored `department_id`.
     *
     * Only what the request names changes (PATCH is partial): a new company without a
     * department drops the old department unless it is in that company; `department_id: null`
     * keeps the company.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function foldCompanyDepartment(array $data, ?Employee $current = null): array
    {
        $hasCompany = $this->exists('company_id');
        $hasDepartment = $this->exists('department_id');
        unset($data['company_id'], $data['department_id']);

        if (! $hasCompany && ! $hasDepartment) {
            return $data;
        }

        $currentNode = $current?->department_id ? Department::query()->with('parent')->find($current->department_id) : null;
        $currentCompany = $currentNode?->company();

        if ($hasDepartment && $this->input('department_id') !== null) {
            $data['department_id'] = $this->integer('department_id');
        } elseif ($hasDepartment) {
            // Department removed: the company stays (the one named, or the current one).
            $data['department_id'] = $hasCompany
                ? ($this->input('company_id') === null ? null : $this->integer('company_id'))
                : $currentCompany?->id;
        } elseif ($this->input('company_id') === null) {
            $data['department_id'] = null;
        } elseif ($currentCompany !== null && (int) $currentCompany->id === $this->integer('company_id')) {
            // Same company named again: their department stays.
            $data['department_id'] = $current->department_id;
        } else {
            $data['department_id'] = $this->integer('company_id');
        }

        return $data;
    }
}
