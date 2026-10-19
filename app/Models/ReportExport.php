<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One background-built report file (2026-10-18) — today only the bulk employee
 * reports. The row is the record of what was asked, how far it got and what came
 * out; BuildEmployeeReports does the work. Operator-facing `message` and technical
 * `error` are kept apart, as for device commands (rule 11).
 */
class ReportExport extends Model
{
    use BelongsToTenant;

    public const KIND_EMPLOYEE_REPORTS = 'employee_reports';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    /** How long a finished file is kept before the next export clears it out. */
    public const KEEP_DAYS = 7;

    protected $fillable = [
        'tenant_id', 'user_id', 'kind', 'params', 'status', 'total', 'done',
        'manifest', 'file_path', 'filename', 'file_size', 'message', 'error', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'manifest' => 'array',
            'total' => 'integer',
            'done' => 'integer',
            'file_size' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED], true);
    }

    /** Where this export's working files and its result live on the local disk. */
    public function directory(): string
    {
        return "report-exports/{$this->id}";
    }
}
