<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which finger slots are filled for an employee on a device.
 *
 * METADATA ONLY. The template column is null for every row today: this library
 * cannot download template blobs, because the blob format and the matching upload
 * command are both unverified, and a template that cannot be written back reliably
 * is worse than no template at all. Enrollment is therefore DEVICE-SIDE, meaning a
 * finger has to be presented at a terminal.
 *
 * The column exists so that adding real template storage later is a fill-in rather
 * than a migration of everything that references it.
 */
class BiometricTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'employee_id', 'device_id', 'finger_slot',
        'template_size', 'template', 'template_format', 'enrolled_at', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
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
