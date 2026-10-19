<?php

namespace App\Services\Integration;

use App\Models\AttendanceLog;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Cache;

/**
 * Turns new attendance_logs rows into `attendance.punch` webhook deliveries.
 *
 * WHY A CURSOR AND NOT A MODEL EVENT. Punches arrive through AttendanceSyncService's
 * bulk insert, which raises no Eloquent events, and PunchRecorded is deliberately NOT
 * fired for backfills (see the comment there). That code is the calibrated path and
 * stays untouched (rule 15); this reads what it already committed. Each endpoint keeps
 * the id of the last row it was handed, so delivery is gap-free and at-least-once, and
 * a worker that was down simply catches up.
 *
 * WHY BATCHES. A sync can land thousands of punches at once. One delivery per punch would
 * hammer the receiver, so a delivery carries up to BATCH of them under `data.punches`.
 * Receivers should treat the list as ordered by `id` and dedupe on `id`.
 *
 * Endpoints are created with the cursor at the CURRENT maximum, so subscribing does not
 * replay history; history is what `GET /api/v1/attendance` is for.
 */
class PunchWebhookRelay
{
    public const BATCH = 100;

    /** Deliveries queued per endpoint per run, so one catch-up cannot monopolise the queue. */
    public const MAX_BATCHES_PER_RUN = 20;

    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    /** @return int deliveries queued */
    public function relay(?int $tenantId = null): int
    {
        $queued = 0;

        $endpoints = WebhookEndpoint::withoutGlobalScopes()
            ->where('is_active', true)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->get()
            ->filter(fn (WebhookEndpoint $e) => $e->wants('attendance.punch'));

        foreach ($endpoints as $endpoint) {
            // Two relays for one endpoint (the scheduler and a live punch) would hand out
            // the same rows twice.
            $lock = Cache::lock('webhooks.punch-relay.'.$endpoint->id, 30);

            if (! $lock->get()) {
                continue;
            }

            try {
                $queued += $this->relayEndpoint($endpoint->fresh());
            } finally {
                $lock->release();
            }
        }

        return $queued;
    }

    private function relayEndpoint(WebhookEndpoint $endpoint): int
    {
        $queued = 0;

        for ($i = 0; $i < self::MAX_BATCHES_PER_RUN; $i++) {
            $logs = AttendanceLog::withoutGlobalScopes()
                ->where('tenant_id', $endpoint->tenant_id)
                ->where('id', '>', $endpoint->punch_cursor)
                ->with(['employee:id,name,employee_code,external_id', 'device:id,name'])
                ->orderBy('id')
                ->limit(self::BATCH)
                ->get();

            if ($logs->isEmpty()) {
                break;
            }

            $this->dispatcher->queue($endpoint, 'attendance.punch', [
                'count' => $logs->count(),
                'punches' => $logs->map(fn (AttendanceLog $log) => self::punch($log))->all(),
            ]);

            $endpoint->forceFill(['punch_cursor' => $logs->last()->id])->save();
            $queued++;
        }

        return $queued;
    }

    /**
     * The one shape of a punch, shared by the pull API and the webhook so an integrator
     * writes one parser. `punched_at` is UTC (rule 10).
     *
     * @return array<string,mixed>
     */
    public static function punch(AttendanceLog $log): array
    {
        return [
            'id' => $log->id,
            'punched_at' => $log->punched_at?->utc()->toIso8601String(),
            'employee_id' => $log->employee_id,
            'external_id' => $log->employee?->external_id,
            // The PIN the terminal recorded. Present even when employee_id is null
            // (an unclaimed person), so a punch is never silently dropped.
            'device_user_id' => $log->device_user_id,
            'employee_code' => $log->employee?->employee_code,
            'employee_name' => $log->employee?->name,
            'device_id' => $log->device_id,
            'device_name' => $log->device?->name,
            'verify_method' => $log->verify_method,
            'status' => $log->status,
        ];
    }
}
