<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A national id this person has held, including ones since replaced.
 *
 * Exists because a renewal changes the number for the same human, and payroll exports
 * produced under the old number must still reconcile years later.
 */
class PersonNationalId extends Model
{
    protected $fillable = ['person_id', 'national_id', 'national_id_hash', 'superseded_at', 'reason'];

    protected $hidden = ['national_id', 'national_id_hash'];

    protected function casts(): array
    {
        return ['superseded_at' => 'datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
