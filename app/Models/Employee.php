<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Employee extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'person_id',
        'name',
        'employee_code',
        'email',
        'department',
        'department_id',
        'photo_path',
        'is_active',
        'card_number',
        'fingerprint_count',
        'fingerprint_slots',
        'enrollment_synced_at',
        // Personnel-dialog fields (2026-09-23). Stored only: none of these is sent
        // to a terminal or panel — see 2026_09_23_100100_add_profile_fields_to_employees.
        'last_name',
        'gender',
        'mobile_phone',
        'hired_date',
        'birthday',
        'device_password',
        'privilege',
        'job_title',
        'office',
        'education',
        'country',
        'city',
        'postal_code',
        'home_phone',
        'political_status',
        'race',
        'home_address',
        'work_address',
        'multi_card_group_id',
        'valid_period_enabled',
        'access_valid_from',
        'access_valid_to',
    ];

    /** The device-side privilege levels this app records. Stored, never pushed. */
    public const PRIVILEGES = ['user', 'enroller', 'admin', 'superadmin'];

    // Recoverable ciphertext, never serialised: EmployeeResource exposes only
    // whether one is set.
    protected $hidden = ['device_password'];

    protected $appends = ['photo_url', 'has_card'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'card_number' => 'integer',
            'fingerprint_count' => 'integer',
            'fingerprint_slots' => 'array',
            'enrollment_synced_at' => 'datetime',
            'hired_date' => 'date',
            'birthday' => 'date',
            'device_password' => 'encrypted',
            'valid_period_enabled' => 'boolean',
            'access_valid_from' => 'date',
            'access_valid_to' => 'date',
        ];
    }

    protected function hasCard(): Attribute
    {
        return Attribute::make(
            get: fn () => ! empty($this->card_number),
        );
    }

    protected function photoUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
        );
    }

    /**
     * The human behind this employment.
     *
     * Nullable while an employment is being reconciled — a device roster gives you a
     * name and a PIN, never a national id, so a claimed entry starts without a Person
     * and gains one when HR supplies the id. Attendance still collects meanwhile;
     * payroll export is what blocks (see Person::isExportable()).
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(AttendanceWaiver::class);
    }

    /*
     * How this employee is enrolled on each terminal, one row per device.
     *
     * The `fingerprint_count`, `fingerprint_slots`, `card_number` and
     * `enrollment_synced_at` columns on this model are a FLAT SNAPSHOT of
     * whichever terminal was read last. With more than one device that cannot
     * answer "enrolled where?", and per rule 10 a number attributed to the wrong
     * terminal claims more than the hardware told us. These rows are the
     * per-device truth, and the API exposes them as `enrollments`.
     */
    /** Per terminal and finger: which slots are filled, and the stored copy (2026-09-24). */
    public function biometricTemplates(): HasMany
    {
        return $this->hasMany(BiometricTemplate::class);
    }

    public function deviceMaps(): HasMany
    {
        return $this->hasMany(EmployeeDeviceMap::class);
    }

    /** Database only — assigning a level writes nothing to any panel (2026-09-23). */
    public function accessLevels(): BelongsToMany
    {
        return $this->belongsToMany(AccessLevel::class, 'employee_access_levels')->withTimestamps();
    }

    public function multiCardGroup(): BelongsTo
    {
        return $this->belongsTo(MultiCardGroup::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
