<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\IntegrationAbilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Issues and revokes integration tokens (Sanctum personal access tokens, scoped).
 *
 * Sanctum, not a parallel API-key table (rule 13): tokens, hashing, `last_used_at`,
 * expiry and revocation already exist and are already what the middleware checks.
 *
 * A token belongs to the user who minted it and is exactly as powerful as that user's
 * role AND its abilities — whichever is narrower. For anything unattended, create a
 * dedicated service user with the least-privileged role and mint the token for that
 * account (`php artisan integration:token`), so an employee leaving does not break the
 * integration and a leaked token cannot do what an administrator can.
 *
 * Only an interactive login (`*`) token may manage tokens — a scoped integration token
 * cannot mint itself a wider one.
 */
class TokenController extends Controller
{
    public const PREFIX = 'integration:';

    public function abilities(): JsonResponse
    {
        return ApiResponse::success(IntegrationAbilities::ALL, 'Token abilities.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->allow($request);

        $tokens = $request->user()->tokens()
            ->where('name', 'like', self::PREFIX.'%')
            ->orderBy('id')
            ->get()
            ->map(fn ($t) => $this->present($t));

        return ApiResponse::success($tokens, 'Integration tokens retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $this->allow($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(IntegrationAbilities::names())],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $token = $request->user()->createToken(
            self::PREFIX.$data['name'],
            array_values(array_unique($data['abilities'])),
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
        );

        return ApiResponse::created(
            $this->present($token->accessToken) + ['token' => $token->plainTextToken],
            'Token created. Store it now — it is not shown again.',
        );
    }

    public function destroy(Request $request, int $token): JsonResponse
    {
        $this->allow($request);

        $deleted = $request->user()->tokens()->where('name', 'like', self::PREFIX.'%')->whereKey($token)->delete();

        abort_if($deleted === 0, 404, 'Token not found.');

        return ApiResponse::success(null, 'Token revoked.');
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()->tokenCan('*'), 403, 'A scoped token cannot manage tokens. Sign in to do this.');
        abort_unless($request->user()->can('settings.manage'), 403, 'This action is unauthorized.');
    }

    /** @return array<string,mixed> */
    private function present($t): array
    {
        return [
            'id' => $t->id,
            'name' => str_starts_with($t->name, self::PREFIX) ? substr($t->name, strlen(self::PREFIX)) : $t->name,
            'abilities' => $t->abilities,
            'last_used_at' => $t->last_used_at?->utc()->toIso8601String(),
            'expires_at' => $t->expires_at?->utc()->toIso8601String(),
            'created_at' => $t->created_at?->utc()->toIso8601String(),
        ];
    }
}
