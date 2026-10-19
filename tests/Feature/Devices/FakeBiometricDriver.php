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
    /** user id => the password a save was asked to write (null = keep). */
    public array $passwordsWritten = [];

    /** @var list<DeviceUser> */
    public array $roster = [];

    /** @var list<array{0:string,1:string}> userId/name pairs that reached the device */
    public array $writes = [];

    /** @var list<string> userIds deleted from the device */
    public array $deletes = [];

    /**
     * Stands in for a live capture session. The ONLY sanctioned use of run() is the
     * enrollment capture, which has no contract method yet (known debt), so a test of
     * that path sets this to play the library's part. Anything else reaching run()
     * still fails loudly below.
     *
     * @var (\Closure(): mixed)|null
     */
    public ?\Closure $capture = null;

    public function run(Device $device, \Closure $callback): mixed
    {
        if ($this->capture !== null) {
            return ($this->capture)();
        }

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
        return $this->punchLog;
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
        ?int $privilege = null,
        ?string $password = null,
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
            privilege: $privilege ?? $occupant?->privilege ?? 0,
            cardNumber: $occupant?->cardNumber,
            // THE DESTRUCTIVE PART, faithfully reproduced: a save replaces the record.
            // The previous holder's fingerprints do not survive it.
            fingerSlots: $occupant !== null && $occupant->name === $name ? $occupant->fingerSlots : null,
            // Null keeps the record's password, as the real save does since 2.13.0.
            password: $password ?? $occupant?->password ?? '',
        );

        $this->roster = array_values(array_filter(
            $this->roster,
            fn (DeviceUser $u) => $u->userId !== $userId,
        ));
        $this->roster[] = $written;
        $this->writes[] = [$userId, $name];
        $this->passwordsWritten[$userId] = $password;

        return $written;
    }

    /** @var list<array{0:string,1:int}> userId/card pairs sent to the device */
    public array $cardWrites = [];

    /** Simulates this firmware ACKing a write and then dropping it (rule 10). */
    public bool $dropCardWrites = false;

    /** The terminal's clock; null = this family cannot read one. */
    public ?\DateTimeImmutable $clock = null;

    /** Simulates a clock write the terminal ACKs and ignores. */
    public bool $ignoreClockWrites = false;

    public function readClock(Device $device): ?\DateTimeImmutable
    {
        return $this->clock;
    }

    public function setClock(Device $device): array
    {
        $before = $this->clock;
        if (! $this->ignoreClockWrites) {
            $this->clock = new \DateTimeImmutable('now', new \DateTimeZone(\App\Support\Timezone::forDevice($device)));
        }

        return ['before' => $before, 'after' => $this->clock];
    }

    public function setUserCard(Device $device, string $userId, int $cardNumber): DeviceUser
    {
        $occupant = $this->find($userId);

        if ($occupant === null) {
            throw new \RuntimeException("User {$userId} is not on the device.");
        }

        $this->cardWrites[] = [$userId, $cardNumber];

        if ($this->dropCardWrites) {
            return $occupant;
        }

        $written = new DeviceUser(
            uid: $occupant->uid,
            userId: $userId,
            name: $occupant->name,
            privilege: $occupant->privilege,
            cardNumber: $cardNumber,
            fingerSlots: $occupant->fingerSlots,
        );

        $this->roster = array_values(array_filter($this->roster, fn (DeviceUser $u) => $u->userId !== $userId));
        $this->roster[] = $written;

        return $written;
    }

    /** @var list<array{0:string,1:bool}> userId/admin pairs that reached the device */
    public array $privilegeWrites = [];

    public function setUserPrivilege(Device $device, string $userId, bool $admin): DeviceUser
    {
        $occupant = $this->find($userId);

        if ($occupant === null) {
            throw new \RuntimeException("User {$userId} is not on the device.");
        }

        $this->privilegeWrites[] = [$userId, $admin];

        $written = new DeviceUser(
            uid: $occupant->uid,
            userId: $userId,
            name: $occupant->name,
            privilege: $admin ? 14 : 0,
            cardNumber: $occupant->cardNumber,
            fingerSlots: $occupant->fingerSlots,
        );

        $this->roster = array_values(array_filter($this->roster, fn (DeviceUser $u) => $u->userId !== $userId));
        $this->roster[] = $written;

        return $written;
    }

    /**
     * Attendance the fake terminal holds, in readAttendance()'s shape. Empty by
     * default, so existing tests see the [] they always did.
     *
     * @var list<array<string,mixed>>
     */
    public array $punchLog = [];

    /** What attendanceRecordCount() reports; null = "cannot say". Defaults to the log's size. */
    public ?int $reportedRecordCount = -1;

    public int $clears = 0;

    public function attendanceRecordCount(Device $device): ?int
    {
        return $this->reportedRecordCount === -1 ? count($this->punchLog) : $this->reportedRecordCount;
    }

    public function clearAttendance(Device $device, int $expectedRecords): int
    {
        if ($this->attendanceRecordCount($device) !== $expectedRecords) {
            throw new \Promoseven\Biometric\Exception\ProtocolException('Refusing to clear attendance: count changed.');
        }

        $this->clears++;
        $held = count($this->punchLog);
        $this->punchLog = [];
        $this->reportedRecordCount = -1;

        return $held;
    }

    public function deleteUser(Device $device, string $userId): void
    {
        $this->roster = array_values(array_filter(
            $this->roster,
            fn (DeviceUser $u) => $u->userId !== $userId,
        ));
        $this->deletes[] = $userId;
    }

    /** @var list<array{0:string,1:string}> from/to pairs */
    public array $renumbers = [];

    public function renumberUser(Device $device, string $fromUserId, string $toUserId): DeviceUser
    {
        $existing = null;
        foreach ($this->roster as $u) {
            if ($u->userId === $toUserId) {
                throw new \Promoseven\Biometric\Exception\ProtocolException("User id {$toUserId} is already held by {$u->name} on this device.");
            }
            if ($u->userId === $fromUserId) {
                $existing = $u;
            }
        }
        if ($existing === null) {
            throw new \Promoseven\Biometric\Exception\ProtocolException("User {$fromUserId} is not on the device.");
        }

        $moved = new DeviceUser(
            uid: $existing->uid,
            userId: $toUserId,
            name: $existing->name,
            privilege: $existing->privilege,
            cardNumber: $existing->cardNumber,
            fingerSlots: $existing->fingerSlots,
        );
        $this->roster = array_values(array_filter($this->roster, fn (DeviceUser $u) => $u->userId !== $fromUserId));
        $this->roster[] = $moved;
        $this->renumbers[] = [$fromUserId, $toUserId];

        return $moved;
    }

    /** @var list<array{user_id:?string,uid:int,slot:int,size:int,sha1:string}> */
    public array $templateDigests = [];

    public function readTemplateDigests(Device $device): array
    {
        return $this->templateDigests;
    }

    /** @var list<array<string,mixed>>|null null = derive from $templateDigests with fake bytes */
    public ?array $templates = null;

    public bool $templatesUnsupported = false;

    public function readTemplates(Device $device): array
    {
        if ($this->templatesUnsupported) {
            throw new \Promoseven\Biometric\Exception\UnsupportedCommandException('No template table here.');
        }

        return $this->templates ?? array_map(fn (array $r) => $r + ['valid' => 1, 'template' => 'bytes-'.$r['sha1']], $this->templateDigests);
    }

    /** @var list<array{user_id:string, slot:int, template:string}> templates that reached the device */
    public array $uploads = [];

    /** Simulates a device that ACKs a template write and then drops it. */
    public bool $dropUploads = false;

    public function uploadTemplates(Device $device, string $userId, array $templates): array
    {
        $occupant = $this->find($userId);
        if ($occupant === null) {
            throw new \RuntimeException("User {$userId} is not on the device.");
        }

        $written = [];
        foreach ($templates as $t) {
            if (in_array($t['slot'], $occupant->fingerSlots ?? [], true)) {
                continue;
            }
            $this->uploads[] = ['user_id' => $userId, 'slot' => $t['slot'], 'template' => $t['template']];
            if (! $this->dropUploads) {
                $written[] = $t['slot'];
            }
        }

        if ($written !== []) {
            $slots = array_values(array_unique([...($occupant->fingerSlots ?? []), ...$written]));
            sort($slots);
            $this->roster = array_values(array_filter($this->roster, fn (DeviceUser $u) => $u->userId !== $userId));
            $this->roster[] = new DeviceUser(uid: $occupant->uid, userId: $userId, name: $occupant->name,
                privilege: $occupant->privilege, cardNumber: $occupant->cardNumber, fingerSlots: $slots);
        }

        return $written;
    }

    /** @var array<string,string> levels overlaid on the defaults, for firmware unlike the F18 */
    public array $capabilityOverrides = [];

    public function capabilities(Device $device): CapabilitySet
    {
        return CapabilitySet::defaults()->with($this->capabilityOverrides);
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
