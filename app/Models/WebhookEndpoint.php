<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A URL in the CUSTOMER'S software that we POST signed events to (2026-10-08).
 * See docs/integration-api.md.
 */
class WebhookEndpoint extends Model
{
    use BelongsToTenant;

    /** Everything a subscriber may ask for. `*` means all of them, now and later. */
    public const EVENTS = [
        'attendance.punch',
        'employee.created',
        'employee.updated',
        'employee.deleted',
        // Fingerprint count / card changed — split from `employee.updated` because a sync
        // touches these on every read and would drown real edits.
        'employee.enrollment_changed',
        'enrollment.finished',
        'device_command.finished',
        'device.status_changed',
        'webhook.test',
    ];

    /** Consecutive failed DELIVERIES (each already retried) before the endpoint is switched off. */
    public const DISABLE_AFTER = 25;

    protected $fillable = [
        'tenant_id', 'url', 'description', 'secret', 'events', 'is_active', 'punch_cursor',
        'consecutive_failures', 'last_success_at', 'last_failure_at', 'disabled_at',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
            'punch_cursor' => 'integer',
            'consecutive_failures' => 'integer',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The dispatcher caches "does this tenant have any endpoint" so a bulk import
        // does not run one query per row; any change here must drop that.
        $forget = fn (self $e) => Cache::forget(self::cacheKey($e->tenant_id));
        static::saved($forget);
        static::deleted($forget);
    }

    public static function cacheKey(?int $tenantId): string
    {
        return 'webhooks.active.'.($tenantId ?? 0);
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function wants(string $event): bool
    {
        $events = $this->events ?? [];

        return in_array('*', $events, true) || in_array($event, $events, true);
    }
}
