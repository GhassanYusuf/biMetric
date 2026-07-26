<?php

namespace App\Services\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use Illuminate\Support\Facades\Log;
use Zkteco\Protocol\Capability\CapabilitySet;
use Zkteco\Protocol\Client\Device as ProtocolDevice;
use Zkteco\Protocol\Exception\DeviceException;
use Zkteco\Protocol\Value\DeviceConfig;

/**
 * Adapts the framework-agnostic zkteco-protocol package to this application.
 *
 * This is the ONLY class in the app that imports anything from Zkteco\Protocol.
 * Its whole job is translation:
 *
 *   Device (Eloquent)  ->  DeviceConfig (value object)
 *   stored capabilities ->  CapabilitySet
 *   DeviceException     ->  logged with app context, rethrown unchanged
 *
 * It deliberately does NOT add business logic. Sync orchestration, watermarks,
 * enrollment sessions and reconciliation live in their own services above it.
 */
class ZktecoDriver implements BiometricDeviceDriver
{
    /** Protocol values from Device this driver handles. */
    private const SUPPORTED = [Device::PROTOCOL_BINARY];

    public function run(Device $device, \Closure $callback): mixed
    {
        $this->guardProtocol($device);

        try {
            return ProtocolDevice::run(
                $this->configFor($device),
                $callback,
                $this->capabilities($device),
            );
        } catch (DeviceException $e) {
            // Log with app-side identity the library cannot know about, then rethrow
            // unchanged — callers decide how to present it, and the typed exception
            // is what lets them distinguish a timeout from an unsupported command.
            Log::warning('Device operation failed', [
                'device_id' => $device->id,
                'device_name' => $device->name,
                'endpoint' => "{$device->ip_address}:{$device->port}",
                'exception' => $e::class,
                'retryable' => $e->isRetryable(),
                'context' => $e->context(),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function ping(Device $device): array
    {
        return $this->run($device, fn (ProtocolDevice $d) => $d->ping());
    }

    public function info(Device $device): array
    {
        return $this->run($device, fn (ProtocolDevice $d) => $d->info());
    }

    /**
     * Capabilities as this app has them stored, falling back to the library's
     * declared baseline.
     *
     * No network access — safe to call while rendering a list of devices.
     */
    public function capabilities(Device $device): CapabilitySet
    {
        $stored = $device->capabilities_cache;

        if (is_array($stored) && $stored !== []) {
            return CapabilitySet::defaults()->with($stored);
        }

        return CapabilitySet::defaults();
    }

    /**
     * Probes the device and persists the refined set.
     *
     * Read-only probes only — the resolver never tests a capability by mutating the
     * device. Costs several round trips, so this is explicit rather than implicit.
     */
    public function probeCapabilities(Device $device): CapabilitySet
    {
        $resolved = $this->run($device, fn (ProtocolDevice $d) => $d->probeCapabilities());

        $device->forceFill([
            'capabilities_cache' => $resolved->toArray(),
            'capabilities_probed_at' => now(),
        ])->save();

        return $resolved;
    }

    public function supports(string $protocol): bool
    {
        return in_array($protocol, self::SUPPORTED, true);
    }

    /**
     * Maps the Eloquent model onto the library's value object.
     *
     * The device stamps punches in its own LOCAL time, so the configured attendance
     * timezone is passed through — without it every imported timestamp is offset,
     * and a browser then applies the viewer's offset on top, doubling the error.
     */
    private function configFor(Device $device): DeviceConfig
    {
        return new DeviceConfig(
            host: $device->ip_address,
            port: $device->port ?: 4370,
            commKey: (int) ($device->comm_key ?: 0),
            timeout: (float) config('attendance.device_timeout', 8.0),
            timezone: $device->timezone ?: config('attendance.device_timezone', '+03:00'),
        );
    }

    private function guardProtocol(Device $device): void
    {
        if (! $this->supports($device->protocol)) {
            throw new \InvalidArgumentException(
                "ZktecoDriver cannot speak protocol '{$device->protocol}' for device {$device->name}. "
                . 'Direct device operations require the Binary/TCP-IP protocol; CSL HTTP devices are '
                . 'handled by the web-portal client instead.',
            );
        }
    }
}
