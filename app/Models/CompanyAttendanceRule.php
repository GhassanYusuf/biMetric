<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One company's own attendance rules — shift, weekend, grace minutes (2026-11-22). Its people are judged by
 * these instead of the system default; a company without a row uses the default. Read only through
 * App\Support\AttendanceRules::forCompany(), so every screen and report judges a day the same way.
 */
class CompanyAttendanceRule extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'company_id', 'shift_start', 'shift_end', 'work_days',
        'late_grace_minutes', 'early_leave_grace_minutes', 'leave_late_after_minutes', 'no_fixed_hours',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'work_days' => 'array',
            'late_grace_minutes' => 'integer',
            'early_leave_grace_minutes' => 'integer',
            'leave_late_after_minutes' => 'integer',
            'no_fixed_hours' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
