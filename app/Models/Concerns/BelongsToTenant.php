<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-owned model. It:
 *  - registers the TenantScope global scope (reads are auto-filtered),
 *  - stamps the current tenant_id on create (writes are auto-owned),
 *  - exposes the tenant relationship.
 *
 * Models can override getTenantColumn() if they ever use a different column,
 * but the default 'tenant_id' is used everywhere.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model) {
            $column = $model->getTenantColumn();
            if (empty($model->{$column}) && Tenancy::check()) {
                $model->{$column} = Tenancy::id();
            }
        });
    }

    public function getTenantColumn(): string
    {
        return 'tenant_id';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
