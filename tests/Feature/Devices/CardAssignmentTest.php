<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AccessCard;
use App\Models\Device;
use App\Models\DeviceRosterEntry;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Services\Devices\DeviceAdoptionService;
use App\Services\Devices\EnrollmentOrchestratorService;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Value\EnrollmentResult;
use Promoseven\Biometric\Value\DeviceUser;
use Tests\TestCase;

/**
 * Writing a card by NUMBER, the alternative to Scan card.
 *
 * What these protect: the card is written to the right record and nothing else on it
 * changes, a number already on somebody else is refused, and a write the device ACKed
 * but did not keep is reported as a failure, not a success (rule 10).
 *
 * No hardware: the driver is faked.
 */
class CardAssignmentTest extends TestCase
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

    public function test_a_typed_card_is_written_and_everything_else_on_the_record_is_kept(): void
    {
        [$device, $nadia] = $this->deviceWithClaimed([new DeviceUser(9, '5', 'Nadia', 0, null, [0, 1])]);

        $result = $this->service()->assignCard($device, $nadia['Nadia'], 2857525);

        $this->assertSame([['5', 2857525]], $this->driver->cardWrites);
        $onDevice = collect($this->driver->roster)->firstWhere('userId', '5');
        $this->assertSame(2857525, $onDevice->cardNumber);
        $this->assertSame('Nadia', $onDevice->name);
        $this->assertSame([0, 1], $onDevice->fingerSlots);

        $map = EmployeeDeviceMap::where('employee_id', $nadia['Nadia']->id)->first();
        $this->assertSame(2857525, $map->card_number);
        // The stored slots survive: the card write must not turn "known" into "unknown".
        $this->assertSame([0, 1], $map->fingerprint_slots);

        $this->assertSame(1, AccessCard::where('employee_id', $nadia['Nadia']->id)
            ->where('card_number', 2857525)->where('status', AccessCard::ACTIVE)->count());
        $this->assertStringContainsString('#2857525', $result['message']);
    }

    public function test_a_card_that_was_replaced_becomes_active_again_when_written_back(): void
    {
        [$device, $people] = $this->deviceWithClaimed([new DeviceUser(9, '5', 'Nadia')]);
        $nadia = $people['Nadia'];

        $this->service()->assignCard($device, $nadia, 2862247);
        $this->service()->assignCard($device, $nadia, 1234);
        $this->service()->assignCard($device, $nadia, 2862247);

        $active = AccessCard::where('employee_id', $nadia->id)->where('status', AccessCard::ACTIVE)->pluck('card_number');
        $this->assertSame([2862247], $active->all());
    }

    public function test_a_card_already_on_somebody_else_on_that_terminal_is_refused(): void
    {
        [$device, $people] = $this->deviceWithClaimed([
            new DeviceUser(1, '5', 'Nadia', 0, 2857525),
            new DeviceUser(2, '6', 'Omar'),
        ]);

        try {
            $this->service()->assignCard($device, $people['Omar'], 2857525);
            $this->fail('A card held by Nadia was written to Omar.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Nadia', $e->getMessage());
        }

        $this->assertSame([], $this->driver->cardWrites);
    }

    public function test_a_write_the_device_acked_but_did_not_keep_is_a_failure(): void
    {
        [$device, $people] = $this->deviceWithClaimed([new DeviceUser(9, '5', 'Nadia')]);
        $this->driver->dropCardWrites = true;

        try {
            $this->service()->assignCard($device, $people['Nadia'], 2857525);
            $this->fail('A dropped write was reported as a success.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('read back', $e->getMessage());
        }

        $this->assertNull(EmployeeDeviceMap::where('employee_id', $people['Nadia']->id)->first()->card_number);
        $this->assertSame(0, AccessCard::count());
    }

    public function test_the_empty_marker_and_zero_are_not_card_numbers(): void
    {
        [$device, $people] = $this->deviceWithClaimed([new DeviceUser(9, '5', 'Nadia')]);

        foreach ([0, AccessCard::SENTINEL] as $number) {
            try {
                $this->service()->assignCard($device, $people['Nadia'], $number);
                $this->fail("{$number} was accepted as a card.");
            } catch (\InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame([], $this->driver->cardWrites);
    }

    /**
     * Since library 2.19.0 the F18 offers no card scanning at all (DC-033): a scan is
     * refused before anything is sent to the terminal.
     */
    public function test_a_scan_is_refused_on_the_f18(): void
    {
        [$device, $people] = $this->deviceWithClaimed([new DeviceUser(9, '5', 'Nadia', 0, 2862247, [5])]);

        $this->expectException(\Promoseven\Biometric\Exception\UnsupportedCommandException::class);
        try {
            $this->service()->captureCard($device, $people['Nadia']);
        } finally {
            $this->assertSame(2862247, collect($this->driver->roster)->firstWhere('userId', '5')->cardNumber);
        }
    }

    /**
     * What IT Room actually produced on 2026-09-23 when card 2862247 was tapped. The
     * library writes its "card" to the record before we see it, so a bad scan must be
     * undone on the device and reported as a failure, never recorded. Scanning is now
     * off on the F18, so these run on a terminal that offers it (the safety net stays
     * for any model that ever does).
     */
    public function test_a_scan_that_returns_the_verify_marker_puts_the_previous_card_back(): void
    {
        $this->assertBogusScanUndone(0xFFFFFFFF);
    }

    public function test_a_scan_that_returns_a_user_id_fragment_puts_the_previous_card_back(): void
    {
        $this->assertBogusScanUndone(942683705); // bytes "9608", from PIN 960802797
    }

    private function assertBogusScanUndone(int $bogus): void
    {
        [$device, $people] = $this->deviceWithClaimed([new DeviceUser(9, '5', 'Nadia', 0, 2862247, [5])]);
        $this->driver->capabilityOverrides = [Capability::CARD_ENROLL => Capability::IMPLEMENTED];
        $this->driver->capture = function () use ($bogus) {
            $this->overwriteCard('5', $bogus); // what the library's captureCard() does
            return EnrollmentResult::card($bogus);
        };

        $result = $this->service()->captureCard($device, $people['Nadia']);

        $this->assertFalse($result['raw']['success'], sprintf('0x%08X was accepted as a card', $bogus));
        $this->assertSame(2862247, collect($this->driver->roster)->firstWhere('userId', '5')->cardNumber);
        $this->assertSame(2862247, EmployeeDeviceMap::where('employee_id', $people['Nadia']->id)->value('card_number'));
        $this->assertSame(0, AccessCard::where('card_number', $bogus)->count());
    }

    public function test_a_scan_that_returns_a_real_number_is_kept_and_read_back(): void
    {
        [$device, $people] = $this->deviceWithClaimed([new DeviceUser(9, '5', 'Nadia', 0, null, [5])]);
        $this->driver->capabilityOverrides = [Capability::CARD_ENROLL => Capability::IMPLEMENTED];
        $this->driver->capture = function () {
            $this->overwriteCard('5', 2862247);
            return EnrollmentResult::card(2862247);
        };

        $result = $this->service()->captureCard($device, $people['Nadia']);

        $this->assertTrue($result['raw']['success']);
        $this->assertSame(2862247, EmployeeDeviceMap::where('employee_id', $people['Nadia']->id)->value('card_number'));
    }

    public function test_what_counts_as_not_a_card(): void
    {
        foreach ([null, 0, 0xFFFFFFFF, 942683705] as $n) {
            $this->assertTrue(EnrollmentOrchestratorService::isNotACard($n), (string) $n);
        }
        foreach ([2862247, 2857525, 1] as $n) {
            $this->assertFalse(EnrollmentOrchestratorService::isNotACard($n), (string) $n);
        }
    }

    private function overwriteCard(string $pin, int $card): void
    {
        $this->driver->roster = array_map(
            fn (DeviceUser $u) => $u->userId === $pin
                ? new DeviceUser($u->uid, $u->userId, $u->name, $u->privilege, $card, $u->fingerSlots)
                : $u,
            $this->driver->roster,
        );
    }

    // ------------------------------------------------------------------- helpers

    /**
     * An adopted device whose every roster entry is claimed by an employee of the
     * same name, as an operator would leave it.
     *
     * @param  list<DeviceUser>  $roster
     * @return array{0: Device, 1: array<string, Employee>}
     */
    private function deviceWithClaimed(array $roster): array
    {
        $device = Device::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'IT Room',
            'ip_address' => '192.168.7.62',
            'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY,
            'is_active' => true,
        ]);
        $this->driver->roster = $roster;

        $adoption = app(DeviceAdoptionService::class);
        $adoption->readRoster($device);

        $people = [];
        foreach (DeviceRosterEntry::where('device_id', $device->id)->get() as $entry) {
            $employee = Employee::create([
                'tenant_id' => $this->tenant->id,
                'name' => $entry->device_name,
                'employee_code' => 'E-'.$entry->device_user_id,
                'is_active' => true,
            ]);
            $adoption->claim($entry, $employee);
            $people[$employee->name] = $employee;
        }

        $adoption->finishAdoption($device->refresh());

        // Seed the mapping with what the device holds, as a roster read would.
        foreach ($roster as $user) {
            EmployeeDeviceMap::where('device_id', $device->id)->where('device_user_id', $user->userId)
                ->update(['card_number' => $user->cardNumber, 'fingerprint_slots' => $user->fingerSlots]);
        }

        return [$device->refresh(), $people];
    }

    private function service(): EnrollmentOrchestratorService
    {
        return app(EnrollmentOrchestratorService::class);
    }
}
