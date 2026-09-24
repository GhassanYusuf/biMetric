<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which finger slots are filled for an employee on a device — and, since 2026-09-24,
 * the system's COPY of that template (owner's decision; FingerprintVault).
 *
 * BIOMETRIC DATA:
 * - `template` is base64 of the device's blob, encrypted at rest (APP_KEY) and
 *   HIDDEN — no toArray(), resource, log line or audit row may carry it. Only
 *   FingerprintVault reads it, to write it back to a device.
 * - `template_format` is "<protocol>:<model>" of the source terminal. A template is
 *   only known to work on the family it came from; an upload must check this.
 * - `template_sha1` is the plaintext digest, for change detection without decrypting.
 * - Rows mirror what the terminal holds: removing the person from the terminal, or
 *   deleting the terminal from the app, deletes its copies (device_id cascades).
 * - Not observed by AuditObserver (it records attributes); FingerprintVault writes a
 *   summary audit row with counts.
 */
class BiometricTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'employee_id', 'device_id', 'finger_slot',
        'template_size', 'template', 'template_format', 'template_sha1', 'enrolled_at', 'verified_at',
    ];

    protected $hidden = ['template'];

    protected function casts(): array
    {
        return [
            'template' => 'encrypted',
            'employee_id' => 'integer',
            'device_id' => 'integer',
            'finger_slot' => 'integer',
            'template_size' => 'integer',
            'enrolled_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** Whether the actual biometric bytes are stored, as opposed to just the slot. */
    public function hasTemplateBytes(): bool
    {
        return $this->template !== null;
    }
}
