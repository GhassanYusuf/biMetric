<?php

namespace App\Models;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Capability\CapabilitySet;

class Device extends Model
{
    use BelongsToTenant, HasFactory;

    /*
     * ADOPTION — a device is read-only until somebody has looked at who is on it.
     *
     * A terminal that has been running for years already holds people: leavers nobody
     * deleted, contractors, the installer's test account. Writing to it before those
     * are accounted for overwrites them, and a fingerprint template destroyed that way
     * cannot be restored from any backup — the employee must re-enrol in person.
     *
     * This is a GATE, not a label. assertWritable() is called before every device
     * write, and nothing may bypass it.
     */

    /** Just added. Nothing read yet — no writes, no attendance. */
    public const UNADOPTED = 'unadopted';

    /** Roster read; attendance syncs; entries awaiting a human decision. No writes. */
    public const READ_ONLY = 'read_only';

    /** Every roster entry decided. Writes permitted. */
    public const ADOPTED = 'adopted';

    public const PROTOCOL_BINARY = 'binary';

    public const PROTOCOL_CSL_HTTP = 'csl_http';

    /**
     * ZKTeco C3 / PullSDK access-control panels — the inBIO and C3 ranges.
     *
     * A SEPARATE FAMILY, not a dialect of PROTOCOL_BINARY: different transport
     * (TCP only), frame, checksum and session model. And the model name does not
     * tell you which family a device is in — the TF1700 is sold as a standalone
     * fingerprint terminal and speaks this. Read the device, do not infer.
     */
    public const PROTOCOL_C3 = 'c3';

    /** @return list<string> every protocol a driver is registered for. */
    public static function protocols(): array
    {
        return [self::PROTOCOL_BINARY, self::PROTOCOL_CSL_HTTP, self::PROTOCOL_C3];
    }

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
        'adoption_status',
        'roster_read_at',
        'adopted_at',
        'adopted_by',
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

    /**
     * A device is unadopted until proven otherwise, in memory as well as in the table.
     *
     * The column has the same default, but a freshly created model does not read it
     * back, so adoption_status would be null on the instance the caller is holding.
     * Null is not ADOPTED, so assertWritable() would still refuse — but code that
     * compares against UNADOPTED would silently miss, and a safety gate that depends
     * on which constant you compared against is not a gate.
     */
    protected $attributes = [
        'adoption_status' => self::UNADOPTED,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'roster_read_at' => 'datetime',
            'adopted_at' => 'datetime',
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
     * Refuses any write to a device whose roster has not been accounted for.
     *
     * CALL THIS BEFORE EVERY DEVICE WRITE. The production incident on 2026-09-15 was
     * a push to a terminal nobody had read first: each employee written by payroll
     * code landed on top of a stranger who already held that number, taking their
     * fingerprints with them.
     *
     * Reads are deliberately NOT gated — reading is how a device gets adopted, and it
     * cannot destroy anything.
     */
    public function assertWritable(): void
    {
        if ($this->adoption_status === self::ADOPTED) {
            return;
        }

        $why = $this->adoption_status === self::UNADOPTED
            ? 'its user roster has never been read'
            : 'its roster still has entries nobody has claimed or ignored';

        throw new \App\Exceptions\DeviceNotAdoptedException(
            $this,
            "{$this->name} is read-only because {$why}. Writing now could overwrite a person "
            ."already enrolled on the hardware. Read the roster and decide every entry first."
        );
    }

    public function isWritable(): bool
    {
        return $this->adoption_status === self::ADOPTED;
    }

    /**
     * Why an employee record cannot be written to this terminal, in words an
     * operator can act on — or null when it can.
     *
     * Lives on the model rather than in the service that asks, because answering it
     * needs the capability set, and rule 8 keeps the library's vocabulary to the
     * short list of classes that may name it. A caller gets a sentence instead of a
     * Capability constant, which is also what it wants: three different refusals
     * with three different fixes, said once, in the same words everywhere.
     */
    public function userWriteRefusal(): ?string
    {
        if (! $this->is_active) {
            return 'it is disabled';
        }

        /*
         * The one that matters. A terminal whose existing roster has not been read
         * and decided is read-only ON PURPOSE: a write addresses a slot, and writing
         * a slot somebody already occupies replaces them — fingerprints included,
         * with no error, and no way back short of that person standing at the
         * terminal again.
         */
        if (! $this->isWritable()) {
            return 'its roster has not been read and claimed yet, so it is read-only';
        }

        if (! $this->supports(Capability::USER_WRITE)) {
            return 'this protocol cannot write users';
        }

        return null;
    }

    /**
     * Roster entries nobody has claimed or ignored yet.
     *
     * A relation rather than a count query so the devices list can `withCount` it in
     * one statement. Adoption is refused while this is above zero: each one is a
     * person already on the hardware whose PIN a write could overwrite.
     */
    public function unclaimedRosterEntries(): HasMany
    {
        return $this->rosterEntries()->where('claim_status', DeviceRosterEntry::UNCLAIMED);
    }

    public function rosterEntries(): HasMany
    {
        return $this->hasMany(DeviceRosterEntry::class);
    }

    /**
     * This device's capability set, resolved through the driver.
     *
     * Reads the cached flags — no network access — so it is safe in a list view.
     * Falls back to the library's declared baseline when nothing has been probed.
     */
    public function capabilitySet(): CapabilitySet
    {
        return app(BiometricDeviceDriver::class)->capabilities($this);
    }

    /**
     * Whether this terminal can move a user to a new user ID in place. Asked by the
     * propagator, which may not name the library's vocabulary itself (rule 8). The F18
     * refuses it — ACK_ERROR on 2026-09-23, docs/device-capabilities.md.
     */
    public function canRenumberUsers(): bool
    {
        return $this->supports(Capability::USER_RENUMBER);
    }

    /** Whether a name/card can be written to a user here (a C3 panel: no, 2026-09-24). */
    public function canWriteUsers(): bool
    {
        return $this->supports(Capability::USER_WRITE);
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

    /**
     * Deletes the device's sync runs before the device itself.
     *
     * WHY THIS IS NOT A DATABASE CASCADE. It used to be. SQL Server refuses a schema
     * with two cascade paths into one table, and attendance_raw_logs had two: from
     * devices directly, and from devices through attendance_sync_runs. Both of the
     * cascades into attendance_raw_logs are load-bearing -- pruning runs must clear
     * their raw rows, and deleting a device must clear its own -- so the duplicate
     * path was broken at attendance_sync_runs.device_id instead, which is now
     * NO ACTION. See 2026_07_26_160400_create_attendance_sync_tables.php.
     *
     * That leaves exactly one gap, closed here: without it, deleting a device that
     * has ever synced fails on a foreign key. Deleting the runs through the relation
     * lets THEIR cascade clear the raw logs, so the outcome matches what the database
     * used to do on its own.
     *
     * It lives on the model, not in DeviceController::destroy, so every path that
     * deletes a device is covered rather than only the HTTP one.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $device) {
            $device->syncRuns()->get()->each->delete();
        });
    }

    public function groups(): BelongsToMany
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
