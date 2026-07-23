<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory;

    public const PROTOCOL_BINARY = 'binary';
    public const PROTOCOL_CSL_HTTP = 'csl_http';

    protected $fillable = [
        'name',
        'ip_address',
        'port',
        'protocol',
        'comm_key',
        'web_username',
        'web_password',
        'location',
        'is_active',
        'last_synced_at',
        'last_sync_status',
        'last_sync_error',
    ];

    protected $hidden = [
        'comm_key',
        'web_password',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }
}
