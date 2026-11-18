<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses every request from a user who must change their password first (owner, 2026-11-18),
 * except the ones needed to do it: who am I, change my password, sign out (routes under auth/,
 * which this middleware is not on). Enforced here, not only on screen, so the starting password an
 * administrator handed out cannot be used for anything but choosing a new one.
 *
 * The 403 carries reason=password_change_required so the website can show the change screen.
 */
class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->getAttribute('must_change_password')) {
            return ApiResponse::error(
                'Choose a new password before you continue.',
                ['reason' => 'password_change_required'],
                403,
            );
        }

        return $next($request);
    }
}
