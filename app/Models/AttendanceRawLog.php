<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A punch exactly as it came off the device, before interpretation.
 *
 * WHY KEEP THESE: the template decoder in this project was wrong for a while and
 * nobody could tell, because the only evidence was thrown away after parsing. With
 * raw payloads archived, a decoder fix can be applied retroactively; without them,
 * a device whose log has rolled over is simply gone.
 *
 * `raw` is hex-encoded so it survives any database's text handling.
 */
class AttendanceRawLog extends Model
{
    use BelongsToTenant;

    public const SKIP_DUPLICATE = 'duplicate';
    public const SKIP_UNDECODABLE = 'undecodable';
    public const SKIP_BELOW_WATERMARK = 'below_watermark';
    public const SKIP_UNMATCHED = 'unmatched';

    protected $fillable = [
        'tenant_id', 'device_id', 'attendance_sync_run_id', 'raw',
        'device_user_id', 'punched_at', 'verify_method', 'status',
        'decoded', 'imported', 'skip_reason',
    ];

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'decoded' => 'boolean',
            'imported' => 'boolean',
            'verify_method' => 'integer',
            'status' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(AttendanceSyncRun::class, 'attendance_sync_run_id');
    }

    /** The original bytes, for re-decoding with a fixed parser. */
    public function bytes(): string
    {
        return (string) hex2bin($this->raw);
    }
}
