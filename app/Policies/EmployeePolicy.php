<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Authorization for employee records. Tenant isolation is already guaranteed
 * by the TenantScope global scope (a record from another tenant is never even
 * retrievable / route-bound), so these checks only concern the acting user's
 * permissions. Super-admins short-circuit via the Gate::before rule.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('employees.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->can('employees.view');
    }

    public function create(User $user): bool
    {
        return $user->can('employees.create');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->can('employees.update');
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->can('employees.delete');
    }

    public function pushToDevice(User $user, Employee $employee): bool
    {
        return $user->can('employees.push');
    }
}
