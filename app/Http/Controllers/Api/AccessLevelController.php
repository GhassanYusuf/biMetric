<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessLevel;
use App\Support\ApiResponse;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Basic CRUD for access levels. DATABASE ONLY (2026-09-23): nothing here, and
 * nothing that assigns a level to an employee, writes to a C3 panel.
 */
class AccessLevelController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', AccessLevel::class);

        return ApiResponse::success(
            AccessLevel::query()->orderBy('name')->get(['id', 'name', 'time_zone']),
            'Access levels retrieved.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AccessLevel::class);

        $level = AccessLevel::create($this->validated($request));

        return ApiResponse::created($level->only(['id', 'name', 'time_zone']), "Access level {$level->name} added.");
    }

    public function update(Request $request, AccessLevel $accessLevel): JsonResponse
    {
        $this->authorize('update', $accessLevel);

        $accessLevel->update($this->validated($request, $accessLevel->id));

        return ApiResponse::success($accessLevel->only(['id', 'name', 'time_zone']), "Access level {$accessLevel->name} updated.");
    }

    public function destroy(AccessLevel $accessLevel): JsonResponse
    {
        $this->authorize('delete', $accessLevel);

        $accessLevel->delete();

        return ApiResponse::success(null, "Access level {$accessLevel->name} removed.");
    }

    private function validated(Request $request, ?int $ignore = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255',
                Rule::unique('access_levels', 'name')->where('tenant_id', Tenancy::id())->ignore($ignore)],
            'time_zone' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
