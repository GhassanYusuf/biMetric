<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Department;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which part of the organisation a list, report or export covers (2026-11-03).
 *
 * Until the companies table, every screen and report took one tree node (`department_id`,
 * which could be a company) and expanded it to everything beneath. Now it is a company
 * and/or a department, read from the two employee columns, in one place:
 *
 *   company_id      a company: everyone in it, its departments included
 *   company_id=none people in no company (the tree's "Unassigned")
 *   department_id   one department
 */
final class OrgScope
{
    public function __construct(
        public readonly int|string|null $companyId = null,
        public readonly ?int $departmentId = null,
    ) {}

    /** From validated input with keys company_id / department_id (missing = everyone). */
    public static function from(array $input): self
    {
        $company = $input['company_id'] ?? null;
        $department = $input['department_id'] ?? null;

        return new self(
            $company === null || $company === '' ? null : ($company === 'none' ? 'none' : (int) $company),
            $department === null || $department === '' ? null : (int) $department,
        );
    }

    /** The validation rules for the two keys. */
    public static function rules(): array
    {
        return [
            'company_id' => ['nullable', 'regex:/^(none|\d+)$/'],
            'department_id' => ['nullable', 'integer'],
        ];
    }

    public function isEveryone(): bool
    {
        return $this->companyId === null && $this->departmentId === null;
    }

    /** Narrows an employees query. */
    public function apply(Builder $employees): Builder
    {
        return $employees
            ->when($this->companyId === 'none', fn ($q) => $q->whereNull('company_id'))
            ->when(is_int($this->companyId), fn ($q) => $q->where('company_id', $this->companyId))
            ->when($this->departmentId !== null, fn ($q) => $q->where('department_id', $this->departmentId));
    }

    /**
     * The plain name, as a report's title prints it: "Camelot", "PROMOSEVEN › Finance",
     * "Unassigned (no company)", or null for everyone.
     */
    public function name(): ?string
    {
        if ($this->departmentId !== null) {
            return Department::query()->with('company:id,name')->find($this->departmentId)?->path() ?? "#{$this->departmentId}";
        }
        if ($this->companyId === 'none') {
            return 'Unassigned (no company)';
        }
        if (is_int($this->companyId)) {
            return Company::query()->whereKey($this->companyId)->value('name') ?? "#{$this->companyId}";
        }

        return null;
    }

    /** For a "filtered by" line: "Company: Camelot", "Department: PROMOSEVEN › Finance", or null. */
    public function label(): ?string
    {
        $name = $this->name();

        return match (true) {
            $name === null, $this->companyId === 'none' => $name,
            $this->departmentId !== null => "Department: {$name}",
            default => "Company: {$name}",
        };
    }

    /** For storing with an export, so the file says what it covered. */
    public function toArray(): array
    {
        return ['company_id' => $this->companyId, 'department_id' => $this->departmentId];
    }
}
