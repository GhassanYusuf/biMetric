<?php

namespace Tests\Feature\Api;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Devices\FakeBiometricDriver;
use Tests\TestCase;

/**
 * 2026-11-02, found testing the v1 API from Postman: pushing a NEW employee to a terminal
 * failed with "Test has no identity on IT ROOM. Allocate one before writing to the device."
 * The v1 push queued a PUSH_USER without giving them a user ID there, and the website's
 * per-terminal push (employee details, enrollment dialog) did the same synchronously and
 * answered "Server error". Both now take an ID first, by EmployeeDevicePropagator's rule:
 * the Personnel ID when it is free on that terminal, else the next free number.
 */
class FirstPushIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Device $device;

    private FakeBiometricDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);

        Tenancy::set($this->tenant->id);
        $this->admin = User::withoutGlobalScopes()->create([
            'name' => 'Admin', 'email' => 'admin@acme.test', 'password' => 'secret-pass-1',
            'tenant_id' => $this->tenant->id, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $this->admin->syncRoles(['tenant-admin']);
        $this->device = Device::create(['name' => 'IT ROOM', 'ip_address' => '192.0.2.62', 'port' => 4370, 'protocol' => 'zkteco']);
        $this->device->forceFill(['adoption_status' => 'adopted'])->save();
        Tenancy::clear();

        $this->driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $this->driver);
    }

    private function as(): self
    {
        $token = $this->admin->createToken('test', ['*'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function employee(string $name, string $code): Employee
    {
        Tenancy::set($this->tenant->id);
        $e = Employee::create(['name' => $name, 'employee_code' => $code, 'is_active' => true]);
        Tenancy::clear();

        return $e;
    }

    private function pin(Employee $e): ?string
    {
        return EmployeeDeviceMap::withoutGlobalScopes()
            ->where('employee_id', $e->id)->where('device_id', $this->device->id)->whereNull('valid_to')
            ->value('device_user_id');
    }

    public function test_the_v1_push_gives_a_new_employee_their_personnel_id_before_queueing(): void
    {
        Queue::fake();
        $e = $this->employee('Test', '90110121');

        $this->as()->postJson("/api/v1/employees/{$e->id}/devices/{$this->device->id}/push")
            ->assertStatus(202)
            ->assertJsonPath('data.command', DeviceCommand::PUSH_USER);

        $this->assertSame('90110121', $this->pin($e));
    }

    public function test_a_personnel_id_taken_on_the_terminal_gets_the_next_free_number(): void
    {
        Queue::fake();
        $holder = $this->employee('Holder', '555');
        $this->as()->postJson("/api/v1/employees/{$holder->id}/devices/{$this->device->id}/push")->assertStatus(202);

        // Same digits in a free-text code is impossible (unique per company), so take it over
        // through the terminal: a second person whose code is already somebody's user ID there.
        Tenancy::set($this->tenant->id);
        EmployeeDeviceMap::where('employee_id', $holder->id)->update(['device_user_id' => '777']);
        Tenancy::clear();
        $e = $this->employee('Second', '777');

        $this->as()->postJson("/api/v1/employees/{$e->id}/devices/{$this->device->id}/push")->assertStatus(202);

        $this->assertNotNull($this->pin($e));
        $this->assertNotSame('777', $this->pin($e));
    }

    public function test_a_second_push_keeps_the_id_they_already_have(): void
    {
        Queue::fake();
        $e = $this->employee('Test', '90110121');

        $this->as()->postJson("/api/v1/employees/{$e->id}/devices/{$this->device->id}/push")->assertStatus(202);
        $this->as()->postJson("/api/v1/employees/{$e->id}/devices/{$this->device->id}/push")->assertStatus(202);

        $this->assertSame(1, EmployeeDeviceMap::withoutGlobalScopes()->where('employee_id', $e->id)->count());
    }

    public function test_the_websites_per_terminal_push_writes_a_new_employee(): void
    {
        $e = $this->employee('Test', '90110121');

        $this->as()->postJson("/api/employees/{$e->id}/devices/{$this->device->id}/push")->assertOk();

        $this->assertSame('90110121', $this->pin($e));
        $this->assertContains(['90110121', 'Test'], $this->driver->writes);
    }
}
