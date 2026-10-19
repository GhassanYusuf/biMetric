<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\ApiResponse;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Is my token good, who am I, what may I do" — the first call an integrator makes. */
class PingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            'api_version' => 'v1',
            'server_time' => now()->utc()->toIso8601String(),
            'tenant' => Tenancy::id() !== null ? ['id' => Tenancy::id(), 'name' => Tenant::query()->whereKey(Tenancy::id())->value('name')] : null,
            'user' => ['id' => $user->id, 'name' => $user->name],
            'abilities' => $user->currentAccessToken()?->abilities ?? [],
        ], 'OK');
    }
}
