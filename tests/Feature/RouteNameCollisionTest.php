<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A route name registered twice is not an error in Laravel — the second one just
 * wins the name lookup, silently. That cost this project a whole broken screen:
 * `apiResource('employees')` in routes/api.php claimed `employees.store`, the same
 * name the legacy Inertia pages use, and because withRouting() registers api.php
 * BEFORE web.php, Ziggy handed the browser `/api/employees`. Every Inertia form
 * then POSTed at a bearer-token endpoint, got the 401 JSON envelope back, and blew
 * up with "All Inertia requests must receive a valid Inertia response".
 *
 * Nothing about that failure points at a route name, so it stays expensive to
 * diagnose every time. These two tests make it impossible to reintroduce.
 */
class RouteNameCollisionTest extends TestCase
{
    public function test_no_route_name_is_registered_twice(): void
    {
        $seen = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || $name === '') {
                continue;
            }

            $seen[$name][] = implode('|', $route->methods()).' /'.$route->uri();
        }

        $collisions = array_filter($seen, fn (array $uris) => count($uris) > 1);

        $this->assertSame([], $collisions, sprintf(
            "Duplicate route names. The LAST registration wins route() lookups and the FIRST wins Ziggy's, so the two disagree:\n%s",
            collect($collisions)
                ->map(fn (array $uris, string $name) => "  {$name}\n    ".implode("\n    ", $uris))
                ->implode("\n"),
        ));
    }

    public function test_api_routes_are_namespaced_and_web_routes_are_not(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || $name === '') {
                continue;
            }

            $isApiUri = str_starts_with($route->uri(), 'api/');
            $isApiName = str_starts_with($name, 'api.');

            // A bare `api.` is the tell-tale of a group-level ->name('api.'):
            // Laravel applies a group name prefix to UNNAMED routes too, collapsing
            // every plain Route::get/post in the group under one bogus name.
            $this->assertNotSame('api.', $name, "Route /{$route->uri()} is named the bare group prefix 'api.'. Name the apiResource with ->names('api.<resource>') instead of putting ->name('api.') on the group.");

            if ($isApiUri) {
                $this->assertTrue($isApiName, "Named API route /{$route->uri()} is called '{$name}', which can collide with a web route. Prefix it 'api.'.");
            } else {
                $this->assertFalse($isApiName, "Web route /{$route->uri()} is called '{$name}', but the 'api.' prefix is reserved for /api routes.");
            }
        }
    }
}
