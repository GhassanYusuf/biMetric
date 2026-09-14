<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Employee extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
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
    ];

    protected $appends = ['photo_url', 'has_card'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'card_number' => 'integer',
            'fingerprint_count' => 'integer',
            'fingerprint_slots' => 'array',
            'enrollment_synced_at' => 'datetime',
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
    public function deviceMaps(): HasMany
    {
        return $this->hasMany(EmployeeDeviceMap::class);
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
