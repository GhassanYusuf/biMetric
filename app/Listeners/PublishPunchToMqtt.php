<?php

namespace App\Listeners;

use App\Events\PunchRecorded;
use App\Services\Realtime\MqttPublisher;
use App\Services\Realtime\TopicBuilder;

/**
 * Publishes a live-attendance MQTT message for a newly-recorded punch. MQTT
 * only enhances the UI (a live-updating list) - it never gates or replaces
 * the actual attendance storage, which has already committed by the time
 * this runs. Delivery is fail-safe via MqttPublisher (a broker outage is
 * logged and swallowed, never surfaced to the caller).
 */
class PublishPunchToMqtt
{
    public function __construct(private readonly MqttPublisher $mqtt)
    {
    }

    public function handle(PunchRecorded $event): void
    {
        $log = $event->log;

        $payload = [
            'id' => $log->id,
            'employee_id' => $log->employee_id,
            'employee_code' => $log->device_user_id,
            'employee_name' => $log->employee?->name,
            'device_id' => $log->device_id,
            'device_name' => $log->device?->name,
            'punched_at' => $log->punched_at->toIso8601String(),
            'verify_method' => $log->verify_method,
        ];

        // Tenant-scoped topic (new architecture): only subscribers of this
        // tenant's attendance module receive it.
        if ($log->tenant_id !== null) {
            $this->mqtt->publish(
                TopicBuilder::module($log->tenant_id, 'attendance', $log->employee_id ?? 0),
                $payload,
            );
        }

        // Legacy global topic retained for the existing (paused) realtime pipeline.
        $this->mqtt->publish(config('services.mqtt.attendance_topic'), $payload);
    }
}
