<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Exceptions\DeviceIdentityConflictException;
use App\Exceptions\DeviceNotAdoptedException;
use App\Models\Device;
use App\Models\DeviceRosterEntry;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Services\Devices\DeviceAdoptionService;
use App\Services\Devices\DeviceIdentityResolver;
use App\Services\Devices\DeviceUserSyncService;
use App\Support\DeviceProgress;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Capability\CapabilitySet;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Value\DeviceUser;
use Tests\TestCase;

/**
 * The guards that stop a device write from destroying somebody's enrollment.
 *
 * WHAT THESE PROTECT AGAINST, in production terms: on 2026-09-15 employees were
 * pushed to terminals that had been in service for years. Each employee was addressed
 * by payroll code, which on those terminals was already somebody else's slot. The
 * ZKTeco save() is an upsert — it did not fail, it OVERWROTE, taking the previous
 * person's fingerprint templates with it. Templates cannot be restored from a backup;
 * the employee has to stand at the terminal and re-enrol.
 *
 * So these tests assert refusals, not successes. Each one is a write that used to go
 * through and now must not.
 *
 * No hardware: the driver is faked, exactly as the codec tests use captured fixtures.
 */
class DeviceIdentityGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private FakeBiometricDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        Tenancy::set($this->tenant);

        $this->driver = new FakeBiometricDriver();
        $this->app->instance(BiometricDeviceDriver::class, $this->driver);
    }

    // ---------------------------------------------------------------- adoption gate

    public function test_a_new_device_refuses_writes_until_its_roster_is_accounted_for(): void
    {
        $device = $this->device();
        $employee = $this->employee('Nadia', '100');

        $this->assertSame(Device::UNADOPTED, $device->adoption_status);

        $this->expectException(DeviceNotAdoptedException::class);

        $this->sync()->pushEmployee($device, $employee);
    }

    public function test_reading_a_roster_leaves_the_device_read_only_while_entries_are_undecided(): void
    {
        $device = $this->device();
        $this->driver->roster = [new DeviceUser(1, '5', 'Existing Person', 0, null, [0, 1])];

        $this->adoption()->readRoster($device->refresh());

        $device->refresh();
        $this->assertSame(Device::READ_ONLY, $device->adoption_status);
        $this->assertFalse($device->isWritable());
        $this->assertSame(1, $this->adoption()->pendingCount($device));
    }

    public function test_adoption_is_refused_while_anyone_on_the_device_is_unclaimed(): void
    {
        $device = $this->device();
        $this->driver->roster = [new DeviceUser(1, '5', 'Existing Person')];
        $this->adoption()->readRoster($device);

        $this->expectExceptionMessageMatches('/nobody has claimed or ignored/');

        $this->adoption()->finishAdoption($device->refresh());
    }

    public function test_a_device_becomes_writable_once_every_entry_is_decided(): void
    {
        $device = $this->device();
        $this->driver->roster = [new DeviceUser(1, '5', 'Test User')];
        $this->adoption()->readRoster($device);

        $entry = DeviceRosterEntry::where('device_id', $device->id)->firstOrFail();
        $this->adoption()->ignore($entry, 'Installer test account');

        $this->adoption()->finishAdoption($device->refresh());

        $this->assertTrue($device->refresh()->isWritable());
    }

    // ------------------------------------------------------- the overwrite itself

    public function test_a_push_refuses_to_overwrite_a_person_already_in_the_slot(): void
    {
        // A terminal that has been running for years: PIN 5 is a real, enrolled human.
        $device = $this->adoptedDeviceHolding([new DeviceUser(9, '5', 'Existing Person', 0, null, [0, 1])]);

        /*
         * A new employee whose PAYROLL CODE is 5. This is the production case exactly:
         * the old code addressed the device by employee_code, so it wrote to slot 5 and
         * replaced the person already there. A mapping is created at that PIN to get
         * past the "no identity" guard, so that what is under test here is the slot
         * check itself and not the one before it.
         */
        $employee = $this->employee('Nadia', '5');
        EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'device_id' => $device->id,
            'device_user_id' => '5',
            'valid_from' => now(),
        ]);

        try {
            $this->sync()->pushEmployee($device, $employee);
            $this->fail('The push should have been refused.');
        } catch (DeviceIdentityConflictException $e) {
            $this->assertStringContainsString('Existing Person', $e->getMessage());
        }

        // The decisive assertion: the hardware was never written to.
        $this->assertSame([], $this->driver->writes);
        $this->assertSame('Existing Person', $this->driver->roster[0]->name);
        $this->assertSame([0, 1], $this->driver->roster[0]->fingerSlots);
    }

    public function test_a_push_proceeds_when_the_slot_was_claimed_by_that_employee(): void
    {
        // The whole adoption flow, as an operator would drive it: read the roster, then
        // claim the existing device user as a known employee. The claim takes over the
        // PIN the device ALREADY uses, which is what preserves their fingerprints.
        $device = $this->device();
        $this->driver->roster = [new DeviceUser(9, '5', 'Nadia')];
        $this->adoption()->readRoster($device);

        $employee = $this->employee('Nadia', 'E-100');
        $entry = DeviceRosterEntry::where('device_id', $device->id)->firstOrFail();
        $map = $this->adoption()->claim($entry, $employee);

        $this->adoption()->finishAdoption($device->refresh());

        $this->assertSame('5', $map->device_user_id);

        $this->sync()->pushEmployee($device->refresh(), $employee);

        $this->assertSame([['5', 'Nadia']], $this->driver->writes);
    }

    public function test_a_stale_mapping_does_not_authorise_writing_over_the_hardware(): void
    {
        /*
         * Our records say PIN 5 is Nadia's. The terminal says PIN 5 is somebody else —
         * because the mapping was written by the auto-matching this replaced, or the
         * PIN was reassigned on the keypad. The two disagree, and the hardware is the
         * one holding the fingerprints, so it wins (rule 3).
         */
        $device = $this->adoptedDeviceHolding([new DeviceUser(9, '5', 'Existing Person', 0, null, [0, 1])]);

        $employee = $this->employee('Nadia', 'E-100');
        EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $employee->id,
            'device_id' => $device->id,
            'device_user_id' => '5',
            'valid_from' => now(),
        ]);

        $this->expectException(DeviceIdentityConflictException::class);

        try {
            $this->sync()->pushEmployee($device, $employee);
        } finally {
            $this->assertSame([], $this->driver->writes);
            $this->assertSame([0, 1], $this->driver->roster[0]->fingerSlots);
        }
    }

    public function test_a_push_is_refused_when_the_employee_has_no_identity_on_the_device(): void
    {
        $device = $this->adoptedDeviceHolding([]);
        $employee = $this->employee('Nadia', '5');

        // employee_code is '5'. The old code would have written to slot 5 on that
        // basis alone. A payroll number is not a device address.
        $this->expectExceptionMessageMatches('/no identity on/');

        $this->sync()->pushEmployee($device, $employee);
    }

    // ------------------------------------------------------------ PIN allocation

    public function test_a_pin_held_by_an_unclaimed_person_is_never_allocated(): void
    {
        $device = $this->device();
        $this->driver->roster = [
            new DeviceUser(1, '1', 'Installer'),
            new DeviceUser(2, '2', 'Existing Person'),
        ];
        $this->adoption()->readRoster($device);

        $employee = $this->employee('Nadia', 'E-100');
        $map = $this->resolver()->claim($device->refresh(), $employee);

        $this->assertSame('3', $map->device_user_id, 'Allocation must skip PINs occupied on the hardware.');
    }

    public function test_a_retired_pin_is_never_reissued(): void
    {
        $device = $this->adoptedDeviceHolding([]);

        $leaver = $this->employee('Leaver', 'E-1');
        $map = $this->resolver()->claim($device, $leaver);
        $this->assertSame('1', $map->device_user_id);

        $map->close('Left the company');

        $joiner = $this->employee('Joiner', 'E-2');
        $fresh = $this->resolver()->claim($device, $joiner);

        /*
         * Reissuing PIN 1 would silently re-attribute every punch the leaver made
         * before today to the new hire — attendance resolves through the mapping in
         * force at the punch time, and two open-ended mappings on one PIN make that
         * question unanswerable.
         */
        $this->assertNotSame('1', $fresh->device_user_id);
        $this->assertSame('2', $fresh->device_user_id);
    }

    // ------------------------------------------------------ cross-device identity

    public function test_the_same_pin_on_two_devices_stays_two_different_people(): void
    {
        // Taken from the real estate on 2026-09-15: PIN 1 is "akram" on the IT Room
        // F18 and an unrelated person on the Good Wheels terminal.
        $itRoom = $this->adoptedDeviceHolding([new DeviceUser(1, '1', 'akram')], 'IT Room');
        $goodWheels = $this->adoptedDeviceHolding([new DeviceUser(1, '1', 'HABIB UR RAHMAN')], 'Good Wheels');

        $akram = $this->employee('akram', 'E-1');
        $habib = $this->employee('Habib Ur Rahman', 'E-2');

        EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $akram->id,
            'device_id' => $itRoom->id, 'device_user_id' => '1', 'valid_from' => now(),
        ]);
        EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $habib->id,
            'device_id' => $goodWheels->id, 'device_user_id' => '1', 'valid_from' => now(),
        ]);

        // Same number, two devices, two humans — and neither resolves to the other.
        $this->assertSame('1', $this->resolver()->pinFor($itRoom, $akram));
        $this->assertSame('1', $this->resolver()->pinFor($goodWheels, $habib));
        $this->assertNull($this->resolver()->activeMap($itRoom, $habib));
        $this->assertNull($this->resolver()->activeMap($goodWheels, $akram));
    }

    // -------------------------------------------------- attendance through time

    public function test_a_punch_resolves_to_whoever_held_the_pin_at_the_time(): void
    {
        $device = $this->adoptedDeviceHolding([]);

        $leaver = $this->employee('Leaver', 'E-1');
        $joiner = $this->employee('Joiner', 'E-2');

        // PIN 7 was the leaver's until March, and the joiner's since.
        EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $leaver->id,
            'device_id' => $device->id, 'device_user_id' => '7',
            'valid_from' => null, 'valid_to' => '2026-03-01 00:00:00',
            'closed_reason' => 'Left the company',
        ]);
        EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $joiner->id,
            'device_id' => $device->id, 'device_user_id' => '7',
            'valid_from' => '2026-03-01 00:00:00',
        ]);

        $before = EmployeeDeviceMap::inForceAt($device->id, '7', new \DateTimeImmutable('2026-02-14 08:00:00'))
            ->value('employee_id');
        $after = EmployeeDeviceMap::inForceAt($device->id, '7', new \DateTimeImmutable('2026-04-14 08:00:00'))
            ->value('employee_id');

        /*
         * The failure this prevents: resolving both punches to the current holder
         * would credit the leaver's entire attendance history to the new hire, and
         * nothing on any screen would look wrong.
         */
        $this->assertSame($leaver->id, $before);
        $this->assertSame($joiner->id, $after);
    }

    public function test_a_mapping_adopted_from_hardware_covers_punches_older_than_itself(): void
    {
        // Claimed today off a terminal that has been running for years. valid_from is
        // null because we do not know when they were enrolled — stamping today would
        // orphan every punch they have ever made.
        $device = $this->device();
        $this->driver->roster = [new DeviceUser(1, '7', 'Long Serving')];
        $this->adoption()->readRoster($device);

        $employee = $this->employee('Long Serving', 'E-1');
        $entry = DeviceRosterEntry::where('device_id', $device->id)->firstOrFail();
        $map = $this->adoption()->claim($entry, $employee);

        $this->assertNull($map->valid_from);

        $resolved = EmployeeDeviceMap::inForceAt($device->id, '7', new \DateTimeImmutable('2019-06-01 08:00:00'))
            ->value('employee_id');

        $this->assertSame($employee->id, $resolved);
    }

    // ------------------------------------------------------------------- helpers

    private function device(string $name = 'IT Room'): Device
    {
        return Device::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'ip_address' => '192.168.7.62',
            'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY,
            'is_active' => true,
        ]);
    }

    /** @param list<DeviceUser> $roster */
    private function adoptedDeviceHolding(array $roster, string $name = 'IT Room'): Device
    {
        $device = $this->device($name);
        $this->driver->roster = $roster;

        $this->adoption()->readRoster($device);

        foreach (DeviceRosterEntry::where('device_id', $device->id)->get() as $entry) {
            $this->adoption()->ignore($entry, 'Pre-existing, not tracked in this test');
        }

        $this->adoption()->finishAdoption($device->refresh());

        return $device->refresh();
    }

    private function employee(string $name, string $code): Employee
    {
        return Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'employee_code' => $code,
            'is_active' => true,
        ]);
    }

    private function resolver(): DeviceIdentityResolver
    {
        return app(DeviceIdentityResolver::class);
    }

    private function adoption(): DeviceAdoptionService
    {
        return app(DeviceAdoptionService::class);
    }

    private function sync(): DeviceUserSyncService
    {
        return app(DeviceUserSyncService::class);
    }
}
