<?php

use App\Exceptions\DeviceNotAdoptedException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
use App\Support\ApiResponse;
use App\Support\DeviceErrorReporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Promoseven\Biometric\Exception\DeviceException;
use Promoseven\Biometric\Exception\UnsupportedCommandException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
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
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Route-level aliases. `tenant` establishes the acting tenant after
        // auth; `permission`/`role` are spatie's authorization gates.
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            // Sanctum token abilities (integration API) and the Idempotency-Key replay.
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            'idempotent' => \App\Http\Middleware\EnforceIdempotency::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
        ]);

        // CRITICAL: the tenant must be resolved BEFORE route-model binding runs,
        // otherwise SubstituteBindings resolves records without the tenant scope
        // and a user could bind another tenant's record by id. Force
        // ResolveTenant ahead of SubstituteBindings in the priority list.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ------------------------------------------------------------------
        // Device / protocol failures are RECORDED here, exactly once.
        //
        // The Promoseven\Biometric package is framework-agnostic (CLAUDE.md rule 8)
        // and cannot log for itself, so anything that escaped a catch block
        // previously reached the default arm below and was flattened to
        // 'Server error.' — losing context(), isRetryable() and the concrete
        // subclass, which are the only parts worth having.
        //
        // Returning false stops Laravel writing its own duplicate entry to the
        // stack channel. Call sites that catch DeviceException themselves call
        // DeviceErrorReporter directly instead, so a failure is never logged
        // twice: either the catch reports it, or this does.
        // ------------------------------------------------------------------
        // A refused write to an unadopted terminal is a normal state for a new
        // device, not an error, so it does not belong in the error log.
        $exceptions->dontReport([DeviceNotAdoptedException::class]);

        $exceptions->report(function (DeviceException $e): bool {
            app(DeviceErrorReporter::class)->report($e, 'device communication');

            return false;
        });

        // Every API/JSON error is returned in the standard envelope so the SPA
        // and mobile clients get a single, predictable failure shape.
        $exceptions->render(function (Throwable $e, Request $request) {
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
                // Rule 9: the UI is supposed to HIDE unsupported actions, so
                // reaching here means a capability was mis-declared or a client
                // called the endpoint directly. Say so plainly rather than
                // returning a plausible-looking empty success.
                $e instanceof UnsupportedCommandException => ApiResponse::error(
                    $e->getMessage(), [], 501,
                ),

                // 409: not a fault, a state — the terminal has not been adopted, so
                // writes are refused on purpose. The message tells the operator what
                // to do, so it goes out verbatim; before 2026-10-11 this fell through
                // to 'Server error.' and the reason never reached the screen.
                $e instanceof DeviceNotAdoptedException => ApiResponse::error(
                    $e->getMessage(), ['device_id' => $e->device->id, 'reason' => 'not_adopted'], 409,
                ),

                // 502, not 500: the request was fine, the terminal upstream of us
                // was not. The message is already operator-readable and the entry
                // was logged by the report() hook above, so only format here.
                $e instanceof DeviceException => ApiResponse::error(
                    $e->getMessage(),
                    ['retryable' => $e->isRetryable()],
                    502,
                ),

                default => ApiResponse::error(
                    app()->hasDebugModeEnabled() ? $e->getMessage() : 'Server error.',
                    [],
                    $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500,
                ),
            };
        });
    })->create();
