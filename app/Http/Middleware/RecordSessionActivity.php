<?php

namespace App\Http\Middleware;

use App\Support\WebSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Marks a website sign-in active after a request the person made (WebSession, 2026-11-11). */
class RecordSessionActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // After the request: by now auth:sanctum has resolved the user and their token.
        WebSession::touch($request);

        return $response;
    }
}
