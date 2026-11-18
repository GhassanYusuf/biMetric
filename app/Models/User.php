<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, BelongsToTenant;
    use HasRoles {
        hasPermissionTo as traitHasPermissionTo;
    }

    protected $fillable = [
        'tenant_id',
        'is_super_admin',
        'employee_id',
        'is_active',
        // Per-user switch over the level (owner, 2026-11-05). Off = no deleting at all.
        'can_delete',
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
            'can_delete' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * The permissions a per-user "Can delete: off" takes away, whatever the level holds
     * (owner, 2026-11-05: "delete permission will be given to someone and someone not").
     */
    public const DELETE_PERMISSIONS = ['employees.delete', 'devices.delete', 'users.delete'];

    /**
     * Spatie's check, with three things on top (in this order):
     *
     *   1. The per-user delete switch (can_delete) takes the delete permissions away.
     *   2. Permissions removed from THIS user (removed_permissions, owner 2026-11-17: "I can add or
     *      remove any permission to everyone") are refused even when their level holds them.
     *   3. The Administrator level holds every company permission, new ones included, without a
     *      list to keep up to date. Permissions given to this user directly (spatie's direct
     *      permissions) count as well as their level's.
     *
     * Every can()/authorize() and policy goes through here (spatie's Gate::before calls
     * checkPermissionTo → this).
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $name = $permission instanceof \Spatie\Permission\Contracts\Permission ? $permission->name : $permission;
        if (is_string($name)) {
            if ($this->can_delete === false && in_array($name, self::DELETE_PERMISSIONS, true)) {
                return false;
            }
            if (in_array($name, $this->removedPermissions(), true)) {
                return false;
            }
            if ($this->isAdministrator() && in_array($name, \App\Support\PermissionCatalogue::tenantPermissions(), true)) {
                return true;
            }
        }

        return $this->traitHasPermissionTo($permission, $guardName);
    }

    /** What this user may actually do: level + given to them, less removed, less delete when switched off. */
    public function effectivePermissionNames(): \Illuminate\Support\Collection
    {
        $names = $this->isAdministrator()
            ? collect(\App\Support\PermissionCatalogue::tenantPermissions())
            : $this->getAllPermissions()->pluck('name');
        $removed = $this->removedPermissions();

        return $names->unique()
            ->reject(fn ($n) => in_array($n, $removed, true))
            ->reject(fn ($n) => $this->can_delete === false && in_array($n, self::DELETE_PERMISSIONS, true))
            ->values();
    }

    /** @return list<string> permissions taken away from this user although their level holds them */
    public function removedPermissions(): array
    {
        $raw = $this->getAttribute('removed_permissions');
        $list = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /** @return list<string> permissions given to this user on top of their level */
    public function grantedPermissionNames(): array
    {
        return $this->getDirectPermissions()->pluck('name')->sort()->values()->all();
    }

    public function isAdministrator(): bool
    {
        return $this->hasRole(\App\Support\PermissionCatalogue::ADMIN);
    }

    /** The companies this user may see, as ids; null = every company (CompanyAccess). */
    public function companyIds(): ?array
    {
        return \App\Support\CompanyAccess::for($this);
    }

    /** Platform super-admins bypass tenant scoping entirely. */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
