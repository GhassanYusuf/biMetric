<?php

namespace App\Services\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Services\Devices\C3\C3Driver;
use App\Services\Devices\Csl\CslHttpDriver;
use Promoseven\Biometric\Capability\CapabilitySet;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Value\DeviceUser;

/**
 * Picks the driver for a device by its protocol, and forwards to it.
 *
 * This is what the contract bound in AppServiceProvider actually resolves to, so
 * NOTHING above it had to change to gain a second vendor: DeviceCommandHandler,
 * AttendanceSyncService, DeviceHealthService and the rest still inject
 * BiometricDeviceDriver and still call the same methods. They simply stopped being
 * ZKTeco-only.
 *
 * Adding a vendor means writing an implementation and adding it to $drivers. It does
 * not mean touching a service, a controller or a route — that claim in CLAUDE.md
 * rule 8 is now literally true rather than aspirational, and the C3 panel driver
 * proved it a second time: it was added here and nowhere else in the app.
 *
 * WHY A DELEGATOR AND NOT A REGISTRY CALLERS ASK: a registry would mean every
 * call site learning to resolve first, which is thirty call sites that can each
 * forget. Here the protocol dispatch happens in exactly one place.
 */
class DeviceDriverManager implements BiometricDeviceDriver
{
    /** @var list<BiometricDeviceDriver> */
    private array $drivers;

    public function __construct(ZktecoDriver $zkteco, CslHttpDriver $csl, C3Driver $c3)
    {
        $this->drivers = [$zkteco, $csl, $c3];
    }

    /**
     * The driver that handles this device.
     *
     * A device whose protocol nobody claims is a configuration error, not a device
     * error — hence InvalidArgumentException rather than a device exception that
     * the retry machinery would treat as worth another attempt.
     */
    public function driverFor(Device $device): BiometricDeviceDriver
    {
        foreach ($this->drivers as $driver) {
            if ($driver->supports($device->protocol)) {
                return $driver;
            }
        }

        throw new \InvalidArgumentException(
            "No driver handles protocol '{$device->protocol}' for device {$device->name}. "
            .'Register an implementation of BiometricDeviceDriver in DeviceDriverManager.',
        );
    }

    public function supports(string $protocol): bool
    {
        foreach ($this->drivers as $driver) {
            if ($driver->supports($protocol)) {
                return true;
            }
        }

        return false;
    }

    public function run(Device $device, \Closure $callback): mixed
    {
        return $this->driverFor($device)->run($device, $callback);
    }

    public function open(Device $device): ProtocolDevice
    {
        return $this->driverFor($device)->open($device);
    }

    public function ping(Device $device): array
    {
        return $this->driverFor($device)->ping($device);
    }

    public function info(Device $device): array
    {
        return $this->driverFor($device)->info($device);
    }

    public function readAttendance(Device $device, ?callable $onProgress = null): array
    {
        return $this->driverFor($device)->readAttendance($device, $onProgress);
    }

    public function readUsers(Device $device, bool $withFingerprints = false): array
    {
        return $this->driverFor($device)->readUsers($device, $withFingerprints);
    }

    public function readTemplateDigests(Device $device): array
    {
        return $this->driverFor($device)->readTemplateDigests($device);
    }

    public function readTemplates(Device $device): array
    {
        return $this->driverFor($device)->readTemplates($device);
    }

    public function saveUser(
        Device $device,
        string $userId,
        string $name,
        ?\Closure $guard = null,
        bool $onlyIfMissing = false,
    ): DeviceUser {
        return $this->driverFor($device)->saveUser($device, $userId, $name, $guard, $onlyIfMissing);
    }

    public function deleteUser(Device $device, string $userId): void
    {
        $this->driverFor($device)->deleteUser($device, $userId);
    }

    public function renumberUser(Device $device, string $fromUserId, string $toUserId): DeviceUser
    {
        return $this->driverFor($device)->renumberUser($device, $fromUserId, $toUserId);
    }

    public function setUserCard(Device $device, string $userId, int $cardNumber): DeviceUser
    {
        return $this->driverFor($device)->setUserCard($device, $userId, $cardNumber);
    }

    /**
     * Deliberately does NOT throw for an unknown protocol.
     *
     * Capabilities are read while rendering lists of devices, and a single
     * misconfigured row must not blow up the page. An empty set reports every
     * capability as `unknown`, which the UI already hides — the safe direction.
     */
    public function capabilities(Device $device): CapabilitySet
    {
        try {
            return $this->driverFor($device)->capabilities($device);
        } catch (\InvalidArgumentException) {
            return CapabilitySet::fromArray([]);
        }
    }

    public function probeCapabilities(Device $device): CapabilitySet
    {
        return $this->driverFor($device)->probeCapabilities($device);
    }
}
