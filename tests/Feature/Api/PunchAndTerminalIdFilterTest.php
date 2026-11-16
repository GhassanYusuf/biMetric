<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Employees → "Punches" and "Terminal ID" filters (owner, 2026-11-16: "a filter for those who do
 * not have punches, and for whose device user ID is the same as their personal ID").
 */
class PunchAndTerminalIdFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Device $camelot;

    private Device $itRoom;

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
        $this->camelot = Device::create(['name' => 'Camelot', 'ip_address' => '192.0.2.60', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'is_active' => true]);
        $this->itRoom = Device::create(['name' => 'IT ROOM', 'ip_address' => '192.0.2.62', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'is_active' => true]);

        // Same ID on both terminals, punches.
        $same = Employee::create(['name' => 'Same', 'employee_code' => '870156500', 'is_active' => true]);
        $this->map($same, $this->camelot, '870156500');
        $this->map($same, $this->itRoom, '870156500');
        AttendanceLog::create(['employee_id' => $same->id, 'device_id' => $this->camelot->id, 'device_user_id' => '870156500', 'punched_at' => now()->subDay()]);

        // Same on Camelot, a short code on IT ROOM; no punches.
        $mixed = Employee::create(['name' => 'Mixed', 'employee_code' => '930326288', 'is_active' => true]);
        $this->map($mixed, $this->camelot, '930326288');
        $this->map($mixed, $this->itRoom, '12');

        // A closed link under another ID must not count; the live one matches.
        $moved = Employee::create(['name' => 'Moved', 'employee_code' => '850872073', 'is_active' => true]);
        $this->map($moved, $this->itRoom, '850872073');
        EmployeeDeviceMap::create(['device_id' => $this->camelot->id, 'employee_id' => $moved->id, 'device_user_id' => '5', 'valid_to' => now()->subDay()]);

        // On no terminal: neither same nor different.
        Employee::create(['name' => 'Loose', 'employee_code' => '999', 'is_active' => true]);
        Tenancy::clear();
    }

    private function map(Employee $e, Device $d, string $id): void
    {
        EmployeeDeviceMap::create(['device_id' => $d->id, 'employee_id' => $e->id, 'device_user_id' => $id]);
    }

    private function names(string $q): array
    {
        return collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/employees?per_page=50&'.$q)->assertOk()->json('data'))
            ->pluck('name')->sort()->values()->all();
    }

    public function test_no_punches_and_has_punches(): void
    {
        $this->assertSame(['Loose', 'Mixed', 'Moved'], $this->names('punches=none'));
        $this->assertSame(['Same'], $this->names('punches=some'));
    }

    public function test_terminal_id_same_as_personnel_id_on_every_terminal(): void
    {
        $this->assertSame(['Moved', 'Same'], $this->names('device_user_id=same'));
        $this->assertSame(['Mixed'], $this->names('device_user_id=different'));
    }

    public function test_terminal_id_on_the_chosen_terminal_only(): void
    {
        $this->assertSame(['Mixed', 'Same'], $this->names("device_user_id=same&device_id={$this->camelot->id}"));
        $this->assertSame(['Mixed'], $this->names("device_user_id=different&device_id={$this->itRoom->id}"));
        $this->assertSame([], $this->names("device_user_id=different&device_id={$this->camelot->id}"));
    }

    public function test_the_export_lists_the_same_people_and_says_which_filter(): void
    {
        $data = app(\App\Services\Reports\EmployeeRoster::class)->build(\Illuminate\Http\Request::create('/', 'GET', ['punches' => 'none', 'device_user_id' => 'different']));

        $this->assertSame(['Mixed'], array_column($data['rows'], 'name'));
        $this->assertContains('No punches', $data['filterLines']);
        $this->assertContains('Terminal ID ≠ Personnel ID', $data['filterLines']);
    }
}
