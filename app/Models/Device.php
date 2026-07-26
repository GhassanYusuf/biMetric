<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory, BelongsToTenant;

    public const PROTOCOL_BINARY = 'binary';
    public const PROTOCOL_CSL_HTTP = 'csl_http';

    protected $fillable = [
        'tenant_id',
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
        // Identity read off the hardware.
        'model',
        'firmware_version',
        'platform',
        'serial_number',
        'mac_address',
        'user_capacity',
        'log_capacity',
        'fingerprint_capacity',
        'info_read_at',
        'timezone',
        // Health / heartbeat.
        'last_seen_at',
        'health_status',
        'consecutive_failures',
        'last_latency_ms',
        // Capability flags and sync watermark.
        'capabilities_cache',
        'capabilities_probed_at',
        'attendance_watermark',
        'attendance_imported_total',
    ];

    public const HEALTH_ONLINE = 'online';
    public const HEALTH_OFFLINE = 'offline';
    public const HEALTH_DEGRADED = 'degraded';
    public const HEALTH_UNKNOWN = 'unknown';

    protected $hidden = [
        'comm_key',
        'web_password',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'info_read_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'capabilities_probed_at' => 'datetime',
            'attendance_watermark' => 'datetime',
            'capabilities_cache' => 'array',
            'consecutive_failures' => 'integer',
            'last_latency_ms' => 'integer',
            'user_capacity' => 'integer',
            'log_capacity' => 'integer',
            'fingerprint_capacity' => 'integer',
            'attendance_imported_total' => 'integer',
        ];
    }

    /**
     * This device's capability set, resolved through the driver.
     *
     * Reads the cached flags — no network access — so it is safe in a list view.
     * Falls back to the library's declared baseline when nothing has been probed.
     */
    public function capabilitySet(): \Zkteco\Protocol\Capability\CapabilitySet
    {
        return app(\App\Contracts\BiometricDeviceDriver::class)->capabilities($this);
    }

    /** Whether the UI should offer an action, e.g. Capability::TEMPLATE_DELETE. */
    public function supports(string $capability): bool
    {
        return $this->capabilitySet()->supports($capability);
    }

    /** Only devices this app can talk to directly over the binary protocol. */
    public function scopeDirectlyAddressable($query)
    {
        return $query->where('protocol', self::PROTOCOL_BINARY)->where('is_active', true);
    }

    /**
     * Whether the device has been heard from recently enough to call it online.
     *
     * Derived rather than stored so a device that simply stopped answering does not
     * keep claiming to be online until something explicitly marks it otherwise.
     */
    public function isOnline(): bool
    {
        if ($this->last_seen_at === null) {
            return false;
        }

        return $this->last_seen_at->gt(
            now()->subSeconds((int) config('attendance.offline_after_seconds', 300)),
        );
    }

    public function groups(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(DeviceGroup::class, 'device_group_members');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(DeviceStatusLog::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(AttendanceSyncRun::class);
    }

    public function employeeMaps(): HasMany
    {
        return $this->hasMany(EmployeeDeviceMap::class);
    }

    public function enrollmentSessions(): HasMany
    {
        return $this->hasMany(EnrollmentSession::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }
}
