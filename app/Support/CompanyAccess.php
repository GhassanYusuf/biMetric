<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Which companies the signed-in user may see (owner, 2026-11-05: "in permission I need to hide
 * some companies for someone").
 *
 * null = every company: no user is signed in (queue jobs, the scheduler, the console), or the
 * user has no rows in user_companies, which is every user until an administrator ticks
 * companies for them. A list = only those companies; employees in NO company and punches
 * linked to nobody are hidden too (owner's choice, 2026-11-05).
 *
 * Read by CompanyScope on Company, Department, Employee and AttendanceLog, so every screen,
 * report, export and /api/v1 call made by that user (an integration's token belongs to a user)
 * sees only those companies. Remembered per user for the request.
 */
final class CompanyAccess
{
    /** @var array<int, list<int>|null> */
    private static array $cache = [];

    private static bool $resolving = false;

    /** @return list<int>|null */
    public static function ids(): ?array
    {
        // Resolving the user runs queries of its own; never scope those.
        if (self::$resolving) {
            return null;
        }
        self::$resolving = true;
        try {
            $user = Auth::guard('sanctum')->user() ?? Auth::user();
        } finally {
            self::$resolving = false;
        }
        if (! $user instanceof User || $user->isSuperAdmin()) {
            return null;
        }

        return self::$cache[$user->id] ??= self::load($user->id);
    }

    public static function limited(): bool
    {
        return self::ids() !== null;
    }

    /** @return list<int>|null the user's companies, or null for every company */
    public static function for(User $user): ?array
    {
        return self::load($user->id);
    }

    public static function forget(?int $userId = null): void
    {
        if ($userId === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$userId]);
        }
    }

    private static ?bool $tableExists = null;

    /** @return list<int>|null */
    private static function load(int $userId): ?array
    {
        // Until the migration has run there is no limit to read (2026-11-05 deploy order).
        if (! (self::$tableExists ??= \Illuminate\Support\Facades\Schema::hasTable('user_companies'))) {
            return null;
        }
        $ids = DB::table('user_companies')->where('user_id', $userId)->orderBy('company_id')->pluck('company_id')->map(fn ($id) => (int) $id)->all();

        return $ids === [] ? null : $ids;
    }
}
