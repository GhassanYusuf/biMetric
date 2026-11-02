<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('departments.view');
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
