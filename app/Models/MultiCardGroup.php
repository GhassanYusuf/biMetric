<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A multi-card opening group (ZKAccess "Multi-Card Opening Personnel Group").
 * Database only; nothing is written to a panel.
 */
class MultiCardGroup extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name'];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
