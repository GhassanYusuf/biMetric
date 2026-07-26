<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
