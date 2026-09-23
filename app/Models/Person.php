<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * One human, across every company they work for.
 *
 * Deliberately NOT tenant-scoped — see the migration. The whole point is that an
 * employee moving between sister companies punches as the same person.
 *
 * NATIONAL ID HANDLING: the plaintext is encrypted at rest, so it cannot be used in a
 * WHERE clause. Every lookup goes through national_id_hash, a deterministic HMAC over
 * the normalised value, which is searchable without being reversible. Set the id only
 * through setNationalId(); assigning the column directly stores plaintext and leaves
 * the hash stale, which breaks both lookup and duplicate detection.
 */
class Person extends Model
{
    /** National id supplied and accepted — exportable to the HRMS. */
    public const VERIFIED = 'verified';

    /** A real human whose id has not been supplied yet. Attendance collects; export is blocked. */
    public const UNVERIFIED = 'unverified';

    protected $fillable = ['full_name', 'hrms_person_id', 'status'];

    /** Never serialise government id into an API response or an audit payload. */
    protected $hidden = ['national_id', 'national_id_hash'];

    public function employments(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function nationalIdHistory(): HasMany
    {
        return $this->hasMany(PersonNationalId::class);
    }

    /**
     * Deterministic blind index over the normalised id.
     *
     * Normalisation matters: the same document gets typed with spaces, dashes and
     * mixed case by different operators, and three spellings would become three
     * people — exactly the duplicate this table exists to prevent.
     */
    public static function hashNationalId(string $nationalId): string
    {
        $normalised = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $nationalId) ?? '');

        return hash_hmac('sha256', $normalised, config('app.key'));
    }

    /** The person holding this national id, current or superseded, or null. */
    public static function findByNationalId(string $nationalId): ?self
    {
        $hash = self::hashNationalId($nationalId);

        return self::query()->where('national_id_hash', $hash)->first()
            ?? PersonNationalId::query()->where('national_id_hash', $hash)->first()?->person;
    }

    /**
     * Sets or replaces the national id, keeping the previous one as history.
     *
     * A renewal (iqama, permit) issues a NEW number for the same human. Payroll
     * exports produced under the old one must still reconcile, so it is superseded
     * rather than overwritten.
     */
    public function setNationalId(string $nationalId, ?string $reason = null): void
    {
        $hash = self::hashNationalId($nationalId);

        if ($this->national_id_hash === $hash) {
            return;
        }

        if ($this->national_id_hash !== null) {
            $this->nationalIdHistory()
                ->where('national_id_hash', $this->national_id_hash)
                ->update(['superseded_at' => now(), 'reason' => $reason]);
        }

        $this->forceFill([
            'national_id' => Crypt::encryptString($nationalId),
            'national_id_hash' => $hash,
            'status' => self::VERIFIED,
        ])->save();

        $this->nationalIdHistory()->create([
            'national_id' => Crypt::encryptString($nationalId),
            'national_id_hash' => $hash,
        ]);
    }

    /** Plaintext national id. Call only where it is actually needed — HRMS export. */
    public function nationalId(): ?string
    {
        return $this->national_id === null ? null : Crypt::decryptString($this->national_id);
    }

    /**
     * The last few characters, for confirming the right document was entered.
     *
     * The full value is NEVER returned to a client. An operator entering three hundred
     * of these needs to see that the row they just filled holds what they typed; they
     * do not need to read everybody's government id back out of a list, and a screen
     * that shows them all is a screen that leaks them all.
     */
    public function maskedNationalId(): ?string
    {
        $plain = $this->nationalId();

        if ($plain === null || $plain === '') {
            return null;
        }

        $tail = substr($plain, -3);

        return str_repeat('•', max(strlen($plain) - 3, 0)).$tail;
    }

    /** Whether payroll may export this person's attendance. */
    public function isExportable(): bool
    {
        return $this->status === self::VERIFIED && $this->national_id_hash !== null;
    }
}
