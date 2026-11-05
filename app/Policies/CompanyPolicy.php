<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    /**
     * The LIST (names and head counts) is also the filter tree on Attendance and Reports, so
     * whoever may see attendance or reports may read it (owner, 2026-11-05: HR sees companies
     * and departments on the Attendance page without the Companies or Employees tab). The
     * Companies tab itself stays behind departments.view (nav and route), and changing
     * anything behind departments.manage.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('departments.view') || $user->can('attendance.view') || $user->can('reports.view');
    }

    public function view(User $user, Company $company): bool
    {
        return $user->can('departments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('departments.manage');
    }

    public function update(User $user, Company $company): bool
    {
        return $user->can('departments.manage');
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->can('departments.manage');
    }
}
