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

        // Keep spatie role/permission lookups scoped to the same tenant, and drop
        // this PROCESS's loaded permission list so a persistent process (Octane,
        // queue worker, same-process tests) re-reads it under the new team.
        //
        // In-memory only — clearPermissionsCollection(), NOT forgetCachedPermissions().
        // The shared cache entry holds every permission with no team in it, so
        // deleting it on a tenant switch protected nothing; and because this runs on
        // EVERY request (ResolveTenant), it deleted the entry hundreds of times a
        // day. Overlapping requests then all rebuilt it at once, and SQL Server
        // rejected the duplicate key in the cache table — the 500 "cache_key_primary"
        // that hit a live page refresh while Ali Mohamed Jaafar was being saved
        // (2026-10-11; 28 that day, 130 since 2026-09-22).
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(self::$tenantId);
        $registrar->clearPermissionsCollection();
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
