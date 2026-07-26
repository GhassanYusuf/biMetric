<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('devices.view');
    }

    public function view(User $user, Device $device): bool
    {
        return $user->can('devices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('devices.create');
    }

    public function update(User $user, Device $device): bool
    {
        return $user->can('devices.update');
    }

    public function delete(User $user, Device $device): bool
    {
        return $user->can('devices.delete');
    }

    public function sync(User $user, Device $device): bool
    {
        return $user->can('devices.sync');
    }

    public function enroll(User $user, Device $device): bool
    {
        return $user->can('devices.enroll');
    }
}
