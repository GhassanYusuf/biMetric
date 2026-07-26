<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\ApiResponse;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the acting tenant for the request AFTER authentication. The
 * tenant is derived from the authenticated user server-side and is NEVER read
 * from client input — the one exception is a super-admin who may explicitly
 * impersonate a tenant via the X-Tenant-Id header, which is validated against
 * their super-admin status.
 *
 * Must run after `auth:sanctum`.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request); // unauthenticated routes resolve no tenant
        }

        if ($user->isSuperAdmin()) {
            // Platform admin: span all tenants unless explicitly scoping to one.
            $impersonated = $request->header('X-Tenant-Id');

            if ($impersonated !== null) {
                $tenant = Tenant::find((int) $impersonated);
                if ($tenant === null) {
                    return ApiResponse::error('Tenant not found.', [], 404);
                }
                Tenancy::set($tenant);
            } else {
                Tenancy::spanAllTenants();
            }

            return $next($request);
        }

        // Regular users are hard-bound to their own tenant.
        if ($user->tenant_id === null) {
            return ApiResponse::error('Your account is not associated with a company.', [], 403);
        }

        if (! $user->tenant?->is_active) {
            return ApiResponse::error('This company account is inactive.', [], 403);
        }

        Tenancy::set($user->tenant_id);

        return $next($request);
    }
}
