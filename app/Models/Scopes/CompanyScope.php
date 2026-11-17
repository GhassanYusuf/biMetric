<?php

namespace App\Models\Scopes;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Support\CompanyAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * A user limited to some companies sees only those (CompanyAccess, owner 2026-11-05). Beside
 * TenantScope, the same way: every query on these models is constrained, so no screen,
 * report, export or API endpoint has to remember to.
 *
 *   Company        its id is one of theirs
 *   Department     its company is one of theirs
 *   Employee       their company is one of theirs (no company = hidden)
 *   AttendanceLog  its employee is one of theirs (linked to nobody = hidden)
 *
 * Does nothing for a user with every company, or with nobody signed in (jobs, console).
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $ids = CompanyAccess::ids();
        if ($ids === null) {
            return;
        }

        $table = $model->getTable();

        match (true) {
            $model instanceof Company => $builder->whereIn("{$table}.id", $ids),
            // A terminal in no company is hidden from a limited user too: it is nobody's to them.
            $model instanceof Department, $model instanceof Employee, $model instanceof Device => $builder->whereIn("{$table}.company_id", $ids),
            $model instanceof AttendanceLog => $builder->whereIn("{$table}.employee_id", fn ($q) => $q
                ->select('id')->from('employees')->whereIn('company_id', $ids)),
            default => null,
        };
    }
}
