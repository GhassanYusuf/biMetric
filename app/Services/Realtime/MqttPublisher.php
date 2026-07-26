<?php

namespace App\Services\Realtime;

use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;

/**
 * The one place the application publishes to MQTT. It is deliberately
 * fail-safe: a broker outage or publish error is logged and swallowed, never
 * thrown — real-time delivery only *enhances* the UI and must never break a
 * domain action that has already committed (per the standards).
 *
 * Callers pass an already-built topic (see TopicBuilder) and an array payload.
 */
class MqttPublisher
{
    public function publish(string $topic, array $payload, int $qos = 0): bool
    {
        try {
            MQTT::connection()->publish($topic, json_encode($payload), qualityOfService: $qos);

            return true;
        } catch (\Throwable $e) {
            Log::warning("MQTT publish to [{$topic}] failed: {$e->getMessage()}");

            return false;
        }
    }
}
