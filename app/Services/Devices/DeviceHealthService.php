<?php

namespace App\Services\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Models\DeviceStatusLog;
use App\Services\Realtime\MqttPublisher;
use App\Services\Realtime\TopicBuilder;
use Promoseven\Biometric\Exception\DeviceException;

/**
 * Liveness and identity for terminals.
 *
 * "Online" is a DERIVED verdict, not a stored boolean. A device that simply stops
 * answering never gets a chance to mark itself offline, so the truth is always
 * "did it answer recently enough" (Device::isOnline()) rather than a flag somebody
 * remembered to clear.
 *
 * Status TRANSITIONS are logged, not every poll. A terminal checked every minute
 * would otherwise write ~43,000 rows a month all saying "still online", which buries
 * the two rows that matter.
 */
class DeviceHealthService
{
    public function __construct(
        private readonly BiometricDeviceDriver $driver,
        private readonly MqttPublisher $mqtt,
    ) {}

    /**
     * Pings a device and records the outcome.
     *
     * Never throws for an unreachable device — being offline is a legitimate ANSWER
     * to a health check, not a failure of the check. Callers that need the exception
     * should use the driver directly.
     *
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function check(Device $device): array
    {
        $previous = $device->health_status;

        try {
            $result = $this->driver->ping($device);

            $this->recordSuccess($device, (int) round($result['latency_ms']), $result['device_time']);

            /*
             * Clock drift matters more than it looks: the device stamps every punch
             * with its own clock, so drift silently shifts attendance times. Surfaced
             * here because a health check is the only thing that looks regularly.
             */
            $drift = $result['device_time'] === null
                ? null
                : abs($result['device_time']->getTimestamp() - now()->getTimestamp());

            $message = sprintf(
                '%s is online (%dms)%s.',
                $device->name,
                (int) round($result['latency_ms']),
                $drift !== null && $drift > 120 ? ", clock drifting by {$drift}s" : '',
            );

            return [
                'message' => $message,
                'raw' => [
                    'status' => Device::HEALTH_ONLINE,
                    'previous_status' => $previous,
                    'latency_ms' => $result['latency_ms'],
                    'device_time' => $result['device_time']?->format('c'),
                    'clock_drift_seconds' => $drift,
                ],
            ];
        } catch (DeviceException $e) {
            $this->recordFailure($device, $e->getMessage());

            return [
                'message' => "{$device->name} did not answer: {$e->getMessage()}",
                'raw' => [
                    'status' => $device->health_status,
                    'previous_status' => $previous,
                    'exception' => $e::class,
                    'context' => $e->context(),
                ],
            ];
        }
    }

    /**
     * Reads identity and capacity off the device and persists it.
     *
     * These fields were previously fetched by getInfo() and thrown away, so the UI
     * could never show what model or firmware a terminal actually was.
     *
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function readInfo(Device $device): array
    {
        // Through the CONTRACT, not run(): every driver implements info(), while
        // run() hands out a ZKTeco session that two of the three cannot serve.
        $info = $this->driver->info($device);

        $device->forceFill(array_filter([
            'model' => $info['device_name'] ?: null,
            'firmware_version' => $info['firmware'] ?: null,
            'platform' => $info['platform'] ?: null,
            'serial_number' => $info['serial'] ?: null,
            'mac_address' => $info['mac'] ?: null,
        ], fn ($v) => $v !== null) + [
            'info_read_at' => now(),
        ])->save();

        return [
            'message' => sprintf(
                'Read %s: %s, firmware %s, serial %s.',
                $device->name,
                $info['device_name'] ?: 'unknown model',
                $info['firmware'] ?: 'unknown',
                $info['serial'] ?: 'unknown',
            ),
            'raw' => $info + ['device_time' => $info['device_time']?->format('c')],
        ];
    }

    /**
     * Reads a device's identity WITHOUT persisting anything.
     *
     * Used by the add-device form to identify hardware before it exists as a row,
     * so the operator can confirm the model rather than type it. The Device passed
     * here is transient (unsaved); nothing is written, and the caller decides what
     * to do with the answer.
     *
     * Only reachable for a protocol this app actually drives — today that is the
     * ZKTeco standalone binary protocol (F-/K-series). An inBIO/C3 panel does not
     * answer info() on this protocol and will time out; that is a true negative,
     * not a bug, until a C3 driver exists (see docs/device-capabilities.md).
     *
     * @return array{device_name:?string, firmware:?string, platform:?string, serial:?string, mac:?string}
     */
    public function identify(Device $device): array
    {
        /*
         * Through the CONTRACT, not run(). Until 2026-09-14 this reached for a
         * ZKTeco protocol session, so "Read from device" on the add-device form
         * could only ever identify a standalone terminal — a C3 panel or a CSL
         * terminal threw, and the form told the operator to choose the model by
         * hand. info() is implemented by every driver, so it now works for all
         * three, which is the whole point of having a contract.
         */
        $info = $this->driver->info($device);

        return [
            'device_name' => $info['device_name'] ?: null,
            'firmware' => $info['firmware'] ?: null,
            'platform' => $info['platform'] ?: null,
            'serial' => $info['serial'] ?: null,
            'mac' => $info['mac'] ?: null,
        ];
    }

    /** Marks a device as having answered, and logs a transition if the state changed. */
    private function recordSuccess(Device $device, int $latencyMs, ?\DateTimeImmutable $deviceTime): void
    {
        $previous = $device->health_status;

        $device->forceFill([
            'last_seen_at' => now(),
            'health_status' => Device::HEALTH_ONLINE,
            'consecutive_failures' => 0,
            'last_latency_ms' => $latencyMs,
        ])->save();

        if ($previous !== Device::HEALTH_ONLINE) {
            $this->logTransition($device, Device::HEALTH_ONLINE, $previous, $latencyMs, 'Device answered a health check.');
        }
    }

    /**
     * Records a failed check.
     *
     * One failure is DEGRADED, not offline — a single dropped UDP datagram on a
     * lossy network is not the same as a terminal being unplugged. Only repeated
     * failures earn "offline", which keeps the status honest and stops alert noise.
     */
    private function recordFailure(Device $device, string $reason): void
    {
        $previous = $device->health_status;
        $failures = $device->consecutive_failures + 1;

        $status = $failures >= 3 ? Device::HEALTH_OFFLINE : Device::HEALTH_DEGRADED;

        $device->forceFill([
            'health_status' => $status,
            'consecutive_failures' => $failures,
        ])->save();

        if ($previous !== $status) {
            $this->logTransition($device, $status, $previous, null, $reason);
        }
    }

    private function logTransition(
        Device $device,
        string $status,
        ?string $previous,
        ?int $latencyMs,
        string $reason,
    ): void {
        DeviceStatusLog::create([
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'status' => $status,
            'previous_status' => $previous,
            'latency_ms' => $latencyMs,
            'reason' => $reason,
            'observed_at' => now(),
        ]);

        // Push it live so the UI updates without polling. Reuses the existing MQTT
        // channel rather than introducing a second real-time mechanism.
        $this->publish($device, $status, $previous, $latencyMs);
    }

    private function publish(Device $device, string $status, ?string $previous, ?int $latencyMs): void
    {
        if ($device->tenant_id === null) {
            return;
        }

        try {
            $this->mqtt->publish(
                TopicBuilder::module($device->tenant_id, 'devices', 'status'),
                [
                    'device_id' => $device->id,
                    'name' => $device->name,
                    'status' => $status,
                    'previous_status' => $previous,
                    'latency_ms' => $latencyMs,
                    'observed_at' => now()->toIso8601String(),
                ],
            );
        } catch (\Throwable) {
            // MQTT only ENHANCES the experience — a broker outage must never fail a
            // health check or block the transition being recorded.
        }
    }
}
