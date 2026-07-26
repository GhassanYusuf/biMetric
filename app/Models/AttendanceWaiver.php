<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceWaiver extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'date',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
