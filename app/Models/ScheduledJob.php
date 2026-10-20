<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One cron job on the Cron jobs screen (owner, 2026-10-20). Run by
 * App\Services\Devices\ScheduledJobRunner, checked every minute, in the company's
 * timezone.
 *
 * ACTIONS is the whole list of what a job can do. Owner's choice, 2026-10-20: a fixed
 * list, never a typed command — a command box would let anyone with the screen run
 * anything on the server as www-data. Each action is a sequence of device commands,
 * queued per terminal IN THIS ORDER on that terminal's own one-at-a-time queue (rule 11),
 * which is what makes "set the clock, then read" mean the read runs after the clock.
 */
class ScheduledJob extends Model
{
    use BelongsToTenant;

    /** Never more often than this, in minutes: every read transfers a terminal's whole table. */
    public const MIN_INTERVAL_MINUTES = 15;

    /**
     * key => label, what it does, and the commands it queues per terminal. A read with
     * `range` asks for yesterday and today in the terminal's own dates (leaves the
     * watermark alone; the punch index makes a re-read punch a no-op).
     */
    public const ACTIONS = [
        'clock_and_read' => [
            'label' => 'Set clocks, then read the past day',
            'description' => 'Each terminal\'s clock is set from the server and read back, then yesterday\'s and today\'s punches are read.',
            'steps' => [[DeviceCommand::SYNC_TIME, false], [DeviceCommand::SYNC_ATTENDANCE, true]],
        ],
        'read_past_day' => [
            'label' => 'Read the past day',
            'description' => 'Yesterday\'s and today\'s punches. A punch already stored is never added twice.',
            'steps' => [[DeviceCommand::SYNC_ATTENDANCE, true]],
        ],
        'read_all_new' => [
            'label' => 'Read all new punches',
            'description' => 'Everything since the last full read. Slow: the whole attendance table comes off each terminal.',
            'steps' => [[DeviceCommand::SYNC_ATTENDANCE, false]],
        ],
        'set_clocks' => [
            'label' => 'Set clocks',
            'description' => 'Each terminal\'s clock is set from the server and read back; a clock that does not take fails its command.',
            'steps' => [[DeviceCommand::SYNC_TIME, false]],
        ],
        'copy_credentials' => [
            'label' => 'Copy fingerprints and cards',
            'description' => 'Reads each terminal\'s users and stores their fingerprints and cards in the system. Read-only on the terminal.',
            'steps' => [[DeviceCommand::SYNC_ENROLLMENT, false]],
        ],
    ];

    protected $fillable = [
        'tenant_id', 'name', 'action', 'schedule', 'device_ids', 'is_enabled',
        'last_run_at', 'last_result', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'device_ids' => 'array',
            'is_enabled' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }
}
