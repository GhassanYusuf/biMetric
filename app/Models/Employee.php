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
        // The caller's own key (integration API, 2026-10-08). Unique per tenant when set.
        'external_id',
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
        // Employment (2026-10-12). A label and a date — see the migration.
        'employment_type',
        'resigned_at',
    ];

    /**
     * The "Device role" on the form (ZKAccess's privilege names). Since 2026-10-20 a
     * CHANGE to it is applied on the terminals (terminalAdmin(), EmployeeDevicePropagator);
     * an unchanged one never is, because real roles differ per terminal.
     */
    public const PRIVILEGES = ['user', 'enroller', 'admin', 'superadmin'];

    /** Employment types. A label only: no attendance rule reads it (owner, 2026-10-12). */
    public const EMPLOYMENT_TYPES = ['full_time', 'part_time'];

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
            'resigned_at' => 'date',
        ];
    }

    /*
     * A resigned employee is never active (2026-10-12). Enforced here rather than in
     * each controller, so the SPA, the integration API and an import cannot disagree:
     * inactive is the state this system already treats as "must not clock in".
     * Clearing the date (a rehire) leaves is_active to whoever is saving.
     */
    protected static function booted(): void
    {
        static::saving(function (Employee $employee) {
            if (! static::hasEmploymentColumns()) {
                unset($employee->employment_type, $employee->resigned_at);
            }

            if ($employee->resigned_at !== null) {
                $employee->is_active = false;
            }

            /*
             * The free-text `department` column predates the Company › Department tree
             * and is still what reports, exports and the punch list print. When the tree
             * link changes, the text follows it — the node's own name, which is the
             * company for a person placed directly under one, exactly as `staff:import`
             * writes it — so the two can never disagree about where someone works.
             */
            // Only when the caller did not set the text itself: an import carrying its own
            // department name (ZKAccess's "LOGISTICS") keeps it.
            if ($employee->isDirty('department_id') && ! $employee->isDirty('department')) {
                $employee->department = $employee->department_id === null
                    ? null
                    : Department::withoutGlobalScopes()->whereKey($employee->department_id)->value('name');
            }
        });
    }

    /**
     * Whether the 2026_10_12_100000 migration has run. Until it has, the screens must
     * keep working: the list ignores the resigned filter and a save drops the two new
     * fields, the same way EmployeeController::copyRelations() waits for its migration.
     */
    public static function hasEmploymentColumns(): bool
    {
        static $ready = null;

        return $ready ??= \Illuminate\Support\Facades\Schema::hasColumn('employees', 'resigned_at');
    }

    public function isResigned(): bool
    {
        return $this->resigned_at !== null;
    }

    /**
     * What the form's Device role means on a terminal: true administrator (may open its
     * menu), false normal user, null "leave the terminal as it is". The ZKTeco family
     * this app writes has only those two (0 / 14), so Admin and Super admin are both an
     * administrator there, and Enroller has no equivalent (owner, 2026-10-20).
     */
    public function terminalAdmin(): ?bool
    {
        return match ($this->privilege) {
            'admin', 'superadmin' => true,
            'user' => false,
            default => null,
        };
    }

    /**
     * First and last name as one string — what the Employees list shows, and what
     * every other screen that names a person should show too (2026-10-11). Not an
     * appended attribute: callers that select only some columns must include
     * `last_name`, and an append would quietly read it as null.
     */
    public function fullName(): string
    {
        return trim(implode(' ', array_filter([$this->name, $this->last_name], fn ($p) => $p !== null && $p !== '')));
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
     * Nullable: a device roster gives you a name and a PIN, so a claimed entry starts
     * without a Person. Attendance collects either way.
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
