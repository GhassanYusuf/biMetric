<?php

use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Route-level aliases. `tenant` establishes the acting tenant after
        // auth; `permission`/`role` are spatie's authorization gates.
        $middleware->alias([
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
        ]);

        // CRITICAL: the tenant must be resolved BEFORE route-model binding runs,
        // otherwise SubstituteBindings resolves records without the tenant scope
        // and a user could bind another tenant's record by id. Force
        // ResolveTenant ahead of SubstituteBindings in the priority list.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\ResolveTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every API/JSON error is returned in the standard envelope so the SPA
        // and mobile clients get a single, predictable failure shape.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null; // let web (Inertia) errors render normally
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error(
                    'The given data was invalid.', $e->errors(), 422,
                ),
                $e instanceof AuthenticationException => ApiResponse::error(
                    'Unauthenticated.', [], 401,
                ),
                $e instanceof AuthorizationException,
                $e instanceof UnauthorizedException => ApiResponse::error(
                    'This action is unauthorized.', [], 403,
                ),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(
                    'Resource not found.', [], 404,
                ),
                default => ApiResponse::error(
                    app()->hasDebugModeEnabled() ? $e->getMessage() : 'Server error.',
                    [],
                    $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500,
                ),
            };
        });
    })->create();
