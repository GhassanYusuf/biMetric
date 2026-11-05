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
        ];
    }

    /**
     * The permissions a per-user "Can delete: off" takes away, whatever the level holds
     * (owner, 2026-11-05: "delete permission will be given to someone and someone not").
     */
    public const DELETE_PERMISSIONS = ['employees.delete', 'devices.delete', 'users.delete'];

    /**
     * Spatie's check, with the per-user delete switch on top. Every can()/authorize() and
     * policy goes through here (spatie's Gate::before calls checkPermissionTo → this).
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $name = $permission instanceof \Spatie\Permission\Contracts\Permission ? $permission->name : $permission;
        if ($this->can_delete === false && is_string($name) && in_array($name, self::DELETE_PERMISSIONS, true)) {
            return false;
        }

        return $this->traitHasPermissionTo($permission, $guardName);
    }

    /** What this user may actually do: the level's permissions, less delete when switched off. */
    public function effectivePermissionNames(): \Illuminate\Support\Collection
    {
        $names = $this->getAllPermissions()->pluck('name');

        return $this->can_delete === false ? $names->reject(fn ($n) => in_array($n, self::DELETE_PERMISSIONS, true))->values() : $names;
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
