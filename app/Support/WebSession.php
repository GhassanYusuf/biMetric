<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * How long a WEBSITE sign-in lasts (owner, 2026-11-11, after asking for best practice: "build it
 * with 15 minutes and 8 hours"). OWASP and NIST SP 800-63B both put an idle timeout of 15–30
 * minutes and a re-sign-in at least every working day on a system like this one (CPRs,
 * fingerprints, terminal control).
 *
 *   idle      15 minutes without a request the person made (background polls do not count)
 *   maximum   8 hours from signing in, active or not
 *
 * Only tokens named "web" (the sign-in page's). Integration and Postman tokens are not a person
 * at a desk: they keep their own expiry (integration:token --days, POST /api/v1/tokens).
 *
 * Enforced on the server, in Sanctum's validity check (a client timer alone can be bypassed);
 * the page also warns two minutes before the idle limit (useIdleSignOut).
 */
final class WebSession
{
    public const NAME = 'web';

    public const IDLE_MINUTES = 15;

    public const MAX_HOURS = 8;

    private static ?bool $hasColumn = null;

    /** Sanctum::authenticateAccessTokensUsing: is this token still allowed? */
    public static function allows($token, bool $isValid): bool
    {
        if (! $isValid || ! $token instanceof PersonalAccessToken || $token->name !== self::NAME) {
            return $isValid;
        }

        // Older sign-ins have no expiry of their own: 8 hours from when they were made.
        if ($token->created_at !== null && $token->created_at->lt(now()->subHours(self::MAX_HOURS))) {
            $token->delete();

            return false;
        }

        // The person's last own request; before the migration (or for an older token), its last use.
        $last = (self::column() ? $token->last_active_at : null) ?? $token->last_used_at ?? $token->created_at;
        if ($last !== null && \Illuminate\Support\Carbon::parse($last)->lt(now()->subMinutes(self::IDLE_MINUTES))) {
            $token->delete();

            return false;
        }

        return true;
    }

    /** After a request the person made (not a background poll), their sign-in is active now. */
    public static function touch(Request $request): void
    {
        if ($request->header('X-Background') === '1' || ! self::column()) {
            return;
        }
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || $token->name !== self::NAME) {
            return;
        }
        // Once a minute is precise enough, and saves a write per click.
        if ($token->last_active_at === null || \Illuminate\Support\Carbon::parse($token->last_active_at)->lt(now()->subMinute())) {
            $token->forceFill(['last_active_at' => now()])->saveQuietly();
        }
    }

    private static function column(): bool
    {
        return self::$hasColumn ??= Schema::hasColumn('personal_access_tokens', 'last_active_at');
    }
}
