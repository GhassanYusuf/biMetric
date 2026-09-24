<?php

namespace App\Policies;

use App\Models\AccessLevel;
use App\Models\User;

/**
 * Read by anyone who can see employees — the edit form lists them. Managed by
 * anyone who can edit employees, since assigning one is part of that edit. No
 * separate permission yet: the rows are database-only and reach no panel.
 */
class AccessLevelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('employees.view');
    }

    public function create(User $user): bool
    {
        return $user->can('employees.update');
    }

    public function update(User $user, AccessLevel $model): bool
    {
        return $user->can('employees.update');
    }

    public function delete(User $user, AccessLevel $model): bool
    {
        return $user->can('employees.update');
    }
}
