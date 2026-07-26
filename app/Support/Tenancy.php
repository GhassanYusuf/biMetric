<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Request-scoped holder for "which tenant are we acting as right now".
 *
 * The tenant is resolved server-side (from the authenticated user, or an
 * impersonating super-admin) and NEVER from client-submitted input, per
 * docs/ENGINEERING_STANDARDS.md. Everything tenant-scoped reads from here:
 * the TenantScope global query scope, the BelongsToTenant trait, and the
 * spatie permission "team" resolver.
 */
class Tenancy
{
    private static ?int $tenantId = null;

    /**
     * When true, tenant scoping is bypassed entirely (platform super-admin
     * acting across tenants, or console/maintenance contexts). Kept explicit
     * so it can never be toggled by accident from request input.
     */
    private static bool $spanAllTenants = false;

    public static function set(int|Tenant $tenant): void
    {
        self::$tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;
        self::$spanAllTenants = false;

        // Keep spatie role/permission lookups scoped to the same tenant, and
        // drop any permissions cached under a previous team so a persistent
        // process (Octane, queue worker, or same-process tests) can never leak
        // one tenant's permissions into another's checks.
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(self::$tenantId);
        $registrar->forgetCachedPermissions();
    }

    public static function id(): ?int
    {
        return self::$tenantId;
    }

    public static function check(): bool
    {
        return self::$tenantId !== null;
    }

    public static function clear(): void
    {
        self::$tenantId = null;
        self::$spanAllTenants = false;
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    /** Run a callback with tenant scoping disabled (platform-level work). */
    public static function spanAllTenants(): void
    {
        self::$spanAllTenants = true;
    }

    public static function spanningAllTenants(): bool
    {
        return self::$spanAllTenants;
    }

    /** True when the TenantScope should actually filter queries. */
    public static function shouldScope(): bool
    {
        return self::$tenantId !== null && ! self::$spanAllTenants;
    }
}
