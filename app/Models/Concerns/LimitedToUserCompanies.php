<?php

namespace App\Models\Concerns;

use App\Models\Scopes\CompanyScope;

/**
 * A user limited to some companies sees only theirs (owner, 2026-11-05). On Company,
 * Department, Employee, AttendanceLog and Device (2026-11-17), beside BelongsToTenant. See CompanyScope.
 */
trait LimitedToUserCompanies
{
    public static function bootLimitedToUserCompanies(): void
    {
        static::addGlobalScope(new CompanyScope());
    }
}
