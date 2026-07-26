<?php

namespace App\Policies;

use App\Models\Holiday;
use App\Models\User;

class HolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('holidays.view');
    }

    public function view(User $user, Holiday $holiday): bool
    {
        return $user->can('holidays.view');
    }

    public function create(User $user): bool
    {
        return $user->can('holidays.manage');
    }

    public function update(User $user, Holiday $holiday): bool
    {
        return $user->can('holidays.manage');
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return $user->can('holidays.manage');
    }
}
