<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MultiCardGroup;
use App\Support\ApiResponse;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Basic CRUD for multi-card opening groups. Database only; reaches no panel. */
class MultiCardGroupController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', MultiCardGroup::class);

        return ApiResponse::success(
            MultiCardGroup::query()->orderBy('name')->get(['id', 'name']),
            'Multi-card groups retrieved.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', MultiCardGroup::class);

        $group = MultiCardGroup::create($this->validated($request));

        return ApiResponse::created($group->only(['id', 'name']), "Group {$group->name} added.");
    }

    public function update(Request $request, MultiCardGroup $multiCardGroup): JsonResponse
    {
        $this->authorize('update', $multiCardGroup);

        $multiCardGroup->update($this->validated($request, $multiCardGroup->id));

        return ApiResponse::success($multiCardGroup->only(['id', 'name']), "Group {$multiCardGroup->name} updated.");
    }

    public function destroy(MultiCardGroup $multiCardGroup): JsonResponse
    {
        $this->authorize('delete', $multiCardGroup);

        $multiCardGroup->delete();

        return ApiResponse::success(null, "Group {$multiCardGroup->name} removed.");
    }

    private function validated(Request $request, ?int $ignore = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255',
                Rule::unique('multi_card_groups', 'name')->where('tenant_id', Tenancy::id())->ignore($ignore)],
        ]);
    }
}
