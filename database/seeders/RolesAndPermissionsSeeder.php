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
        'devices.view', 'devices.create', 'devices.update', 'devices.delete', 'devices.sync', 'devices.enroll',
        'attendance.view', 'attendance.view.self', 'attendance.sync', 'attendance.waive',
        'holidays.view', 'holidays.manage',
        'departments.view', 'departments.manage',
        'users.view', 'users.create', 'users.update', 'users.delete',
        'roles.view', 'roles.manage',
        'reports.view',
        'settings.view', 'settings.manage',
        'notifications.view', 'notifications.view.self', 'notifications.manage',
        'audit.view',
        'tenants.view', 'tenants.manage', // platform-only
    ];

    /** Per-tenant roles => the permissions granted to each. */
    public const TENANT_ROLES = [
        'tenant-admin' => '*', // everything except platform tenants.*
        'hr-manager' => [
            'employees.view', 'employees.create', 'employees.update', 'employees.delete',
            'attendance.view', 'attendance.waive',
            'holidays.view', 'holidays.manage',
            'departments.view', 'departments.manage',
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

        foreach (self::TENANT_ROLES as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $grant = $permissions === '*' ? $tenantPermissions : $permissions;
            $role->syncPermissions($grant);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
