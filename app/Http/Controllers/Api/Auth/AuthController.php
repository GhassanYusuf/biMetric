<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Tenancy;
use App\Support\WebSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Token-based auth (Sanctum personal access tokens) so the same endpoints
 * serve the web SPA and any future mobile client. All responses use the
 * standard envelope.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Look the user up WITHOUT tenant scoping (no tenant is resolved yet at
        // login time) and verify the password in constant-ish time.
        $user = User::withoutGlobalScopes()->where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            return ApiResponse::error('These credentials do not match our records.', [
                'email' => ['These credentials do not match our records.'],
            ], 422);
        }

        if (! $user->is_active) {
            return ApiResponse::error('This account has been deactivated.', [], 403);
        }

        // Establish tenant context so roles/permissions serialize correctly.
        if (! $user->isSuperAdmin() && $user->tenant_id !== null) {
            Tenancy::set($user->tenant_id);
        }

        // A website sign-in lasts 8 hours at most, and ends after 15 minutes idle (WebSession).
        $name = $data['device_name'] ?? WebSession::NAME;
        $created = $user->createToken($name, ['*'], $name === WebSession::NAME ? now()->addHours(WebSession::MAX_HOURS) : null);
        if (\Illuminate\Support\Facades\Schema::hasColumn('personal_access_tokens', 'last_active_at')) {
            $created->accessToken->forceFill(['last_active_at' => now()])->saveQuietly();
        }
        $token = $created->plainTextToken;
        $user->load('tenant');

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user),
        ], 'Signed in successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('tenant');

        return ApiResponse::success(new UserResource($user), 'OK');
    }

    /**
     * Change my own password (owner, 2026-11-18). Needs the current one; the new one must be at least
     * 8 characters and differ from it. Clears "must change password", and signs out every OTHER
     * session — this one stays signed in.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return ApiResponse::error('That is not your current password.', ['current_password' => ['That is not your current password.']], 422);
        }
        if (Hash::check($data['password'], $user->password)) {
            return ApiResponse::error('Choose a password different from the current one.', ['password' => ['Choose a password different from the current one.']], 422);
        }

        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        $keep = $user->currentAccessToken()?->id;
        $user->tokens()->when($keep, fn ($q) => $q->where('id', '!=', $keep))->delete();

        \App\Models\AuditLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'action' => 'user.password_changed',
            'auditable_type' => \App\Models\User::class,
            'auditable_id' => $user->id,
            'changes' => null,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return ApiResponse::success(new UserResource($user->fresh()->load('tenant')), 'Password changed.');
    }

    public function logout(Request $request): JsonResponse
    {
        // Revoke only the token used for this request.
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Signed out.');
    }
}
