<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A company the organisation runs: PROMOSEVEN, Camelot, GoodWheels (owner, 2026-11-02).
 *
 * Until 2026-11-03 a company was a `departments` row with no parent; the migration
 * 2026_11_03_100000_create_companies_table moved them here WITH THEIR IDS. Departments belong
 * to one company; an employee has a company, and optionally a department in it. One level
 * above all of this is the tenant (the whole organisation), which is something else.
 */
class Company extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'code', 'manager_id'];

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }
}
