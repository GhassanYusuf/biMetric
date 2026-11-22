<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Every permission in the system, grouped by module. Permissions are
     * global (not team-scoped); only role assignments are per-tenant.
     */
    public const PERMISSIONS = [
        'employees.view', 'employees.create', 'employees.update', 'employees.delete', 'employees.push',
        // Take a fingerprint on a terminal and copy fingerprints into the system — nothing that
        // deletes or moves anyone (owner, 2026-11-17: a GEMS HR account that views and enrols).
        'employees.fingerprint',
        // Scan the roster for duplicate records (owner, 2026-11-22: Administrator only — held by the
        // Administrator level, which always has every permission, and by nobody else unless given).
        'employees.duplicates',
        'devices.view', 'devices.create', 'devices.update', 'devices.delete', 'devices.sync', 'devices.enroll',
        'attendance.view', 'attendance.view.self', 'attendance.sync', 'attendance.waive',
        'holidays.view', 'holidays.manage',
        'departments.view', 'departments.manage',
        'users.view', 'users.create', 'users.update', 'users.delete',
        'roles.view', 'roles.manage',
        'reports.view',
        'settings.view', 'settings.manage',
        'notifications.view', 'notifications.view.self', 'notifications.manage',
        'audit.view', 'logs.view',
        'tenants.view', 'tenants.manage', // platform-only
    ];

    /** Per-tenant roles => the permissions granted to each. */
    public const TENANT_ROLES = [
        'tenant-admin' => '*', // everything except platform tenants.*
        // View only (owner, 2026-11-11: "the HR manager permission should be only view").
        'hr-manager' => [
            'employees.view',
            'attendance.view',
            'holidays.view',
            'departments.view',
            'reports.view', 'notifications.view',
        ],
        'operator' => [
            'devices.view', 'devices.create', 'devices.update', 'devices.sync', 'devices.enroll',
            'employees.view', 'employees.push',
            'attendance.view', 'attendance.sync',
            'notifications.view',
        ],
        'employee' => [
            'attendance.view.self', 'notifications.view.self',
        ],
    ];

    public function run(): void
    {
        // Create the full permission set once (guard: web).
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Global platform super-admin role (team_id null): the super-admin flag
        // bypasses tenant scoping, so this role is mostly for clarity/UX.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $superAdmin = Role::findOrCreate('super-admin', 'web');
        $superAdmin->syncPermissions(Permission::all());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Creates the four tenant-scoped roles for one tenant and grants their permissions. */
    public static function seedTenantRoles(Tenant $tenant): void
    {
        Tenancy::set($tenant); // sets the spatie team id for role creation

        $tenantPermissions = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $p) => ! str_starts_with($p, 'tenants.'),
        ));

        /*
         * The starting permissions of a NEW company's built-in levels only (owner, 2026-11-17:
         * "I can add or remove any permission to everyone, don't hardcode permissions to any
         * roles"). A level that already exists keeps whatever was set on the Users screen;
         * re-running this never resets it. Administrator needs no list at all: it always holds
         * every permission (User::hasPermissionTo), new ones included.
         */
        foreach (self::TENANT_ROLES as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($permissions === '*' ? $tenantPermissions : $permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
