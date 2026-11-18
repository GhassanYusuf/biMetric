<?php

namespace Tests\Feature\Api;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Promoseven\Biometric\Capability\Capability;
use Tests\Feature\Devices\FakeBiometricDriver;
use Tests\TestCase;

/** The device page's Door section (owner, 2026-11-18): GET /api/devices/{id}/door, read-only. */
class DeviceDoorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Device $door;

    private FakeBiometricDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($tenant);
        Tenancy::set($tenant->id);
        $this->admin = User::withoutGlobalScopes()->create(['name' => 'A', 'email' => 'a@acme.test', 'password' => 'secret-pass-1', 'tenant_id' => $tenant->id, 'is_active' => true, 'email_verified_at' => now()]);
        $this->admin->syncRoles(['tenant-admin']);
        $this->door = Device::create(['name' => 'Glass Door', 'model' => 'inBIO460', 'ip_address' => '192.0.2.61', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'is_active' => true]);
        $e = Employee::create(['name' => 'Yusuf', 'last_name' => 'Khalid', 'employee_code' => '659843', 'is_active' => true]);
        EmployeeDeviceMap::create(['device_id' => $this->door->id, 'employee_id' => $e->id, 'device_user_id' => '659843', 'valid_from' => now()]);
        Tenancy::clear();

        $this->driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $this->driver);
    }

    public function test_it_reports_door_state_and_events_with_names(): void
    {
        $this->driver->capabilityOverrides = [Capability::DOOR_STATUS => Capability::IMPLEMENTED];
        $this->driver->door = [
            'status' => ['doors' => [
                1 => ['sensor' => 'closed', 'alarm' => false, 'open_too_long' => false],
                2 => ['sensor' => 'open', 'alarm' => false, 'open_too_long' => true],
                3 => ['sensor' => 'unknown', 'alarm' => false, 'open_too_long' => false],
            ], 'time' => new \DateTimeImmutable('2026-11-18 12:00:00')],
            'events' => [
                ['time' => new \DateTimeImmutable('2026-11-18 11:59:00'), 'what' => 'opened by a valid user', 'door' => 1, 'pin' => '659843'],
                ['time' => new \DateTimeImmutable('2026-11-18 11:58:00'), 'what' => 'opened by exit button', 'door' => 1, 'pin' => null],
            ],
            'settings' => [],
        ];

        $res = $this->actingAs($this->admin, 'sanctum')->getJson("/api/devices/{$this->door->id}/door")->assertOk();

        $this->assertSame([1, 2], collect($res->json('data.status.doors'))->pluck('door')->all(), 'an unwired door is left out');
        $this->assertSame('open', $res->json('data.status.doors.1.sensor'));
        $this->assertTrue($res->json('data.status.doors.1.open_too_long'));
        $this->assertSame('Yusuf Khalid', $res->json('data.events.0.name'));
        $this->assertNull($res->json('data.events.1.name'));
        $this->assertSame('implemented', $res->json('data.levels.status'));
    }

    public function test_a_terminal_with_events_only_has_no_status_and_its_lock_settings(): void
    {
        $this->driver->door = [
            'status' => null,
            'events' => [['time' => new \DateTimeImmutable('2026-11-16 15:44:46'), 'what' => 'exit button pressed', 'door' => null, 'pin' => null]],
            'settings' => ['LockOn' => '10', 'DoorSensorTimeout' => '30', 'MasterState' => ''],
        ];

        $res = $this->actingAs($this->admin, 'sanctum')->getJson("/api/devices/{$this->door->id}/door")->assertOk();

        $this->assertNull($res->json('data.status'));
        $this->assertSame(['LockOn' => '10', 'DoorSensorTimeout' => '30'], $res->json('data.settings'), 'an unanswered setting is left out');
    }

    public function test_a_terminal_that_reports_no_door_is_refused_before_it_is_asked(): void
    {
        $this->driver->capabilityOverrides = [Capability::DOOR_EVENTS => Capability::UNKNOWN, Capability::DOOR_STATUS => Capability::UNKNOWN];

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/devices/{$this->door->id}/door")->assertStatus(422);
    }

    public function test_it_needs_permission_to_see_terminals(): void
    {
        Tenancy::set($this->admin->tenant_id);
        $staff = User::withoutGlobalScopes()->create(['name' => 'S', 'email' => 's@acme.test', 'password' => 'secret-pass-1', 'tenant_id' => $this->admin->tenant_id, 'is_active' => true, 'email_verified_at' => now()]);
        $staff->syncRoles(['employee']);
        Tenancy::clear();

        $this->actingAs($staff, 'sanctum')->getJson("/api/devices/{$this->door->id}/door")->assertForbidden();
    }
}
