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

    /**
     * Since 2026-10-11 a write prepares the terminal itself: it reads the roster and,
     * finding a stranger there, refuses — the same protection the manual step gave,
     * without the operator having to take it.
     */
    public function test_a_new_device_holding_a_stranger_refuses_the_write(): void
    {
        $device = $this->device();
        $employee = $this->employee('Nadia', '100');
        $this->driver->roster = [new DeviceUser(1, '5', 'Existing Person')];

        try {
            $this->sync()->pushEmployee($device, $employee);
            $this->fail('The write should have been refused.');
        } catch (DeviceNotAdoptedException $e) {
            $this->assertStringContainsString('Existing Person (PIN 5)', $e->getMessage());
        }

        // Read, not written: the stranger is recorded and still the only one there.
        $this->assertSame(Device::READ_ONLY, $device->refresh()->adoption_status);
        $this->assertCount(1, $this->driver->roster);
    }

    public function test_a_new_device_with_nobody_unmatched_is_adopted_by_the_write_itself(): void
    {
        $device = $this->device();
        $employee = $this->employee('Nadia', '100');
        $this->driver->roster = [];
        $this->app->make(DeviceIdentityResolver::class)->claim($device, $employee);

        $this->sync()->pushEmployee($device, $employee);

        $device->refresh();
        $this->assertSame(Device::ADOPTED, $device->adoption_status);
        $this->assertNotNull($device->roster_read_at);
        $this->assertCount(1, $this->driver->roster);
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

    /**
     * SQL Server returns integer columns as strings. The ownership check compares ids
     * strictly, so an uncast "1414" !== 1414 refused a push to the employee who owned
     * the slot (IT Room, 2026-09-23). SQLite returns integers, so the flow test above
     * cannot catch it; this pins the casts instead.
     */
    public function test_ownership_keys_are_integers_whatever_the_driver_returns(): void
    {
        $entry = (new DeviceRosterEntry())->setRawAttributes([
            'tenant_id' => '1', 'device_id' => '2', 'claimed_employee_id' => '1414', 'claimed_by' => '7',
        ]);
        $map = (new EmployeeDeviceMap())->setRawAttributes([
            'tenant_id' => '1', 'device_id' => '2', 'employee_id' => '1414',
        ]);

        $this->assertSame(1414, $entry->claimed_employee_id);
        $this->assertSame(2, $entry->device_id);
        $this->assertSame(7, $entry->claimed_by);
        $this->assertSame(1414, $map->employee_id);
        $this->assertSame(2, $map->device_id);
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

    /**
     * Owner, 2026-10-21 (casebook DC-026): Syed's CPR was typed onto Asif, pushed to Good
     * Wheels, then corrected. The number must still be able to go to Syed — and Asif's
     * punches under it must stay Asif's.
     */
    public function test_a_personnel_id_given_to_the_wrong_person_can_go_to_the_right_one(): void
    {
        $device = $this->adoptedDeviceHolding([], 'Good Wheels');
        $asif = $this->employee('Muhammad Asif', '850256461');
        $syed = $this->employee('Syed Wajid Abbas', '770280340');
        $this->heldUntil($device, $asif, '770280340', '2026-10-18 09:38:13', '2026-10-21 08:32:05');
        DeviceRosterEntry::create([
            'tenant_id' => $this->tenant->id, 'device_id' => $device->id, 'device_uid' => 29,
            'device_user_id' => '770280340', 'device_name' => 'Muhammad Asif',
            'claim_status' => DeviceRosterEntry::IGNORED, 'claimed_employee_id' => null,
        ]);
        $this->punch($device, '770280340', '2026-10-20 07:58:00', $asif);

        $map = $this->resolver()->claim($device, $syed, '770280340');

        $this->assertSame('770280340', $map->device_user_id);
        $this->assertSame($syed->id, (int) $map->employee_id);
        // Asif's punch stays Asif's; one from now on is Syed's.
        $this->assertSame($asif->id, (int) EmployeeDeviceMap::inForceAt($device->id, '770280340', new \DateTimeImmutable('2026-10-20 07:58:00'))->value('employee_id'));
        $this->assertSame($syed->id, (int) EmployeeDeviceMap::inForceAt($device->id, '770280340', now()->addMinute())->value('employee_id'));
        // The reservation is Syed's now, so the slot guard recognises his record later.
        $entry = DeviceRosterEntry::where('device_id', $device->id)->where('device_user_id', '770280340')->first();
        $this->assertSame(DeviceRosterEntry::CLAIMED, $entry->claim_status);
        $this->assertSame($syed->id, (int) $entry->claimed_employee_id);
    }

    public function test_a_number_someone_holds_now_is_never_taken_over(): void
    {
        $device = $this->adoptedDeviceHolding([]);
        $holder = $this->employee('Holder', 'E-1');
        $other = $this->employee('Other', 'E-2');
        $this->resolver()->claim($device, $holder, '555');

        $this->expectException(\App\Exceptions\PinInUseException::class);
        $this->resolver()->claim($device, $other, '555');
    }

    /**
     * A punch dated after the last holder left — a terminal clock far ahead, or one
     * nobody was mapped to — would fall into the new holder's window. Refused.
     */
    public function test_a_number_with_a_punch_after_its_holder_left_is_not_taken_over(): void
    {
        $device = $this->adoptedDeviceHolding([]);
        $leaver = $this->employee('Leaver', 'E-1');
        $joiner = $this->employee('Joiner', 'E-2');
        $this->heldUntil($device, $leaver, '777', '2026-01-01 00:00:00', '2026-06-01 00:00:00');
        $this->punch($device, '777', '2030-01-30 09:00:00', null);

        $this->expectException(\App\Exceptions\PinInUseException::class);
        $this->resolver()->claim($device, $joiner, '777');
    }

    private function heldUntil(Device $device, Employee $employee, string $pin, string $from, string $to): void
    {
        EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id, 'device_id' => $device->id,
            'device_user_id' => $pin, 'valid_from' => $from, 'valid_to' => $to, 'closed_reason' => 'Removed from device',
        ]);
    }

    private function punch(Device $device, string $pin, string $at, ?Employee $employee): void
    {
        \App\Models\AttendanceLog::create([
            'tenant_id' => $this->tenant->id, 'device_id' => $device->id, 'device_user_id' => $pin,
            'employee_id' => $employee?->id, 'punched_at' => $at, 'status' => 0, 'verify_method' => 1,
        ]);
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

    /**
     * A C3 stores 041112750 as 41112750, so its driver reports a "move" to the
     * employee code as the person already being there (2026-10-11). That must read as
     * "already done" — not a failure, and not a closed-and-reopened mapping on every
     * save.
     */
    public function test_a_move_the_driver_finds_already_done_changes_nothing(): void
    {
        $device = $this->adoptedDeviceHolding([]);
        $employee = $this->employee('Tariq', '041112750');
        $this->app->make(DeviceIdentityResolver::class)->claim($device, $employee, '41112750');
        $mapId = EmployeeDeviceMap::where('employee_id', $employee->id)->value('id');

        // Stand-in for C3Driver::renumberUser() on a Pin that only differs by a
        // leading zero: it returns the record where it already is.
        $this->driver->capabilityOverrides = [Capability::USER_RENUMBER => 'implemented'];
        $driver = new class extends FakeBiometricDriver {
            public function renumberUser(Device $device, string $fromUserId, string $toUserId): DeviceUser
            {
                return new DeviceUser(319, $fromUserId, 'Tariq');
            }
        };
        $driver->capabilityOverrides = $this->driver->capabilityOverrides;
        $this->app->instance(BiometricDeviceDriver::class, $driver);

        $result = $this->app->make(DeviceUserSyncService::class)->renumberEmployee($device, $employee, '041112750');

        $this->assertStringContainsString('already user ID 41112750', $result['message']);
        $this->assertSame(1, EmployeeDeviceMap::where('employee_id', $employee->id)->count());
        $this->assertNull(EmployeeDeviceMap::find($mapId)->valid_to);
    }

    /**
     * The person was once seen at the new number, so a roster entry for it — claimed
     * by them — is still there. The move must replace it, not crash on the unique
     * (device, PIN) index after the hardware has already moved (Ghassan, 2026-10-11).
     */
    public function test_a_move_onto_a_number_this_person_once_held_records_cleanly(): void
    {
        $device = $this->adoptedDeviceHolding([]);
        $employee = $this->employee('Ghassan', '830108246');
        $this->driver->roster = [new DeviceUser(247, '656', 'Gassan')];
        $this->app->make(DeviceIdentityResolver::class)->claim($device, $employee, '656');
        DeviceRosterEntry::create([
            'tenant_id' => $this->tenant->id, 'device_id' => $device->id, 'device_uid' => 247,
            'device_user_id' => '656', 'device_name' => 'Gassan',
            'claim_status' => DeviceRosterEntry::CLAIMED, 'claimed_employee_id' => $employee->id,
        ]);
        DeviceRosterEntry::create([
            'tenant_id' => $this->tenant->id, 'device_id' => $device->id, 'device_uid' => 247,
            'device_user_id' => '830108246', 'device_name' => 'Gassan',
            'claim_status' => DeviceRosterEntry::CLAIMED, 'claimed_employee_id' => $employee->id,
        ]);
        $this->driver->capabilityOverrides = [Capability::USER_RENUMBER => 'implemented'];

        $this->sync()->renumberEmployee($device, $employee, '830108246');

        $this->assertSame('830108246', $this->app->make(DeviceIdentityResolver::class)->activeMap($device, $employee)->device_user_id);
        $this->assertSame(['830108246'], DeviceRosterEntry::where('device_id', $device->id)->pluck('device_user_id')->all());
    }

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
