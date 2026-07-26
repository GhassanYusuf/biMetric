<?php

namespace App\Contracts;

use App\Models\Device;
use Zkteco\Protocol\Capability\CapabilitySet;
use Zkteco\Protocol\Client\Device as ProtocolDevice;

/**
 * The seam between this application and any biometric protocol library.
 *
 * Everything above this interface speaks Eloquent, tenancy and policies.
 * Everything below speaks packets. Neither knows about the other.
 *
 * WHY THIS EXISTS: the protocol implementation is a standalone package
 * (promoseven/zkteco-protocol) that other platforms are meant to reuse. If app
 * code called it directly, the package would slowly re-acquire Laravel-shaped
 * assumptions and stop being portable. Controllers must depend on services;
 * services depend on THIS; only the driver knows the library.
 *
 * Adding a second vendor (Hikvision, Suprema, Anviz) means writing another
 * implementation of this interface, not touching a controller.
 */
interface BiometricDeviceDriver
{
    /**
     * Runs a callback against an open device session, always closing it.
     *
     * The ONLY way app code touches a device. Takes the Eloquent model and handles
     * mapping it to whatever the underlying library needs.
     *
     * @template T
     * @param \Closure(ProtocolDevice):T $callback
     * @return T
     */
    public function run(Device $device, \Closure $callback): mixed;

    /**
     * Proves the device is reachable and serving commands.
     *
     * @return array{alive:bool, device_time:?\DateTimeImmutable, latency_ms:float}
     */
    public function ping(Device $device): array;

    /**
     * Identity and capacity fields read off the device.
     *
     * @return array<string,mixed>
     */
    public function info(Device $device): array;

    /**
     * What this device can do.
     *
     * Returns the stored/declared set without touching the network. Use
     * probeCapabilities() to refresh it from the hardware.
     */
    public function capabilities(Device $device): CapabilitySet;

    /** Probes the device read-only and returns a refined capability set. */
    public function probeCapabilities(Device $device): CapabilitySet;

    /** Which protocol family this driver speaks, matching Device::$protocol. */
    public function supports(string $protocol): bool;
}
