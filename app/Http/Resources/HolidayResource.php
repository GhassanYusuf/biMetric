<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Holiday
 */
class HolidayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->format('Y-m-d'),
            'name' => $this->name,
            // Empty = every company (2026-11-22).
            'companies' => $this->whenLoaded('companies', fn () => $this->companies->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->all(), []),
        ];
    }
}
