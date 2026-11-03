<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Services\Employees\EmployeeDevicePropagator;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Promoseven\Biometric\Exception\ProtocolException;
use Promoseven\Biometric\Value\DeviceUser;
use Tests\TestCase;

/**
 * Casebook DC-038 (2026-11-03): a merge left Suji's duplicate under his CPR on both door
 * panels. Our records let the push through, the PANEL refused ("Pin 711247579 is already
 * used"), and the red row had no button. A panel's pin_in_use refusal (library 2.22.0) now
 * carries the same "Free this number" action as the system's own check, unless an employee
 * is linked to that number there.
 */
class PanelNumberInUseTest extends TestCase
{
    use RefreshDatabase;

    private Device $door;

    private Employee $suji;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        Tenancy::set($tenant->id);
        $this->door = Device::create(['name' => 'Entrance', 'ip_address' => '192.0.2.60', 'port' => 4370, 'protocol' => 'zkteco', 'is_active' => true]);
        $this->door->forceFill(['adoption_status' => 'adopted'])->save();
        $this->suji = Employee::create(['name' => 'SUJI RAMANKUTTY', 'employee_code' => '711247579', 'is_active' => true]);

        $this->app->instance(BiometricDeviceDriver::class, new class extends FakeBiometricDriver
        {
            public function saveUser(Device $device, string $userId, string $name, ?\Closure $guard = null, bool $onlyIfMissing = false, ?int $privilege = null, ?string $password = null): DeviceUser
            {
                // The panel already holds a user 711247579 (the leftover duplicate).
                throw new ProtocolException("Pin 711247579 is already used in 'user' on this panel.", ['reason' => 'pin_in_use', 'pin' => '711247579', 'table' => 'user']);
            }
        });
    }

    private function failure(): array
    {
        $result = app(EmployeeDevicePropagator::class)->push($this->suji, [$this->door], toEmployeeCode: true);
        $this->assertCount(1, $result['failed'], json_encode($result));

        return $result['failed'][0];
    }

    public function test_a_panel_refusal_for_a_number_nobody_holds_offers_to_free_it(): void
    {
        $failure = $this->failure();

        $this->assertStringContainsString('already used', $failure['error']);
        $this->assertSame(['type' => 'release_number', 'device_user_id' => '711247579', 'holder' => null], $failure['action']);
    }

    public function test_a_number_another_employee_holds_there_is_never_offered(): void
    {
        $other = Employee::create(['name' => 'Someone', 'employee_code' => '42', 'is_active' => true]);
        EmployeeDeviceMap::create(['employee_id' => $other->id, 'device_id' => $this->door->id, 'device_user_id' => '711247579', 'valid_from' => now()]);

        $failure = app(EmployeeDevicePropagator::class)->push($this->suji, [$this->door], toEmployeeCode: true)['failed'][0] ?? null;
        $this->assertNotNull($failure, 'the push still fails, just without the offer');

        $this->assertNull($failure['action'] ?? null);
    }
}
