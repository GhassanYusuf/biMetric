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

    /**
     * Take a fingerprint on a terminal, see the person's enrollment, copy their fingerprints into
     * the system (2026-11-17). Granted by employees.fingerprint, or by employees.push which does all
     * that and more. Deleting fingerprints or cards, moving or removing people stays on push.
     */
    public function enrollFingerprint(User $user, Employee $employee): bool
    {
        return $user->can('employees.fingerprint') || $user->can('employees.push');
    }
}
