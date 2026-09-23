<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person the DEVICE holds, before anyone has decided who they are.
 *
 * Raw evidence, not an identity. Nothing downstream may assume a roster entry
 * corresponds to an employee — that is precisely the guess that attached punches to
 * the wrong humans. A claim is a human decision, recorded here with who made it.
 */
class DeviceRosterEntry extends Model
{
    use BelongsToTenant;

    /** Read off the device; nobody has decided who this is. */
    public const UNCLAIMED = 'unclaimed';

    /** Bound to an employee by an operator; a live mapping exists. */
    public const CLAIMED = 'claimed';

    /** Declared not a person we track. Kept so the PIN stays reserved. */
    public const IGNORED = 'ignored';

    protected $fillable = [
        'tenant_id', 'device_id', 'device_uid', 'device_user_id', 'device_name',
        'device_privilege', 'card_number', 'fingerprint_slots', 'claim_status',
        'claimed_employee_id', 'claimed_by', 'claimed_at', 'ignore_reason',
        'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'fingerprint_slots' => 'array',
            'device_uid' => 'integer',
            'device_privilege' => 'integer',
            'card_number' => 'integer',
            'claimed_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function claimedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'claimed_employee_id');
    }

    /**
     * Fingerprints enrolled, or null when the table would not decode.
     *
     * Null is NOT zero (rule 10). A caller rendering this must keep the two apart —
     * "unknown" and "none enrolled" lead an operator to opposite conclusions.
     */
    public function fingerprintCount(): ?int
    {
        return $this->fingerprint_slots === null ? null : count($this->fingerprint_slots);
    }

    public function isDecided(): bool
    {
        return $this->claim_status !== self::UNCLAIMED;
    }

    /**
     * Orders a roster the way an operator reads it: 2 before 10, not "10" before "2".
     *
     * WHY IT IS A SCOPE AND NOT AN orderByRaw AT EACH CALL SITE. device_user_id is a
     * string, because a device id is whatever the terminal says it is, and a plain
     * CAST is not portable across the two databases this app runs on. SQLite returns
     * 0 for text that is not a number; SQL Server raises "Conversion failed when
     * converting the nvarchar value ... to data type int" and the endpoint 500s. The
     * roster happens to be all-numeric on the estate today, but attendance_logs
     * already holds 16,386 non-numeric device ids off the same terminals, so this is
     * a break waiting for one junk roster row -- exactly what the F22 and Camelot
     * produce.
     *
     * TRY_CAST yields NULL instead of throwing, and NULLs sort first, so unparseable
     * ids group at the top rather than taking the screen down. The secondary sort
     * keeps the order stable, which pagination needs. The dialect is decided HERE,
     * once; callers just ask for the roster in order.
     */
    public function scopeOrderByDeviceUserId(Builder $query): Builder
    {
        $numeric = $query->getConnection()->getDriverName() === 'sqlsrv'
            ? 'TRY_CAST(device_user_id AS BIGINT)'
            : 'CAST(device_user_id AS INTEGER)';

        return $query->orderByRaw($numeric)->orderBy('device_user_id');
    }
}
