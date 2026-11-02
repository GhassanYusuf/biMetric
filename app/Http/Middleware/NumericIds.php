<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every id in an integration API answer is a number (owner, 2026-11-02).
 *
 * SQL Server hands bigint columns to PHP as text, so a reused resource answered
 * `"employee_id": "1829"` beside `"id": 53`: fine in JavaScript, a type error in C# or Java.
 * This turns `id`, every `…_id`, and the items of every `…_ids` list whose value is all digits
 * into an integer, everywhere in the answer.
 *
 * Two keys stay text on purpose: `external_id` (the caller's own id, any text) and
 * `device_user_id` (the user ID as the terminal stores it; leading zeros matter there).
 *
 * v1 only. The website's screens were written against the answers as they are, and cannot
 * be re-checked in a browser from here; they keep them.
 */
class NumericIds
{
    private const TEXT_KEYS = ['external_id', 'device_user_id'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->setData($this->walk($response->getData(true)));
        }

        return $response;
    }

    private function walk(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->walk($v, is_string($k) ? $k : $key);
            }

            return $value;
        }

        if (is_string($value) && $key !== null && ! in_array($key, self::TEXT_KEYS, true)
            && ($key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_ids'))
            && preg_match('/^(0|[1-9]\d{0,17})$/', $value) === 1) {
            return (int) $value;
        }

        return $value;
    }
}
