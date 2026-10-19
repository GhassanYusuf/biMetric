<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One human, across every company they work for.
 *
 * Deliberately NOT tenant-scoped — see the migration. The whole point is that an
 * employee moving between sister companies punches as the same person.
 *
 * The national id this used to carry as its join key was removed on 2026-09-27 at
 * the owner's request (2026_09_27_110000_drop_national_ids). Nothing now attaches an
 * employment to a Person automatically; `hrms_person_id` is the remaining key.
 */
class Person extends Model
{
    public const VERIFIED = 'verified';

    public const UNVERIFIED = 'unverified';

    protected $fillable = ['full_name', 'hrms_person_id', 'status'];

    public function employments(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
