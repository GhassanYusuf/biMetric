<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Card assignment HISTORY.
 *
 * A ZKTeco user record holds exactly ONE card field, so a person cannot carry two
 * active cards on a device. This table is therefore not a list of cards somebody
 * holds; it is the trail of which card was theirs when. That is what makes the
 * revoke-a-lost-card-and-issue-a-replacement flow answerable after the fact.
 */
class AccessCard extends Model
{
    use BelongsToTenant;

    public const ACTIVE = 'active';
    public const REPLACED = 'replaced';
    public const REVOKED = 'revoked';
    public const LOST = 'lost';

    /*
     * Why a card left a terminal, as revoke_reason. The difference decides whether it
     * goes back on (CLAUDE.md rule 19): a card that went with its holder's record is
     * restored when they return; one an operator cleared on purpose is not.
     */
    public const REASON_REMOVED_WITH_USER = 'Removed from device';

    public const REASON_CLEARED = 'Cleared from the terminal';

    /** 0xFFFFFFFF, all bits set. See looksLikeSentinel(). */
    public const SENTINEL = 4294967295;

    protected $fillable = [
        'tenant_id', 'employee_id', 'device_id', 'card_number', 'status',
        'assigned_at', 'revoked_at', 'revoke_reason', 'is_suspect', 'assigned_by',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
            'is_suspect' => 'boolean',
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

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * OBSERVED on real hardware: one user carried exactly 4294967295. On many
     * firmwares that value is the no-card marker rather than a real card number, so
     * it is flagged as suspect rather than trusted.
     */
    public static function looksLikeSentinel(?int $cardNumber): bool
    {
        return $cardNumber === self::SENTINEL;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
