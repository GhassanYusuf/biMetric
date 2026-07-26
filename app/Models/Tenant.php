<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant = one company/organization account. This is the root of the
 * multi-tenant tree; it is NOT itself tenant-scoped.
 */
class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'logo_path',
        'primary_color',
        'is_active',
        'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'trial_ends_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }
}
