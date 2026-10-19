<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Rules\SafeWebhookUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Sends ONE webhook delivery, once. Retries are owned by the delivery ROW, as with
 * device commands (rule 11): a failed attempt schedules the next with backoff, so the
 * queue's own retry counter never gets involved and the history stays in one place.
 *
 * SIGNATURE. `X-Webhook-Signature: sha256=<hex>`, HMAC-SHA256 over
 * "{X-Webhook-Timestamp}.{raw body}" keyed with the endpoint secret. The timestamp is
 * inside the signed string so a captured request cannot be replayed later; the receiver
 * should reject anything older than a few minutes. `X-Webhook-Id` is stable across
 * retries — it is the receiver's idempotency key, because delivery is at-least-once.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Seconds before attempt N+1. Eight attempts spans roughly two days. */
    public const BACKOFF = [60, 300, 1800, 7200, 21600, 43200, 86400];

    public function __construct(public readonly int $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::withoutGlobalScopes()->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== WebhookDelivery::PENDING) {
            return;
        }

        $endpoint = WebhookEndpoint::withoutGlobalScopes()->find($delivery->webhook_endpoint_id);

        if ($endpoint === null || (! $endpoint->is_active && $delivery->event !== 'webhook.test')) {
            $this->recordFailure($delivery, null, 'The endpoint was removed or disabled.', retry: false);

            return;
        }

        if ($reason = SafeWebhookUrl::violation($endpoint->url)) {
            $this->recordFailure($delivery, $endpoint, $reason, retry: false);

            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->timestamp;
        $delivery->increment('attempts');

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withHeaders([
                    'User-Agent' => 'ZKT-Webhooks/1',
                    'X-Webhook-Id' => $delivery->event_id,
                    'X-Webhook-Event' => $delivery->event,
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $endpoint->secret),
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (\Throwable $e) {
            $this->recordFailure($delivery, $endpoint, $e->getMessage());

            return;
        }

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => WebhookDelivery::SUCCEEDED,
                'response_status' => $response->status(),
                'response_body' => mb_substr($response->body(), 0, 1000),
                'error' => null,
                'next_attempt_at' => null,
                'delivered_at' => now(),
            ])->save();

            $endpoint->forceFill(['consecutive_failures' => 0, 'last_success_at' => now()])->save();

            return;
        }

        $delivery->response_status = $response->status();
        $delivery->response_body = mb_substr($response->body(), 0, 1000);
        $this->recordFailure($delivery, $endpoint, "The receiver answered HTTP {$response->status()}.");
    }

    /** Records a failed attempt and either schedules the next or gives the delivery up. */
    private function recordFailure(WebhookDelivery $delivery, ?WebhookEndpoint $endpoint, string $error, bool $retry = true): void
    {
        $retry = $retry && $delivery->attempts <= count(self::BACKOFF);
        $delay = self::BACKOFF[$delivery->attempts - 1] ?? null;

        $delivery->forceFill([
            'status' => $retry && $delay !== null ? WebhookDelivery::PENDING : WebhookDelivery::FAILED,
            'error' => $error,
            'next_attempt_at' => $retry && $delay !== null ? now()->addSeconds($delay) : null,
        ])->save();

        if ($retry && $delay !== null) {
            self::dispatch($delivery->id)->delay($delay);

            return;
        }

        if ($endpoint === null) {
            return;
        }

        // A delivery gave up for good. Enough in a row means the receiver is gone, and
        // queueing events to a dead URL forever is how the jobs table fills up.
        $endpoint->forceFill([
            'consecutive_failures' => $endpoint->consecutive_failures + 1,
            'last_failure_at' => now(),
        ]);

        if ($endpoint->consecutive_failures >= WebhookEndpoint::DISABLE_AFTER) {
            $endpoint->is_active = false;
            $endpoint->disabled_at = now();
        }

        $endpoint->save();
    }
}
