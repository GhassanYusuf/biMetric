<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Holiday extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'date',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }

    /**
     * The companies this holiday applies to (2026-11-22). None = every company — what every holiday
     * before this was, and still the default.
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'holiday_company');
    }

    /**
     * Another holiday on $date that some company would have twice alongside one for $companyIds
     * (empty = every company): any holiday that day when either is for every company, else one sharing a
     * company. Null when there is none.
     *
     * @param  list<int>  $companyIds
     */
    public static function clashOn(string $date, array $companyIds, ?int $ignoreId = null): ?self
    {
        return self::query()
            ->with('companies:id')
            ->whereDate('date', \Carbon\Carbon::parse($date)->format('Y-m-d'))
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get()
            ->first(function (self $other) use ($companyIds) {
                $theirs = $other->companies->pluck('id')->map(fn ($i) => (int) $i)->all();

                return $companyIds === [] || $theirs === [] || array_intersect($companyIds, $theirs) !== [];
            });
    }

    /** Whether it applies to someone in $companyId (null: someone in no company — only all-company holidays). */
    public function appliesToCompany(?int $companyId): bool
    {
        $ids = $this->relationLoaded('companies') ? $this->companies->pluck('id') : $this->companies()->pluck('companies.id');

        return $ids->isEmpty() || ($companyId !== null && $ids->map(fn ($i) => (int) $i)->contains($companyId));
    }
}
