<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
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
        'valid_from', 'valid_to', 'closed_reason',
        'device_name', 'device_privilege', 'card_number', 'fingerprint_slots',
        'template_digests', 'templates_read_at',
        'synced_at', 'reconciliation_status', 'reconciliation_detail',
    ];

    protected function casts(): array
    {
        return [
            // SQL Server returns integer columns as strings; see DeviceRosterEntry.
            'tenant_id' => 'integer',
            'employee_id' => 'integer',
            'device_id' => 'integer',
            'fingerprint_slots' => 'array',
            'template_digests' => 'array',
            'templates_read_at' => 'datetime',
            'reconciliation_detail' => 'array',
            'synced_at' => 'datetime',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
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

    /** Mappings still in force. The default for anything asking "who is PIN 5". */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('valid_to');
    }

    /**
     * The mapping in force on a given device at a given moment.
     *
     * ATTENDANCE MUST RESOLVE THROUGH THIS, not through the live mapping. A PIN
     * reassigned after somebody left still carries their punches from before the
     * handover; resolving those to the current holder re-attributes another person's
     * history, which is a payroll error with no visible symptom.
     */
    public function scopeInForceAt(Builder $query, int $deviceId, string $devicePin, \DateTimeInterface $at): Builder
    {
        return $query
            ->where('device_id', $deviceId)
            ->where('device_user_id', $devicePin)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $at));
    }

    /**
     * When inForceAt() matches two windows, the one that should win first: a closed
     * window (an earlier holder with a known end) before an open-ended one, then the
     * later start. The same order as PunchAttribution::windows(), which explains why.
     * Only for a query that takes one row (value(), first()) — SQL Server refuses an
     * ORDER BY under count().
     */
    public function scopeByPrecedence(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN valid_to IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('valid_from');
    }

    /**
     * Ends this mapping without deleting it.
     *
     * The row is history: it is how a punch recorded last year still resolves to the
     * person who actually made it. Deleting it would orphan that attendance.
     */
    public function close(string $reason): void
    {
        $this->update(['valid_to' => now(), 'closed_reason' => $reason]);
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
