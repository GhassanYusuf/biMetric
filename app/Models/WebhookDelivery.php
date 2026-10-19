<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One event to one endpoint, and everything that happened trying to deliver it. */
class WebhookDelivery extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    protected $fillable = [
        'tenant_id', 'webhook_endpoint_id', 'event_id', 'event', 'payload', 'status',
        'attempts', 'response_status', 'response_body', 'error', 'next_attempt_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'response_status' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
