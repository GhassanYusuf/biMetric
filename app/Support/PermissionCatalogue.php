<?php

namespace App\Support;

use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * What the Users screen's "Permission levels" grid shows (owner, 2026-10-19: "users tab
 * that has permission levels and add users"; chose editable levels, admin locked).
 *
 * A permission level is a spatie Role, per company (team = tenant). The permission
 * NAMES are fixed in RolesAndPermissionsSeeder::PERMISSIONS and checked all over the
 * code; this class only gives each one an operator-facing label and a group, so a new
 * permission added to the seeder without a label here still appears (under its name).
 *
 * The four seeded levels are BUILT IN: they cannot be renamed or deleted, because the
 * seeder re-creates them by name for every company. `tenant-admin` is also LOCKED to
 * every permission, so nobody can edit the last way back into this screen out of
 * existence.
 */
final class PermissionCatalogue
{
    public const ADMIN = 'tenant-admin';

    /** Built-in level => the name shown on screen. */
    public const BUILT_IN = [
        'tenant-admin' => 'Administrator',
        'hr-manager' => 'HR manager',
        'operator' => 'Operator',
        'employee' => 'Employee',
    ];

    /** Group => [permission => label]. Order is the order on screen. */
    private const GROUPS = [
        'Employees' => [
            'employees.view' => 'See employees',
            'employees.create' => 'Add employees',
            'employees.update' => 'Edit employees',
            'employees.delete' => 'Delete employees',
            'employees.push' => 'Send employees to terminals, set terminal admin',
        ],
        'Attendance' => [
            'attendance.view' => "See everyone's attendance",
            'attendance.view.self' => 'See own attendance only',
            'attendance.sync' => 'Read punches from terminals',
            'attendance.waive' => 'Excuse absences (waivers)',
        ],
        'Reports' => [
            'reports.view' => 'Reports, statements and exports',
        ],
        'Holidays' => [
            'holidays.view' => 'See holidays and attendance rules',
            'holidays.manage' => 'Change holidays and attendance rules',
        ],
        'Companies & departments' => [
            'departments.view' => 'See companies and departments',
            'departments.manage' => 'Change companies and departments',
        ],
        'Terminals' => [
            'devices.view' => 'See terminals',
            'devices.create' => 'Add terminals',
            'devices.update' => 'Change terminals and clear their punches',
            'devices.delete' => 'Remove terminals',
            'devices.sync' => 'Read terminals now',
            'devices.enroll' => 'Enrol fingerprints and cards',
        ],
        'Users & levels' => [
            'users.view' => 'See users',
            'users.create' => 'Add users',
            'users.update' => 'Edit users, reset passwords, deactivate',
            'users.delete' => 'Delete users',
            'roles.view' => 'See permission levels',
            'roles.manage' => 'Create and edit permission levels',
        ],
        'Company settings' => [
            'settings.view' => 'See settings',
            'settings.manage' => 'Change settings',
        ],
        'Alerts & audit' => [
            'notifications.view' => "See the company's alerts",
            'notifications.view.self' => 'See own alerts only',
            'notifications.manage' => 'Manage alerts',
            'audit.view' => 'See the audit log',
        ],
    ];

    /** Every permission a company level may hold: all of them except platform `tenants.*`. */
    public static function tenantPermissions(): array
    {
        return array_values(array_filter(
            RolesAndPermissionsSeeder::PERMISSIONS,
            fn (string $p) => ! str_starts_with($p, 'tenants.'),
        ));
    }

    /** @return list<array{group:string, permissions:list<array{name:string,label:string}>}> */
    public static function groups(): array
    {
        $known = self::tenantPermissions();
        $out = [];
        $placed = [];
        foreach (self::GROUPS as $group => $permissions) {
            $rows = [];
            foreach ($permissions as $name => $label) {
                if (in_array($name, $known, true)) {
                    $rows[] = ['name' => $name, 'label' => $label];
                    $placed[] = $name;
                }
            }
            if ($rows !== []) {
                $out[] = ['group' => $group, 'permissions' => $rows];
            }
        }

        $unlabelled = array_values(array_diff($known, $placed));
        if ($unlabelled !== []) {
            $out[] = ['group' => 'Other', 'permissions' => array_map(fn ($n) => ['name' => $n, 'label' => $n], $unlabelled)];
        }

        return $out;
    }

    public static function label(string $role): string
    {
        return self::BUILT_IN[$role] ?? $role;
    }

    public static function isBuiltIn(string $role): bool
    {
        return array_key_exists($role, self::BUILT_IN);
    }
}
