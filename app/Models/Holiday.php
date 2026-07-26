<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

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
}
