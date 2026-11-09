<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * "See error logs" (logs.view) for the Error logs screen (owner, 2026-11-09). Given to the
 * Administrator level of every company account, which holds every permission by design
 * (PermissionCatalogue: locked); any other level gets it only by an administrator ticking it.
 * The seeder lists it too, for fresh installs.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('logs.view', 'web');
        foreach (Role::query()->where('name', 'tenant-admin')->get() as $role) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'logs.view')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
