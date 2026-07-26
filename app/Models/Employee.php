<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory, BelongsToTenant;

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

    protected function hasCard(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn () => ! empty($this->card_number),
        );
    }

    protected function photoUrl(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn () => $this->photo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->photo_path) : null,
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

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class);
    }
}
