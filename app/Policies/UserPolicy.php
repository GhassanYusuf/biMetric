<?php

namespace App\Policies;

use App\Models\User;

/**
 * The Users screen (2026-10-19). Only users of the caller's own company reach these
 * checks: User carries BelongsToTenant, so route binding cannot find another company's.
 * Platform super-admins are not company users and are never listed or edited here.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user, User $target): bool
    {
        return $user->can('users.update') && ! $target->is_super_admin;
    }

    public function delete(User $user, User $target): bool
    {
        return $user->can('users.delete') && ! $target->is_super_admin;
    }
}
