<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Tenancy;
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

        $token = $user->createToken($data['device_name'] ?? 'web')->plainTextToken;
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

    public function logout(Request $request): JsonResponse
    {
        // Revoke only the token used for this request.
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Signed out.');
    }
}
