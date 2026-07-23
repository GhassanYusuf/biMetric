<?php

namespace App\Listeners;

use App\Events\PunchRecorded;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;

/**
 * Publishes a live-attendance MQTT message for a newly-recorded punch. MQTT
 * only enhances the UI (a live-updating list) - it never gates or replaces
 * the actual attendance storage, which has already committed by the time
 * this runs. A broker outage or publish failure must never surface as an
 * error to the user or the calling code; it's simply logged.
 */
class PublishPunchToMqtt
{
    public function handle(PunchRecorded $event): void
    {
        $log = $event->log;

        try {
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

            MQTT::connection()->publish(
                config('services.mqtt.attendance_topic'),
                json_encode($payload),
                qualityOfService: 0,
            );
        } catch (\Throwable $e) {
            Log::warning("Failed to publish punch #{$log->id} to MQTT: {$e->getMessage()}");
        }
    }
}
