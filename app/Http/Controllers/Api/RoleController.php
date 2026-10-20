<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\PermissionCatalogue;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Permission levels on the Users screen (owner, 2026-10-19: editable levels, the
 * Administrator locked). A level is a spatie Role scoped to the company (team_id).
 *
 *   built in   Administrator, HR manager, Operator, Employee — re-created by name by
 *              RolesAndPermissionsSeeder for every company, so never renamed or deleted.
 *   locked     the Administrator always holds every company permission; it is not
 *              editable at all, so nobody can remove the last way back into this screen.
 *   custom     created here; renamed, edited, and deleted once nobody holds it.
 *
 * Nobody can put into a level a permission they do not hold themselves.
 */
class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('roles.view')) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        }

        $roles = $this->companyRoles()->with('permissions:id,name')->get();
        $counts = DB::table(config('permission.table_names.model_has_roles'))
            ->where('team_id', Tenancy::id())
            ->where('model_type', User::class)
            ->whereIn('model_id', User::query()->where('is_super_admin', false)->select('id'))
            ->groupBy('role_id')
            ->selectRaw('role_id, count(*) as c')
            ->pluck('c', 'role_id');

        // Built-in levels first, in their seeded order, then custom ones by name.
        $order = array_flip(array_keys(PermissionCatalogue::BUILT_IN));
        $rows = $roles
            ->sortBy(fn (Role $r) => [isset($order[$r->name]) ? 0 : 1, $order[$r->name] ?? 0, strtolower($r->name)])
            ->values()
            ->map(fn (Role $r) => $this->present($r, (int) ($counts[$r->id] ?? 0)));

        return ApiResponse::success($rows, 'Permission levels retrieved.', [
            'groups' => PermissionCatalogue::groups(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('roles.manage')) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        }

        $data = $request->validate($this->rules());
        if ($refusal = $this->beyondActor($request, $data['permissions'])) {
            return $refusal;
        }

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web', 'team_id' => Tenancy::id()]);
        $role->syncPermissions($data['permissions']);
        $this->audit($role, 'role.created', ['permissions' => $data['permissions']]);

        return ApiResponse::created($this->present($role->fresh('permissions'), 0), "Level “{$role->name}” created.");
    }

    public function update(Request $request, int $role): JsonResponse
    {
        if (! $request->user()->can('roles.manage')) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        }

        $model = $this->companyRoles()->findOrFail($role);
        if ($model->name === PermissionCatalogue::ADMIN) {
            return ApiResponse::error('The Administrator level always has every permission and cannot be changed.', [], 422);
        }

        $data = $request->validate($this->rules($model));
        if (PermissionCatalogue::isBuiltIn($model->name) && isset($data['name']) && $data['name'] !== $model->name && $data['name'] !== PermissionCatalogue::label($model->name)) {
            return ApiResponse::error('A built-in level keeps its name.', ['name' => ['A built-in level keeps its name.']], 422);
        }

        $before = $model->permissions->pluck('name')->sort()->values()->all();
        $added = array_values(array_diff($data['permissions'], $before));
        // Only what is being ADDED must be held by the editor: removing is always allowed.
        if ($refusal = $this->beyondActor($request, $added)) {
            return $refusal;
        }

        DB::transaction(function () use ($model, $data) {
            if (! PermissionCatalogue::isBuiltIn($model->name) && isset($data['name'])) {
                $model->name = $data['name'];
            }
            $model->syncPermissions($data['permissions']);
            // Moves the live stamp (rule 18): syncing permissions alone touches no timestamp.
            $model->touch();
        });

        $after = $model->fresh('permissions')->permissions->pluck('name')->sort()->values()->all();
        $this->audit($model, 'role.updated', [
            'added' => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
        ]);

        return ApiResponse::success($this->present($model->fresh('permissions'), $this->holders($model)), PermissionCatalogue::label($model->name).' saved.');
    }

    public function destroy(Request $request, int $role): JsonResponse
    {
        if (! $request->user()->can('roles.manage')) {
            return ApiResponse::error('This action is unauthorized.', [], 403);
        }

        $model = $this->companyRoles()->findOrFail($role);
        if (PermissionCatalogue::isBuiltIn($model->name)) {
            return ApiResponse::error('Built-in levels cannot be deleted.', [], 422);
        }
        if (($n = $this->holders($model)) > 0) {
            return ApiResponse::error(sprintf('%d user%s still %s “%s”. Give them another level first.', $n, $n === 1 ? '' : 's', $n === 1 ? 'has' : 'have', $model->name), [], 409);
        }

        $this->audit($model, 'role.deleted', ['name' => $model->name]);
        $model->delete();

        return ApiResponse::success(null, "Level “{$model->name}” deleted.");
    }

    private function companyRoles()
    {
        return Role::query()->where('team_id', Tenancy::id())->where('guard_name', 'web');
    }

    private function rules(?Role $role = null): array
    {
        $taken = Rule::unique('roles', 'name')->where('team_id', Tenancy::id())->where('guard_name', 'web');

        return [
            'name' => [
                $role ? 'sometimes' : 'required', 'string', 'max:60',
                $role ? $taken->ignore($role->id) : $taken,
                // A custom level may not borrow a built-in one's name or label.
                Rule::notIn([...array_keys(PermissionCatalogue::BUILT_IN), ...array_values(PermissionCatalogue::BUILT_IN)]),
            ],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(PermissionCatalogue::tenantPermissions())],
        ];
    }

    private function beyondActor(Request $request, array $permissions): ?JsonResponse
    {
        $missing = array_values(array_filter($permissions, fn ($p) => ! $request->user()->can($p)));
        if ($missing !== []) {
            $message = 'You cannot give a level permissions you do not have yourself.';

            return ApiResponse::error($message, ['permissions' => [$message]], 403);
        }

        return null;
    }

    private function holders(Role $role): int
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->where('role_id', $role->id)
            ->where('team_id', Tenancy::id())
            ->where('model_type', User::class)
            ->count();
    }

    private function present(Role $role, int $users): array
    {
        $locked = $role->name === PermissionCatalogue::ADMIN;

        return [
            'id' => $role->id,
            'name' => $role->name,
            'label' => PermissionCatalogue::label($role->name),
            'built_in' => PermissionCatalogue::isBuiltIn($role->name),
            'locked' => $locked,
            'permissions' => $locked ? PermissionCatalogue::tenantPermissions() : $role->permissions->pluck('name')->values()->all(),
            'users_count' => $users,
        ];
    }

    private function audit(Role $role, string $action, ?array $changes): void
    {
        AuditLog::create([
            'tenant_id' => Tenancy::id(),
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => Role::class,
            'auditable_id' => $role->id,
            'changes' => $changes,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255),
        ]);
    }
}
