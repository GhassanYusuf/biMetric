<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Receives crashes the SPA caught in the browser.
 *
 * WHY: a React render error blanked the whole screen and left nothing on the
 * server. "Sometimes I get errors" was unanswerable because the only record was
 * in a console the operator had already closed.
 *
 * This endpoint is deliberately dumb. It validates a small fixed shape, writes one
 * entry to the `client` channel, and returns 202. It does NOT write to the
 * database: the payload is attacker-controllable (any authenticated user can POST
 * anything here), and a table invites both unbounded growth and a UI nobody
 * prunes. A log channel already rotates, and rule 13 says reuse before you add.
 *
 * Throttled at the route, and every field is length-capped, so a crash loop in one
 * browser cannot fill the disk.
 */
class ClientErrorController extends Controller
{
    /** Fields are truncated to these lengths before anything is written. */
    private const LIMITS = [
        'message' => 500,
        'stack' => 4000,
        'component_stack' => 4000,
        'url' => 500,
        'user_agent' => 300,
    ];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'stack' => ['nullable', 'string', 'max:20000'],
            'component_stack' => ['nullable', 'string', 'max:20000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'kind' => ['nullable', 'string', 'in:render,unhandledrejection,window.onerror'],
        ]);

        Log::channel('client')->error('SPA error: '.$this->cap($data['message'], 'message'), [
            'kind' => $data['kind'] ?? 'unknown',
            'url' => $this->cap($data['url'] ?? $request->headers->get('referer'), 'url'),
            'user_id' => Auth::id(),
            'tenant_id' => Auth::user()?->tenant_id,
            'ip' => $request->ip(),
            'user_agent' => $this->cap($request->userAgent(), 'user_agent'),
            'stack' => $this->cap($data['stack'] ?? null, 'stack'),

            // React's component stack names the component that threw, which the JS
            // stack usually does not once the bundle is minified.
            'component_stack' => $this->cap($data['component_stack'] ?? null, 'component_stack'),
        ]);

        // 202, not 200: accepted for logging. The client must not care, and must
        // never retry — a failed error report that retries is a crash loop.
        return ApiResponse::success(null, 'Reported.', [], 202);
    }

    private function cap(?string $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $limit = self::LIMITS[$field];

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit).' …[truncated]' : $value;
    }
}
