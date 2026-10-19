<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'name',
        'code',
        'manager_id',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /*
     * The tree (2026-10-11): a company is a department with no parent, its
     * departments point at it. See the add_parent_id_to_departments migration.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id');
    }

    /**
     * This department and every department beneath it, so that filtering by a
     * company also returns the people in its departments. Walked in PHP over the
     * tenant's whole department list: it is a handful of rows, and a recursive CTE
     * would be one more thing to keep portable between SQL Server and SQLite.
     *
     * @return list<int>
     */
    public static function subtreeIds(int $rootId): array
    {
        $byParent = [];
        foreach (static::query()->get(['id', 'parent_id']) as $d) {
            $byParent[(int) $d->parent_id][] = (int) $d->id;
        }

        $ids = [$rootId];
        // Guarded against a cycle, which nothing prevents in the data.
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($byParent[$ids[$i]] ?? [] as $child) {
                if (! in_array($child, $ids, true)) {
                    $ids[] = $child;
                }
            }
        }

        return $ids;
    }

    /**
     * The company this department belongs to: the top of its branch, or itself when it
     * IS a company. Walks the loaded `parent` relation, so eager-load `parent` for a list.
     */
    public function company(): self
    {
        $node = $this;
        $seen = [$this->id];
        while ($node->parent !== null && ! in_array($node->parent->id, $seen, true)) {
            $node = $node->parent;
            $seen[] = $node->id;
        }

        return $node;
    }

    /** "PROMOSEVEN › Finance" — the name as the tree shows it. */
    public function path(): string
    {
        $names = [$this->name];
        $seen = [$this->id];
        $node = $this->parent;
        while ($node !== null && ! in_array($node->id, $seen, true)) {
            array_unshift($names, $node->name);
            $seen[] = $node->id;
            $node = $node->parent;
        }

        return implode(' › ', $names);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }
}
