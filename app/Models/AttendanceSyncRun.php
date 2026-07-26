<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt to import attendance from one device.
 *
 * The counters are deliberately granular. "Imported 3" on its own hides whether the
 * device offered 3 records or 9,000 of which 8,997 were already known — and that
 * difference is the whole story when a sync looks wrong.
 */
class AttendanceSyncRun extends Model
{
    use BelongsToTenant;

    public const MODE_FULL = 'full';
    public const MODE_INCREMENTAL = 'incremental';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    /** Completed, but some records could not be decoded or matched. */
    public const STATUS_PARTIAL = 'partial';

    protected $fillable = [
        'tenant_id', 'device_id', 'device_command_id', 'mode', 'status',
        'started_at', 'finished_at', 'duration_ms',
        'records_read', 'records_imported', 'records_duplicate',
        'records_undecodable', 'records_unmatched',
        'watermark_before', 'watermark_after', 'message', 'error',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'watermark_before' => 'datetime',
            'watermark_after' => 'datetime',
            'error' => 'array',
            'duration_ms' => 'integer',
            'records_read' => 'integer',
            'records_imported' => 'integer',
            'records_duplicate' => 'integer',
            'records_undecodable' => 'integer',
            'records_unmatched' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(DeviceCommand::class, 'device_command_id');
    }

    public function rawLogs(): HasMany
    {
        return $this->hasMany(AttendanceRawLog::class);
    }

    /** True when the run finished but something needs a human to look at it. */
    public function needsAttention(): bool
    {
        return $this->status === self::STATUS_FAILED
            || $this->records_undecodable > 0
            || $this->records_unmatched > 0;
    }
}
