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

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'tenant_id' => Tenancy::id(),
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'employee_id' => $data['employee_id'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'email_verified_at' => now(),
            ]);
            $user->syncRoles([$data['role']]);

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
        if (($deactivating || $newRole !== $currentRole) && $this->isLastAdmin($user)) {
            return ApiResponse::error("{$user->name} is the only active Administrator. Make somebody else an Administrator first.", [], 409);
        }

        DB::transaction(function () use ($user, $data, $newRole, $currentRole, $deactivating) {
            $user->fill(array_intersect_key($data, array_flip(['name', 'email', 'employee_id', 'is_active'])))->save();
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
