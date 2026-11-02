<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'name',
        'code',
        'manager_id',
    ];

    /*
     * A department belongs to exactly one company (2026-11-03). Until then a company was a
     * department with no parent; see Company and the create_companies_table migration.
     * Departments are flat under their company: no department under a department.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** "PROMOSEVEN › Finance": the name as screens and reports print it. Load `company`. */
    public function path(): string
    {
        return $this->company === null ? $this->name : "{$this->company->name} › {$this->name}";
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }
}
