<?php

namespace App\Models\Scopes;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope applied to every tenant-owned model. Silently constrains all
 * queries to the current tenant so no controller/service can accidentally
 * read or write across tenants. Disabled only when Tenancy::spanAllTenants()
 * is explicitly in effect (platform super-admin / console).
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! Tenancy::shouldScope()) {
            return;
        }

        $builder->where(
            $model->getTable() . '.' . $model->getTenantColumn(),
            Tenancy::id(),
        );
    }
}
