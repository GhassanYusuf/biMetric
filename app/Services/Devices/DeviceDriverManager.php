<?php

namespace App\Services\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Exceptions\DeviceBusyException;
use App\Models\Device;
use App\Services\Devices\C3\C3Driver;
use App\Services\Devices\Csl\CslHttpDriver;
use Promoseven\Biometric\Capability\CapabilitySet;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Value\DeviceUser;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

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
    /*
     * ONE CONVERSATION PER TERMINAL AT A TIME (2026-10-12, owner: "make a queuing
     * mechanism so we don't fight over who pushes the data first").
     *
     * The protocol is stateful per session (rule 11): two sessions on one terminal
     * interleave replies and both answers become wrong. Queued commands were already
     * kept apart by ProcessDeviceCommand's WithoutOverlapping, but Save, Push, Enroll
     * and the read-backs run inside the web request and skipped it — two operators, or
     * an operator and a scheduled sync, could talk to the same terminal at once.
     *
     * Every call that reaches a terminal now waits here for that terminal's lock, so
     * callers line up instead of colliding. Different terminals never wait for each
     * other. Re-entrant within one process: a call made while this process already
     * holds the terminal (inside run()) goes straight through.
     *
     * NOT covered: open(), which hands out a session the realtime listener keeps for
     * hours — locking it would block every other caller for as long as it listens.
     *
     * The lock lives in the cache store (database), so it is shared by every Apache
     * worker and the queue worker. Its TTL equals the queue worker's --timeout (1800s,
     * zkt-queue.service): no job can outlive it, and Camelot's remote reads have run
     * past 15 minutes (docs/error-handling.md). The price: a process killed mid-call
     * leaves the terminal "busy" for up to 30 minutes, or until the row is deleted
     * from cache_locks (key ending device-session:{id}).
     */
    private const LOCK_TTL_SECONDS = 1800;

    /**
     * How long a caller waits in line before giving up with DeviceBusyException. Long
     * enough for one push and read-back on another terminal session; short enough that
     * a Save behind an hour-long read reports "busy" rather than hanging. Public only
     * so a test need not wait a minute.
     */
    public static int $lockWaitSeconds = 60;

    /** @var array<int,int> device id => nesting depth held by THIS process */
    private static array $held = [];

    /**
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    private function exclusive(Device $device, \Closure $work): mixed
    {
        $id = (int) $device->id;

        if ((self::$held[$id] ?? 0) > 0) {
            self::$held[$id]++;
            try {
                return $work();
            } finally {
                self::$held[$id]--;
            }
        }

        $lock = Cache::lock("device-session:{$id}", self::LOCK_TTL_SECONDS);

        try {
            $lock->block(self::$lockWaitSeconds);
        } catch (LockTimeoutException) {
            throw new DeviceBusyException(
                "{$device->name} is busy with another operation and did not come free within ".self::$lockWaitSeconds.' seconds. Nothing was sent to it — try again in a moment.',
                ['device_id' => $id],
            );
        }

        self::$held[$id] = 1;
        try {
            return $work();
        } finally {
            self::$held[$id] = 0;
            $lock->release();
        }
    }

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
        return $this->exclusive($device, fn () => $this->driverFor($device)->run($device, $callback));
    }

    public function open(Device $device): ProtocolDevice
    {
        return $this->driverFor($device)->open($device);
    }

    public function ping(Device $device): array
    {
        return $this->exclusive($device, fn () => $this->driverFor($device)->ping($device));
    }

    public function info(Device $device): array
    {
        return $this->exclusive($device, fn () => $this->driverFor($device)->info($device));
    }

    public function readAttendance(Device $device, ?callable $onProgress = null): array
    {
        return $this->exclusive($device, fn () => $this->driverFor($device)->readAttendance($device, $onProgress));
    }

    public function readUsers(Device $device, bool $withFingerprints = false): array
    {
        return $this->exclusive($device, fn () => $this->driverFor($device)->readUsers($device, $withFingerprints));
    }

    public function readTemplateDigests(Device $device): array
    {
        return $this->exclusive($device, fn () => $this->driverFor($device)->readTemplateDigests($device));
    }

    public function readTemplates(Device $device): array
    {
        return $this->exclusive($device, fn () => $this->driverFor($device)->readTemplates($device));
    }

    public function saveUser(
        Device $device,
        string $userId,
        string $name,
        ?\Closure $guard = null,
        bool $onlyIfMissing = false,
        ?int $privilege = null,
        ?string $password = null,
    ): DeviceUser {
        return $this->exclusive($device, fn () => $this->driverFor($device)->saveUser($device, $userId, $name, $guard, $onlyIfMissing, $privilege, $password));
    }

    public function deleteUser(Device $device, string $userId): void
    {
        $this->exclusive($device, fn () => $this->driverFor($device)->deleteUser($device, $userId));
    }

    public function renumberUser(Device $device, string $fromUserId, string $toUserId): DeviceUser
    {
        return $this->exclusive($device, fn () => $this->driverFor($device)->renumberUser($device, $fromUserId, $toUserId));
    }

    public function setUserCard(Device $device, string $userId, int $cardNumber): DeviceUser
    {
        return $this->exclusive($device, fn () => $this->driverFor($device)->setUserCard($device, $userId, $cardNumber));
    }

    public function setUserPrivilege(Device $device, string $userId, bool $admin): DeviceUser
    {
        return $this->exclusive($device, fn () => $this->driverFor($device)->setUserPrivilege($device, $userId, $admin));
    }

    public function attendanceRecordCount(Device $device): ?int
    {
        return $this->exclusive($device, fn () => $this->driverFor($device)->attendanceRecordCount($device));
    }

    public function clearAttendance(Device $device, int $expectedRecords): int
    {
        return $this->exclusive($device, fn () => $this->driverFor($device)->clearAttendance($device, $expectedRecords));
    }

    public function readClock(Device $device): ?\DateTimeImmutable
    {
        return $this->exclusive($device, fn () => $this->driverFor($device)->readClock($device));
    }

    public function setClock(Device $device): array
    {
        return $this->exclusive($device, fn () => $this->driverFor($device)->setClock($device));
    }

    /**
     * Deliberately does NOT throw for an unknown protocol.
     *
     * Capabilities are read while rendering lists of devices, and a single
     * misconfigured row must not blow up the page. An empty set reports every
     * capability as `unknown`, which the UI already hides — the safe direction.
     */
    public function uploadTemplates(Device $device, string $userId, array $templates): array
    {
        return $this->exclusive($device, fn () => $this->driverFor($device)->uploadTemplates($device, $userId, $templates));
    }

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
        return $this->exclusive($device, fn () => $this->driverFor($device)->probeCapabilities($device));
    }
}
