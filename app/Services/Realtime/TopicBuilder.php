<?php

namespace App\Services\Realtime;

/**
 * Single source of truth for MQTT topic strings, so the tenant/user scoping
 * required by docs/ENGINEERING_STANDARDS.md is applied identically on the
 * publish side (here) and the subscribe side (the React client mirrors these).
 *
 * Every topic is prefixed with the tenant id so a subscriber can never receive
 * another tenant's events. Wildcard subscriptions must always pin the tenant
 * segment (e.g. tenant/5/#), never a leading wildcard.
 */
class TopicBuilder
{
    public static function userNotifications(int $tenantId, int $userId): string
    {
        return "tenant/{$tenantId}/user/{$userId}/notifications";
    }

    public static function roleAlerts(int $tenantId, string $role): string
    {
        return "tenant/{$tenantId}/role/{$role}/alerts";
    }

    public static function module(int $tenantId, string $module, int|string $recordId): string
    {
        return "tenant/{$tenantId}/module/{$module}/{$recordId}";
    }

    public static function dashboard(int $tenantId, int $userId): string
    {
        return "tenant/{$tenantId}/dashboard/{$userId}";
    }

    /** The tenant-wide prefix a client may subscribe to (still tenant-pinned). */
    public static function tenantWildcard(int $tenantId): string
    {
        return "tenant/{$tenantId}/#";
    }
}
