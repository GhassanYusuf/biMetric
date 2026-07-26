<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one employee is identified ON one device.
 *
 * Replaces matching by comparing employees.employee_code to the device's user id
 * string. That worked until a code was renumbered or reused, at which point punches
 * silently attached to the wrong person — a payroll error nobody would spot.
 */
class EmployeeDeviceMap extends Model
{
    use BelongsToTenant;

    public const IN_SYNC = 'in_sync';
    /** In the system, absent from the device — they cannot clock in. */
    public const MISSING_ON_DEVICE = 'missing_on_device';
    /** On the device, absent from the system — punches arrive unattributed. */
    public const MISSING_IN_SYSTEM = 'missing_in_system';
    /** Present on both, but the details disagree. */
    public const MISMATCHED = 'mismatched';

    protected $fillable = [
        'tenant_id', 'employee_id', 'device_id', 'device_uid', 'device_user_id',
        'device_name', 'device_privilege', 'card_number', 'fingerprint_slots',
        'synced_at', 'reconciliation_status', 'reconciliation_detail',
    ];

    protected function casts(): array
    {
        return [
            'fingerprint_slots' => 'array',
            'reconciliation_detail' => 'array',
            'synced_at' => 'datetime',
            'device_uid' => 'integer',
            'device_privilege' => 'integer',
            'card_number' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function fingerprintCount(): ?int
    {
        return $this->fingerprint_slots === null ? null : count($this->fingerprint_slots);
    }

    public function needsAttention(): bool
    {
        return $this->reconciliation_status !== self::IN_SYNC;
    }
}
