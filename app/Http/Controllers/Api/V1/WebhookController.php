<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\AttendanceLog;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Rules\SafeWebhookUrl;
use App\Services\Integration\WebhookDispatcher;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Webhook endpoints: where THIS system calls the customer's software.
 *
 * Requires `settings.manage` as well as the `webhooks:manage` ability — pointing company
 * events at an arbitrary URL is an administrator's decision, not a clerk's.
 *
 * The signing secret is returned ONCE, on create and on rotate, and never again.
 */
class WebhookController extends Controller
{
    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    public function events(): JsonResponse
    {
        return ApiResponse::success(WebhookEndpoint::EVENTS, 'Webhook events.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->allow($request);

        return ApiResponse::success(
            WebhookEndpoint::query()->orderBy('id')->get()->map(fn ($e) => $this->present($e)),
            'Webhook endpoints retrieved.',
        );
    }

    public function show(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $this->allow($request);

        return ApiResponse::success($this->present($webhook), 'Webhook endpoint retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $this->allow($request);

        $data = $request->validate($this->rules());

        $endpoint = WebhookEndpoint::create([
            'url' => $data['url'],
            'description' => $data['description'] ?? null,
            'events' => $data['events'],
            'is_active' => $data['is_active'] ?? true,
            'secret' => $secret = WebhookEndpoint::newSecret(),
            // Start at "now": subscribing must not replay the whole punch history.
            // History is what GET /api/v1/attendance is for.
            'punch_cursor' => (int) AttendanceLog::query()->max('id'),
        ]);

        return ApiResponse::created(
            $this->present($endpoint) + ['secret' => $secret],
            'Webhook endpoint created. Store the secret now — it is not shown again.',
        );
    }

    public function update(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $this->allow($request);

        $data = $request->validate($this->rules(partial: true));

        $webhook->fill($data);

        // Switching it back on is how an operator acknowledges a receiver that was
        // auto-disabled for failing; the failure count must start over or it would be
        // switched straight off again.
        if (($data['is_active'] ?? false) === true) {
            $webhook->forceFill(['consecutive_failures' => 0, 'disabled_at' => null]);
        }

        $webhook->save();

        return ApiResponse::success($this->present($webhook), 'Webhook endpoint updated.');
    }

    public function destroy(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $this->allow($request);

        $webhook->delete();

        return ApiResponse::success(null, 'Webhook endpoint deleted.');
    }

    public function rotateSecret(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $this->allow($request);

        $webhook->update(['secret' => $secret = WebhookEndpoint::newSecret()]);

        return ApiResponse::success(
            $this->present($webhook) + ['secret' => $secret],
            'Secret rotated. The old one stops working immediately; store the new one now.',
        );
    }

    /** Sends a signed `webhook.test` event so a receiver can be wired up before real traffic. */
    public function test(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $this->allow($request);

        $delivery = $this->dispatcher->queue($webhook, 'webhook.test', ['message' => 'This is a test event.']);

        return ApiResponse::success($this->presentDelivery($delivery->fresh()), 'Test event queued.', [], 202);
    }

    public function deliveries(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $this->allow($request);

        $request->validate(['status' => ['nullable', Rule::in([WebhookDelivery::PENDING, WebhookDelivery::SUCCEEDED, WebhookDelivery::FAILED])]]);

        $rows = $webhook->deliveries()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        return ApiResponse::success($rows->through(fn ($d) => $this->presentDelivery($d)), 'Deliveries retrieved.');
    }

    /** Re-sends a delivery that gave up, with the same event id and body. */
    public function redeliver(Request $request, WebhookDelivery $delivery): JsonResponse
    {
        $this->allow($request);

        if ($delivery->status === WebhookDelivery::PENDING) {
            return ApiResponse::error('That delivery is still being attempted.', [], 422);
        }

        $delivery->forceFill([
            'status' => WebhookDelivery::PENDING,
            'attempts' => 0,
            'error' => null,
            'next_attempt_at' => now(),
        ])->save();

        DeliverWebhook::dispatch($delivery->id);

        return ApiResponse::success($this->presentDelivery($delivery->fresh()), 'Delivery re-queued.', [], 202);
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()->can('settings.manage'), 403, 'This action is unauthorized.');
    }

    /** @return array<string,mixed> */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'url' => [$required, 'string', 'max:2048', new SafeWebhookUrl],
            'description' => ['nullable', 'string', 'max:255'],
            'events' => [$required, 'array', 'min:1'],
            'events.*' => ['string', Rule::in([...WebhookEndpoint::EVENTS, '*'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string,mixed> */
    private function present(WebhookEndpoint $e): array
    {
        return [
            'id' => $e->id,
            'url' => $e->url,
            'description' => $e->description,
            'events' => $e->events,
            'is_active' => $e->is_active,
            'consecutive_failures' => $e->consecutive_failures,
            'last_success_at' => $e->last_success_at?->utc()->toIso8601String(),
            'last_failure_at' => $e->last_failure_at?->utc()->toIso8601String(),
            'disabled_at' => $e->disabled_at?->utc()->toIso8601String(),
            'created_at' => $e->created_at?->utc()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function presentDelivery(WebhookDelivery $d): array
    {
        return [
            'id' => $d->id,
            'webhook_id' => $d->webhook_endpoint_id,
            'event_id' => $d->event_id,
            'event' => $d->event,
            'status' => $d->status,
            'attempts' => $d->attempts,
            'response_status' => $d->response_status,
            'error' => $d->error,
            'next_attempt_at' => $d->next_attempt_at?->utc()->toIso8601String(),
            'delivered_at' => $d->delivered_at?->utc()->toIso8601String(),
            'created_at' => $d->created_at?->utc()->toIso8601String(),
        ];
    }
}
