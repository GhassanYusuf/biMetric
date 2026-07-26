<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to capture a credential at a terminal.
 *
 * Enrollment blocks on a HUMAN walking to a device, so it cannot be modelled as a
 * request/response. The session is the durable state machine:
 *
 *   pending   queued, device not contacted yet
 *   sent      the trigger reached the device (it beeped)
 *   waiting   the device is in capture mode, expecting a finger or card
 *   success   captured and stored
 *   timeout   nobody came
 *   failed    the device reported an error code
 *   cancelled an operator abandoned it
 *
 * The events column holds the device's own progress trail, which is the only
 * evidence available when an operator reports that nothing happened: it shows
 * whether the terminal ever beeped, whether a finger was placed, and what it scored.
 */
class EnrollmentSession extends Model
{
    use BelongsToTenant;

    public const TYPE_FINGERPRINT = 'fingerprint';
    public const TYPE_CARD = 'card';

    public const PENDING = 'pending';
    public const SENT = 'sent';
    public const WAITING = 'waiting';
    public const SUCCESS = 'success';
    public const TIMEOUT = 'timeout';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    /** States from which no further transition happens. */
    public const TERMINAL = [self::SUCCESS, self::TIMEOUT, self::FAILED, self::CANCELLED];

    protected $fillable = [
        'tenant_id', 'employee_id', 'device_id', 'device_command_id',
        'type', 'finger_slot', 'status',
        'requested_at', 'sent_at', 'completed_at', 'timeout_seconds',
        'captured_card_number', 'device_error_code', 'events', 'message', 'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'requested_at' => 'datetime',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'finger_slot' => 'integer',
            'timeout_seconds' => 'integer',
            'captured_card_number' => 'integer',
            'device_error_code' => 'integer',
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

    public function command(): BelongsTo
    {
        return $this->belongsTo(DeviceCommand::class, 'device_command_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function isActive(): bool
    {
        return ! $this->isFinished();
    }

    /**
     * Whether a stale session should be abandoned.
     *
     * A worker killed mid-capture would otherwise leave a session waiting forever,
     * blocking the employee from a fresh attempt. The grace is doubled so a slow
     * device is not reaped while it is genuinely still working.
     */
    public function hasExpired(): bool
    {
        if ($this->isFinished()) {
            return false;
        }

        return $this->requested_at->addSeconds($this->timeout_seconds * 2)->isPast();
    }

    /** @param list<string> $events */
    public function appendEvents(array $events): void
    {
        $this->forceFill(['events' => [...($this->events ?? []), ...$events]])->save();
    }

    public function transitionTo(string $status, ?string $message = null): void
    {
        $attributes = ['status' => $status];

        if ($status === self::SENT) {
            $attributes['sent_at'] = now();
        }

        if (in_array($status, self::TERMINAL, true)) {
            $attributes['completed_at'] = now();
        }

        if ($message !== null) {
            $attributes['message'] = $message;
        }

        $this->forceFill($attributes)->save();
    }
}
