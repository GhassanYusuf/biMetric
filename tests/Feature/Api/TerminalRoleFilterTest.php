<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Employees → Terminal role filter (owner, 2026-11-10: "who is admin and where, with filters"). */
class TerminalRoleFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_administrators_and_normal_users_overall_and_per_terminal(): void
    {
        Tenancy::clear();
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($tenant);
        Tenancy::set($tenant->id);
        $admin = User::withoutGlobalScopes()->create(['name' => 'A', 'email' => 'a@acme.test', 'password' => 'secret-pass-1', 'tenant_id' => $tenant->id, 'is_active' => true, 'email_verified_at' => now()]);
        $admin->syncRoles(['tenant-admin']);

        $camelot = Device::create(['name' => 'Camelot', 'ip_address' => '192.0.2.60', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'is_active' => true]);
        $itRoom = Device::create(['name' => 'IT ROOM', 'ip_address' => '192.0.2.62', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'is_active' => true]);
        $ragesh = Employee::create(['name' => 'Ragesh', 'employee_code' => '870156500', 'is_active' => true]);
        $charmae = Employee::create(['name' => 'Charmae', 'employee_code' => '930326288', 'is_active' => true]);
        $nobody = Employee::create(['name' => 'Loose', 'employee_code' => '999', 'is_active' => true]);
        EmployeeDeviceMap::create(['device_id' => $camelot->id, 'employee_id' => $ragesh->id, 'device_user_id' => '870156500', 'device_privilege' => 14]);
        EmployeeDeviceMap::create(['device_id' => $itRoom->id, 'employee_id' => $ragesh->id, 'device_user_id' => '870156500', 'device_privilege' => 0]);
        EmployeeDeviceMap::create(['device_id' => $camelot->id, 'employee_id' => $charmae->id, 'device_user_id' => '930326288', 'device_privilege' => 0]);
        // A former administrator link, closed: must not count.
        EmployeeDeviceMap::create(['device_id' => $itRoom->id, 'employee_id' => $charmae->id, 'device_user_id' => '5', 'device_privilege' => 14, 'valid_to' => now()->subDay()]);
        Tenancy::clear();

        $names = fn (string $q) => collect($this->actingAs($admin, 'sanctum')->getJson('/api/employees?per_page=50&'.$q)->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Ragesh'], $names('terminal_role=admin'));
        $this->assertSame(['Charmae'], $names('terminal_role=user'));
        $this->assertSame(['Ragesh'], $names("terminal_role=admin&device_id={$camelot->id}"));
        $this->assertSame([], $names("terminal_role=admin&device_id={$itRoom->id}"));
        $this->assertSame(['Ragesh'], $names("terminal_role=user&device_id={$itRoom->id}"));
        $this->assertSame(['Charmae', 'Loose', 'Ragesh'], $names('per_page=50'));
        $this->assertSame(14, collect($this->actingAs($admin, 'sanctum')->getJson('/api/employees?terminal_role=admin')->json('data.0.enrollments'))->firstWhere('device_id', $camelot->id)['device_privilege']);
    }
}
