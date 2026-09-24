<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named access level, as ZKAccess shows it on the Personnel dialog.
 *
 * DATABASE ONLY (2026-09-23): assigning one to an employee writes nothing to any
 * panel. `time_zone` is a label, not a panel time-zone index — see the migration.
 */
class AccessLevel extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'time_zone'];

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_access_levels')->withTimestamps();
    }
}
