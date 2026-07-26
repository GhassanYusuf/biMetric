<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One health TRANSITION for a device.
 *
 * Deliberately not written on every poll — a device checked every minute would
 * otherwise produce tens of thousands of rows saying "still online". Only changes
 * are recorded, which is what makes the history readable.
 */
class DeviceStatusLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'device_id', 'status', 'previous_status',
        'latency_ms', 'reason', 'observed_at',
    ];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'latency_ms' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
