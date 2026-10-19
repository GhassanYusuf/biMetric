<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` for the v1 write endpoints.
 *
 * An integration's HTTP call can time out AFTER the server acted. Retrying a plain POST
 * then creates a second employee, or queues a second fingerprint enrollment on a
 * terminal with a person standing at it. With this header the retry gets the ORIGINAL
 * response back, flagged `Idempotent-Replayed: true`, and nothing runs twice.
 *
 * Keyed on token + method + path + the caller's key, so two integrations cannot collide
 * and a key reused on a different endpoint is simply a different key. Reusing a key with
 * a DIFFERENT body is a caller bug and is refused (422) rather than answered with a
 * response that does not belong to the request. Responses are kept 24 hours. Server
 * errors are not stored: a 5xx is exactly what a retry is for.
 *
 * Needs a cache store that supports locks (database, redis, file, array all do).
 */
class EnforceIdempotency
{
    public const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $request->isMethodSafe()) {
            return $next($request);
        }

        if (strlen($key) > 255) {
            return ApiResponse::error('Idempotency-Key must be at most 255 characters.', [], 422);
        }

        $scope = 'idem:'.sha1(implode('|', [
            $request->user()?->currentAccessToken()?->id ?? $request->user()?->id,
            $request->method(),
            $request->path(),
            $key,
        ]));
        $fingerprint = sha1($request->getContent().'|'.json_encode($request->query()));

        if ($stored = Cache::get($scope)) {
            if ($stored['fingerprint'] !== $fingerprint) {
                return ApiResponse::error(
                    'This Idempotency-Key was already used with a different request body.',
                    ['idempotency_key' => ['Use a new key for a different request.']],
                    422,
                );
            }

            return response($stored['body'], $stored['status'], $stored['headers'] + ['Idempotent-Replayed' => 'true']);
        }

        // Two simultaneous retries must not both run.
        $lock = Cache::lock($scope.':lock', 60);

        if (! $lock->get()) {
            return ApiResponse::error('A request with this Idempotency-Key is still being processed.', [], 409);
        }

        try {
            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                Cache::put($scope, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(),
                    'headers' => ['Content-Type' => 'application/json'],
                ], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
