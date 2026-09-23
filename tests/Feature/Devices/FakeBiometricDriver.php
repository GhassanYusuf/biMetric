<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Capability\CapabilitySet;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Value\DeviceUser;

/**
 * A terminal, in memory, with the one firmware behaviour that matters here.
 *
 * saveUser() REPLACES whatever is in the slot, exactly as the real save() does. That
 * is not a convenience of the fake — it is the behaviour under test. A fake that
 * refused to overwrite would make the guard look unnecessary and would pass whether
 * or not the guard existed.
 *
 * $writes records only the writes that actually reached the "hardware", so a test can
 * assert that a refusal left the device untouched rather than merely returning an
 * error after the damage was done.
 */
class FakeBiometricDriver implements BiometricDeviceDriver
{
    /** @var list<DeviceUser> */
    public array $roster = [];

    /** @var list<array{0:string,1:string}> userId/name pairs that reached the device */
    public array $writes = [];

    /** @var list<string> userIds deleted from the device */
    public array $deletes = [];

    public function run(Device $device, \Closure $callback): mixed
    {
        throw new \LogicException(
            'A test reached run(), which means app code is speaking protocol directly. '
            .'Device writes go through saveUser()/deleteUser() on the contract.'
        );
    }

    public function open(Device $device): ProtocolDevice
    {
        throw new \LogicException('Not used in these tests.');
    }

    public function ping(Device $device): array
    {
        return ['alive' => true, 'device_time' => null, 'latency_ms' => 1.0];
    }

    public function info(Device $device): array
    {
        return [];
    }

    public function readAttendance(Device $device, ?callable $onProgress = null): array
    {
        return [];
    }

    public function readUsers(Device $device, bool $withFingerprints = false): array
    {
        return $this->roster;
    }

    public function saveUser(
        Device $device,
        string $userId,
        string $name,
        ?\Closure $guard = null,
        bool $onlyIfMissing = false,
    ): DeviceUser {
        $occupant = $this->find($userId);

        // Guard first, inside the same "session", exactly as the real driver does.
        // If it throws, nothing below runs and the device is left as it was.
        if ($guard !== null) {
            $guard($occupant);
        }

        if ($onlyIfMissing && $occupant !== null) {
            return $occupant;
        }

        $written = new DeviceUser(
            uid: $occupant?->uid ?? count($this->roster) + 1,
            userId: $userId,
            name: $name,
            privilege: $occupant?->privilege ?? 0,
            cardNumber: $occupant?->cardNumber,
            // THE DESTRUCTIVE PART, faithfully reproduced: a save replaces the record.
            // The previous holder's fingerprints do not survive it.
            fingerSlots: $occupant !== null && $occupant->name === $name ? $occupant->fingerSlots : null,
        );

        $this->roster = array_values(array_filter(
            $this->roster,
            fn (DeviceUser $u) => $u->userId !== $userId,
        ));
        $this->roster[] = $written;
        $this->writes[] = [$userId, $name];

        return $written;
    }

    public function deleteUser(Device $device, string $userId): void
    {
        $this->roster = array_values(array_filter(
            $this->roster,
            fn (DeviceUser $u) => $u->userId !== $userId,
        ));
        $this->deletes[] = $userId;
    }

    /** @var list<array{user_id:?string,uid:int,slot:int,size:int,sha1:string}> */
    public array $templateDigests = [];

    public function readTemplateDigests(Device $device): array
    {
        return $this->templateDigests;
    }

    public function capabilities(Device $device): CapabilitySet
    {
        return CapabilitySet::defaults();
    }

    public function probeCapabilities(Device $device): CapabilitySet
    {
        return $this->capabilities($device);
    }

    public function supports(string $protocol): bool
    {
        return true;
    }

    private function find(string $userId): ?DeviceUser
    {
        foreach ($this->roster as $user) {
            if ($user->userId === $userId) {
                return $user;
            }
        }

        return null;
    }
}
