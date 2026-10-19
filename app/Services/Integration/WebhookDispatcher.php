<?php

namespace App\Services\Integration;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The one place an outbound event is turned into delivery rows.
 *
 * Callers say WHAT happened (`emit`); which endpoints care, the envelope, signing and
 * retrying are decided here and in DeliverWebhook. Every observer and the punch relay
 * come through this, so the envelope cannot drift between event types.
 *
 * Tenant is passed explicitly rather than read from Tenancy: events are raised from
 * queued jobs and from observers on rows being written for another tenant, where the
 * ambient tenant is unset or wrong.
 */
class WebhookDispatcher
{
    /**
     * @param  array<string,mixed>  $data
     * @return int how many deliveries were queued
     */
    public function emit(?int $tenantId, string $event, array $data): int
    {
        if ($tenantId === null || ! $this->tenantHasEndpoints($tenantId)) {
            return 0;
        }

        $queued = 0;

        $endpoints = WebhookEndpoint::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        foreach ($endpoints as $endpoint) {
            if ($endpoint->wants($event)) {
                $this->queue($endpoint, $event, $data);
                $queued++;
            }
        }

        return $queued;
    }

    /** Queues one event for one specific endpoint (also used for test pings and punches). */
    public function queue(WebhookEndpoint $endpoint, string $event, array $data): WebhookDelivery
    {
        $eventId = (string) Str::uuid();

        $delivery = WebhookDelivery::withoutGlobalScopes()->create([
            'tenant_id' => $endpoint->tenant_id,
            'webhook_endpoint_id' => $endpoint->id,
            'event_id' => $eventId,
            'event' => $event,
            'status' => WebhookDelivery::PENDING,
            'next_attempt_at' => now(),
            // The envelope is what is signed and sent, stored whole so a redelivery is
            // byte-identical to the first attempt.
            'payload' => [
                'id' => $eventId,
                'event' => $event,
                'created_at' => now()->utc()->toIso8601String(),
                'tenant_id' => $endpoint->tenant_id,
                'data' => $data,
            ],
        ]);

        DeliverWebhook::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }

    private function tenantHasEndpoints(int $tenantId): bool
    {
        return Cache::remember(
            WebhookEndpoint::cacheKey($tenantId),
            60,
            fn () => WebhookEndpoint::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('is_active', true)->exists(),
        );
    }
}
