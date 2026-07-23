<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'employee_code',
        'email',
        'department',
        'photo_path',
        'is_active',
    ];

    protected $appends = ['photo_url'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
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
}
