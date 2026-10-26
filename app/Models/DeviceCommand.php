<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One durable request for a device to do something.
 *
 * The row is the record of intent and outcome; the queued job is only the worker
 * that carries it out. That separation is what lets a failed command be inspected,
 * retried and audited after the job itself is long gone.
 */
class DeviceCommand extends Model
{
    use BelongsToTenant;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    // Command names. Kept as constants so a typo is a fatal, not a silent no-op.
    public const SYNC_ATTENDANCE = 'sync_attendance';
    public const SYNC_ENROLLMENT = 'sync_enrollment';
    public const IMPORT_EMPLOYEES = 'import_employees';
    public const PUSH_USER = 'push_user';
    public const REMOVE_USER = 'remove_user';
    public const ENROLL_FINGERPRINT = 'enroll_fingerprint';
    public const ENROLL_CARD = 'enroll_card';
    public const DELETE_FINGERPRINT = 'delete_fingerprint';
    public const CLEAR_CARD = 'clear_card';
    public const HEALTH_CHECK = 'health_check';
    public const READ_INFO = 'read_info';
    public const PROBE_CAPABILITIES = 'probe_capabilities';
    public const SYNC_TIME = 'sync_time';
    public const RECONCILE_USERS = 'reconcile_users';

    // "Rebuild from system" (2026-10-11, DeviceRebuildService): backup + plan + fan-out,
    // then one command per person to change and per unlinked record to remove.
    public const REBUILD_DEVICE = 'rebuild_device';

    public const REBUILD_PERSON = 'rebuild_person';

    public const REBUILD_REMOVE = 'rebuild_remove';

    // Erase the terminal's punch log after storing every punch (AttendanceClearService,
    // 2026-10-18). Users, cards and fingerprints stay.
    public const CLEAR_ATTENDANCE = 'clear_attendance';

    // Remove every user and punch from the terminal (DeviceWipeService, 2026-10-21,
    // CLAUDE.md rule 22). Payload: save_punches (bool).
    public const WIPE_DEVICE = 'wipe_device';

    // Reboot the terminal; done when it answers again (2026-10-26, DC-034).
    public const RESTART_DEVICE = 'restart_device';

    protected $fillable = [
        'tenant_id', 'device_id', 'command', 'payload', 'status',
        'attempts', 'max_attempts', 'available_at', 'started_at', 'finished_at',
        'message', 'error', 'raw_response', 'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'error' => 'array',
            'raw_response' => 'array',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }

    /** Whether another attempt is allowed. */
    public function canRetry(): bool
    {
        return $this->status === self::STATUS_FAILED && $this->attempts < $this->max_attempts;
    }

    public function markRunning(): void
    {
        $this->forceFill([
            'status' => self::STATUS_RUNNING,
            'attempts' => $this->attempts + 1,
            'started_at' => now(),
        ])->save();
    }

    /** @param array<string,mixed>|null $raw */
    public function markSucceeded(string $message, ?array $raw = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_SUCCEEDED,
            'finished_at' => now(),
            'message' => $message,
            'raw_response' => $raw,
            'error' => null,
        ])->save();
    }

    /**
     * Records a failure and schedules a retry when attempts remain.
     *
     * Backoff comes from config so it can be tuned per deployment; a terminal on a
     * flaky wireless bridge wants longer gaps than one on wired LAN.
     *
     * @param array<string,mixed>|null $error
     */
    public function markFailed(string $message, ?array $error = null): void
    {
        $backoff = config('attendance.command_retry_backoff', [30, 120, 300]);
        $exhausted = $this->attempts >= $this->max_attempts;

        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'finished_at' => now(),
            'message' => $message,
            'error' => $error,
            'available_at' => $exhausted
                ? null
                : now()->addSeconds($backoff[min($this->attempts - 1, count($backoff) - 1)] ?? 300),
        ])->save();
    }
}
