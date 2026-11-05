<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'device_id',
        'device_user_id',
        // What the terminal recorded, when device_user_id was replaced by the CPR
        // (attendance:personnel-ids, 2026-11-05). Null: device_user_id is the terminal's.
        'original_device_user_id',
        'employee_id',
        'punched_at',
        'status',
        'verify_method',
    ];

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
        ];
    }

    /** The number the terminal recorded this punch under, whatever device_user_id says now. */
    public function terminalUserId(): string
    {
        return (string) ($this->original_device_user_id ?? $this->device_user_id);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
