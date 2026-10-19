<?php

namespace App\Support;

/**
 * What an integration token may do (Sanctum token abilities), narrower than the user's
 * own permissions: a token can only ever do what BOTH its abilities and its user's
 * role allow. Read-only reporting software gets `attendance:read` and nothing else.
 *
 * A SPA login token carries `*` and passes every check here — by design, so the same
 * credentials work for scripting; issue a scoped token for anything unattended.
 */
final class IntegrationAbilities
{
    public const ALL = [
        'employees:read' => 'List and read employees and departments.',
        'employees:write' => 'Create, change and delete employees, departments and photos.',
        'devices:read' => 'List terminals, their health and capabilities; read commands and enrollment sessions.',
        'devices:write' => 'Push and remove people on terminals, enroll fingerprints and cards, trigger syncs.',
        'attendance:read' => 'Read punches and attendance summaries.',
        'webhooks:manage' => 'Create and manage webhook endpoints and read their deliveries.',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::ALL);
    }
}
