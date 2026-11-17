<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\User\StoreUserRequest;
use App\Http\Requests\Api\User\UpdateUserRequest;
use App\Http\Resources\ManagedUserResource;
use App\Models\AuditLog;
use App\Models\DeviceCommand;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\PermissionCatalogue;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * The Users screen (owner, 2026-10-19: "add users tab that has permissions levels and
 * add users"). Every user here belongs to the caller's company; each holds exactly one
 * permission level (a spatie role, see PermissionCatalogue).
 *
 * The refusals below exist so that this screen cannot lock a company out of itself or
 * be used to climb above one's own rights:
 *
 *   - nobody changes their OWN level, deactivates or deletes themselves;
 *   - the last active Administrator cannot be demoted, deactivated or deleted;
 *   - a level can only be given by someone who already holds every permission in it.
 *
 * Deleting is for mistakes: a user who has acted in the system (audit trail, device
 * commands) is deactivated instead, so the record of who did what keeps its name.
 * Deactivating, and a password reset, sign the person out everywhere.
 */
class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->where('is_super_admin', false)
            ->with(['employee:id,name,employee_code', 'roles'])
            ->addSelect(['last_active_at' => DB::table('personal_access_tokens')
                ->selectRaw('max(last_used_at)')
                ->whereColumn('tokenable_id', 'users.id')
                ->where('tokenable_type', User::class)])
            ->orderBy('name')
            ->get();

        return ApiResponse::success(ManagedUserResource::collection($users), 'Users retrieved.');
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validated();
        if ($refusal = $this->cannotGrant($request->user(), $data['role'])) {
            return $refusal;
        }
        if ($refusal = $this->cannotGrantAccess($request->user(), $data)) {
            return $refusal;
        }

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'tenant_id' => Tenancy::id(),
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'employee_id' => $data['employee_id'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'can_delete' => $data['can_delete'] ?? true,
                'email_verified_at' => now(),
            ]);
            $user->syncRoles([$data['role']]);
            $this->syncCompanies($user, $data['company_ids'] ?? null);

            return $user;
        });

        return ApiResponse::created($this->present($user), "{$user->name} can now sign in.");
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validated();
        $actor = $request->user();
        $self = $actor->id === $user->id;
        $currentRole = $user->getRoleNames()->first();
        $newRole = $data['role'] ?? $currentRole;
        $deactivating = array_key_exists('is_active', $data) && ! $data['is_active'] && $user->is_active;

        if ($self && $newRole !== $currentRole) {
            return ApiResponse::error('You cannot change your own permission level.', ['role' => ['You cannot change your own permission level.']], 422);
        }
        if ($self && $deactivating) {
            return ApiResponse::error('You cannot deactivate yourself.', [], 422);
        }
        if ($newRole !== $currentRole && ($refusal = $this->cannotGrant($actor, $newRole))) {
            return $refusal;
        }
        $accessChanged = (array_key_exists('can_delete', $data) && (bool) $data['can_delete'] !== ($user->can_delete !== false))
            || (array_key_exists('company_ids', $data) && $this->normalisedCompanies($data['company_ids']) !== $user->companyIds());
        if ($self && $accessChanged) {
            return ApiResponse::error('You cannot change what you yourself may delete or see.', [], 422);
        }
        if ($accessChanged && ($refusal = $this->cannotGrantAccess($actor, $data))) {
            return $refusal;
        }
        if (($deactivating || $newRole !== $currentRole) && $this->isLastAdmin($user)) {
            return ApiResponse::error("{$user->name} is the only active Administrator. Make somebody else an Administrator first.", [], 409);
        }

        $beforeAccess = ['can_delete' => $user->can_delete !== false, 'company_ids' => $user->companyIds()];

        DB::transaction(function () use ($user, $data, $newRole, $currentRole, $deactivating, $accessChanged, $beforeAccess) {
            $user->fill(array_intersect_key($data, array_flip(['name', 'email', 'employee_id', 'is_active', 'can_delete'])))->save();
            if (array_key_exists('company_ids', $data)) {
                $this->syncCompanies($user, $data['company_ids']);
            }
            if ($accessChanged) {
                $this->audit($user, 'user.access_changed', [
                    'can_delete' => [$beforeAccess['can_delete'], $user->can_delete !== false],
                    'company_ids' => [$beforeAccess['company_ids'], $user->companyIds()],
                ]);
            }
            if ($newRole !== $currentRole) {
                $user->syncRoles([$newRole]);
                $this->audit($user, 'user.level_changed', ['role' => [$currentRole, $newRole]]);
            }
            if ($deactivating) {
                $user->tokens()->delete();
            }
        });

        return ApiResponse::success($this->present($user), $deactivating ? "{$user->name} is deactivated and signed out." : "{$user->name} saved.");
    }

    /** The administrator sets a new password and tells the person. */
    public function password(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate(['password' => ['required', 'string', Password::min(8)]]);

        $user->update(['password' => $data['password']]);

        // Everywhere else they were signed in now needs the new password. The caller's
        // own session survives resetting their own.
        $keep = $request->user()->id === $user->id ? $request->user()->currentAccessToken()?->id : null;
        $user->tokens()->when($keep, fn ($q) => $q->where('id', '!=', $keep))->delete();

        $this->audit($user, 'user.password_reset', null);

        return ApiResponse::success(null, "{$user->name}'s password changed. Tell them the new one.");
    }

    /**
     * This user's own permissions on top of their level (owner, 2026-11-17: "keep all permissions,
     * I can add or remove any permission to everyone — make it flexible"):
     *
     *   granted  given to this user although their level does not hold it (spatie direct permission)
     *   removed  taken from this user although their level holds it (users.removed_permissions)
     *
     * Not for yourself (so nobody locks themselves out) and not for an Administrator (that level
     * always holds everything). You cannot give a permission you do not hold yourself; taking one
     * away is always allowed. Audited with what was added and removed.
     */
    public function permissions(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);
        $all = PermissionCatalogue::tenantPermissions();
        $data = $request->validate([
            'granted' => ['present', 'array'],
            'granted.*' => ['string', 'in:'.implode(',', $all)],
            'removed' => ['present', 'array'],
            'removed.*' => ['string', 'in:'.implode(',', $all)],
        ]);

        if ($request->user()->id === $user->id) {
            return ApiResponse::error('You cannot change your own permissions.', [], 422);
        }
        if ($user->isAdministrator()) {
            return ApiResponse::error('An Administrator always has every permission. Give them another level to adjust theirs.', [], 422);
        }

        $granted = array_values(array_unique($data['granted']));
        $removed = array_values(array_diff(array_unique($data['removed']), $granted));
        $beforeGranted = $user->grantedPermissionNames();
        $beforeRemoved = $user->removedPermissions();

        $newlyGiven = array_diff($granted, $beforeGranted);
        $beyond = array_values(array_filter($newlyGiven, fn ($p) => ! $request->user()->can($p)));
        if ($beyond !== []) {
            $message = 'You cannot give permissions you do not have yourself: '.implode(', ', $beyond).'.';

            return ApiResponse::error($message, ['granted' => [$message]], 403);
        }

        DB::transaction(function () use ($user, $granted, $removed) {
            $user->syncPermissions($granted);
            $user->forceFill(['removed_permissions' => $removed === [] ? null : json_encode($removed)])->save();
        });
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->audit($user->fresh(), 'user.permissions_changed', [
            'granted' => ['added' => array_values(array_diff($granted, $beforeGranted)), 'removed' => array_values(array_diff($beforeGranted, $granted))],
            'removed' => ['added' => array_values(array_diff($removed, $beforeRemoved)), 'removed' => array_values(array_diff($beforeRemoved, $removed))],
        ]);

        return ApiResponse::success($this->present($user->fresh()), "{$user->name}'s permissions saved.");
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        if ($request->user()->id === $user->id) {
            return ApiResponse::error('You cannot delete yourself.', [], 422);
        }
        if ($this->isLastAdmin($user)) {
            return ApiResponse::error("{$user->name} is the only active Administrator. Make somebody else an Administrator first.", [], 409);
        }
        $acted = AuditLog::query()->where('user_id', $user->id)->exists()
            || DeviceCommand::query()->where('requested_by', $user->id)->exists();
        if ($acted) {
            return ApiResponse::error("{$user->name} has made changes in the system, and deleting would remove their name from that history. Deactivate them instead.", [], 409);
        }

        $user->tokens()->delete();
        $user->delete();

        return ApiResponse::success(null, "{$user->name} deleted.");
    }

    /** A 403 when the level holds a permission the actor lacks — no granting above oneself. */
    private function cannotGrant(User $actor, string $roleName): ?JsonResponse
    {
        $role = Role::findByName($roleName, 'web');
        $missing = $role->permissions->pluck('name')->reject(fn ($p) => $actor->can($p));
        if ($missing->isNotEmpty()) {
            $message = 'You cannot give the '.PermissionCatalogue::label($roleName).' level: it has permissions you do not have yourself.';

            return ApiResponse::error($message, ['role' => [$message]], 403);
        }

        return null;
    }

    /**
     * The same rule as levels (owner, 2026-11-05): nobody gives more than they have. A user who
     * may not delete cannot let someone else delete; a user limited to some companies can
     * give only those, and cannot give "every company".
     */
    private function cannotGrantAccess(User $actor, array $data): ?JsonResponse
    {
        if (($data['can_delete'] ?? true) && $actor->can_delete === false) {
            return ApiResponse::error('You cannot let someone delete: you may not delete yourself.', ['can_delete' => ['You may not delete yourself, so you cannot give it.']], 403);
        }

        $mine = $actor->companyIds();
        // A new user always gets companies; an edit only when it sends them.
        $giving = array_key_exists('company_ids', $data) || request()->isMethod('post');
        if ($mine !== null && $giving) {
            $given = $this->normalisedCompanies($data['company_ids'] ?? null);
            if ($given === null || array_diff($given, $mine) !== []) {
                $message = 'You can only give the companies you can see yourself.';

                return ApiResponse::error($message, ['company_ids' => [$message]], 403);
            }
        }

        return null;
    }

    /** @return list<int>|null sorted ids, or null for every company */
    private function normalisedCompanies(?array $ids): ?array
    {
        $ids = array_values(array_unique(array_map('intval', $ids ?? [])));
        sort($ids);

        return $ids === [] ? null : $ids;
    }

    private function syncCompanies(User $user, ?array $ids): void
    {
        DB::table('user_companies')->where('user_id', $user->id)->delete();
        $now = now();
        foreach ($this->normalisedCompanies($ids) ?? [] as $id) {
            DB::table('user_companies')->insert(['user_id' => $user->id, 'company_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
        }
        \App\Support\CompanyAccess::forget($user->id);
    }

    /** True when $user is the only ACTIVE user holding the Administrator level. */
    private function isLastAdmin(User $user): bool
    {
        if (! $user->is_active || ! $user->hasRole(PermissionCatalogue::ADMIN)) {
            return false;
        }

        return User::query()
            ->where('is_super_admin', false)
            ->where('is_active', true)
            ->role(PermissionCatalogue::ADMIN)
            ->count() <= 1;
    }

    private function present(User $user): ManagedUserResource
    {
        return new ManagedUserResource($user->fresh()->load(['employee:id,name,employee_code', 'roles']));
    }

    /** Role changes and password resets are not columns, so the AuditObserver cannot see them. */
    private function audit(User $user, string $action, ?array $changes): void
    {
        AuditLog::create([
            'tenant_id' => Tenancy::id(),
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'changes' => $changes,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255),
        ]);
    }
}
