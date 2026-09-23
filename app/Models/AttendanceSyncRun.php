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

    /**
     * Bounded to a window of device-local dates.
     *
     * Distinct from FULL because it answers a different question. A full re-read
     * reconsiders everything and moves the watermark; a range read reconsiders one
     * window, imports only what falls inside it, and LEAVES THE WATERMARK ALONE —
     * it is a targeted backfill, not a change to where ordinary syncing is up to.
     */
    public const MODE_RANGE = 'range';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /** Completed, but some records could not be decoded or matched. */
    public const STATUS_PARTIAL = 'partial';

    /*
     * In-flight phases. Distinct from `status`, which is the run's OUTCOME —
     * a run is `running` for the whole time and passes through both of these.
     *
     * They are separate because the two halves fail and feel different: reading is
     * network-bound and can stall or retry, importing is disk-bound and cannot. A
     * single bar covering both would sit at 100% for the entire insert.
     */
    public const PHASE_READING = 'reading';

    public const PHASE_IMPORTING = 'importing';

    protected $fillable = [
        'tenant_id', 'device_id', 'device_command_id', 'mode', 'status',
        'started_at', 'finished_at', 'duration_ms',
        'records_read', 'records_imported', 'records_duplicate',
        'records_undecodable', 'records_unmatched', 'records_out_of_range',
        'watermark_before', 'watermark_after', 'range_from', 'range_to',
        'message', 'error',
        'progress_phase', 'progress_read', 'progress_total', 'progress_unit',
        'progress_attempt', 'progress_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'watermark_before' => 'datetime',
            'watermark_after' => 'datetime',
            'range_from' => 'date',
            'range_to' => 'date',
            'error' => 'array',
            'duration_ms' => 'integer',
            'records_read' => 'integer',
            'records_imported' => 'integer',
            'records_duplicate' => 'integer',
            'records_undecodable' => 'integer',
            'records_unmatched' => 'integer',
            'records_out_of_range' => 'integer',
            'progress_read' => 'integer',
            'progress_total' => 'integer',
            'progress_attempt' => 'integer',
            'progress_updated_at' => 'datetime',
        ];
    }

    /**
     * The in-flight view, or null once the run is over.
     *
     * `percent` is null — never 0 — when the device declared no total. Rule 10:
     * "we were not told how much there is" and "there is none" are different
     * answers, and a bar that invents a denominator cannot be checked by anyone
     * looking at it.
     *
     * @return array<string,mixed>|null
     */
    public function progress(): ?array
    {
        if ($this->progress_phase === null) {
            return null;
        }

        $total = $this->progress_total;

        return [
            'phase' => $this->progress_phase,
            'read' => $this->progress_read,
            'total' => $total,
            'unit' => $this->progress_unit,
            'percent' => $total > 0
                ? (int) round(min(1.0, $this->progress_read / $total) * 100)
                : null,
            'attempt' => $this->progress_attempt,
            'updated_at' => $this->progress_updated_at?->toIso8601String(),
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
