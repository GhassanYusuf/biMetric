<?php

namespace App\Policies;

use App\Models\MultiCardGroup;
use App\Models\User;

/**
 * Read by anyone who can see employees — the edit form lists them. Managed by
 * anyone who can edit employees, since assigning one is part of that edit. No
 * separate permission yet: the rows are database-only and reach no panel.
 */
class MultiCardGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('employees.view');
    }

    public function create(User $user): bool
    {
        return $user->can('employees.update');
    }

    public function update(User $user, MultiCardGroup $model): bool
    {
        return $user->can('employees.update');
    }

    public function delete(User $user, MultiCardGroup $model): bool
    {
        return $user->can('employees.update');
    }
}
