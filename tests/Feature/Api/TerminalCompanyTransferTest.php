<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Department;
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
 * Employees → Move terminal to company (owner, 2026-11-02): "transfer all the records under a
 * certain terminal to a certain company directly". Careful by default: only people in no
 * company move unless asked; nothing is written to a terminal.
 */
class TerminalCompanyTransferTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Device $jjs;

    private Company $jjsCo;

    private Company $promoseven;

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
        $this->jjs = Device::create(['name' => 'JJs', 'ip_address' => '192.0.2.6', 'port' => 4370, 'protocol' => 'zkteco']);
        $other = Device::create(['name' => 'IT ROOM', 'ip_address' => '192.0.2.62', 'port' => 4370, 'protocol' => 'zkteco']);
        $this->jjsCo = Company::create(['name' => "JJ'S"]);
        $this->promoseven = Company::create(['name' => 'PROMOSEVEN']);
        $finance = Department::create(['name' => 'Finance', 'company_id' => $this->promoseven->id]);

        $on = function (string $code, Device $device, array $extra = []) {
            $e = Employee::create(['name' => "E{$code}", 'employee_code' => $code, 'is_active' => true] + $extra);
            EmployeeDeviceMap::create(['employee_id' => $e->id, 'device_id' => $device->id, 'device_user_id' => $code, 'valid_from' => now()]);

            return $e;
        };
        $on('1', $this->jjs);
        $on('2', $this->jjs);
        $on('3', $this->jjs, ['department_id' => $finance->id]);     // in PROMOSEVEN › Finance
        $on('4', $this->jjs, ['company_id' => $this->jjsCo->id]);    // already there
        $on('5', $other);                                           // another terminal
        $closed = $on('6', $this->jjs);                             // no longer on JJs
        EmployeeDeviceMap::where('employee_id', $closed->id)->update(['valid_to' => now()]);
        Tenancy::clear();
    }

    private function api(): self
    {
        $token = $this->admin->createToken('test', ['*'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function companyOf(string $code): ?int
    {
        $id = Employee::withoutGlobalScopes()->where('employee_code', $code)->value('company_id');

        return $id === null ? null : (int) $id;
    }

    public function test_the_preview_says_who_is_on_the_terminal_and_where_they_are(): void
    {
        $this->api()->getJson("/api/employees/move-to-company/preview?device_id={$this->jjs->id}&company_id={$this->jjsCo->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.unassigned', 2)
            ->assertJsonPath('data.already', 1)
            ->assertJsonPath('data.elsewhere', [['company' => 'PROMOSEVEN', 'count' => 1]]);
        $this->assertNull($this->companyOf('1'), 'a preview changes nothing');
    }

    public function test_by_default_only_people_in_no_company_move(): void
    {
        $this->api()->postJson('/api/employees/move-to-company', ['device_id' => $this->jjs->id, 'company_id' => $this->jjsCo->id])
            ->assertOk()
            ->assertJsonPath('data.moved', 2)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('message', "Moved 2 people from JJs into JJ'S. 1 already in another company was left where they are.");

        $this->assertSame($this->jjsCo->id, $this->companyOf('1'));
        $this->assertSame($this->jjsCo->id, $this->companyOf('2'));
        $this->assertSame($this->promoseven->id, $this->companyOf('3'));
        $this->assertNull($this->companyOf('5'), 'not on that terminal');
        $this->assertNull($this->companyOf('6'), 'no longer on that terminal');
        $this->assertSame("JJ'S", Employee::withoutGlobalScopes()->where('employee_code', '1')->value('department'));
    }

    public function test_asked_to_it_also_moves_people_from_other_companies_and_clears_their_department(): void
    {
        $this->api()->postJson('/api/employees/move-to-company', ['device_id' => $this->jjs->id, 'company_id' => $this->jjsCo->id, 'include_assigned' => true])
            ->assertOk()->assertJsonPath('data.moved', 3)->assertJsonPath('data.skipped', 0);

        $this->assertSame($this->jjsCo->id, $this->companyOf('3'));
        $this->assertNull(Employee::withoutGlobalScopes()->where('employee_code', '3')->value('department_id'));
    }

    public function test_it_needs_the_right_to_change_employees_and_real_targets(): void
    {
        Tenancy::set($this->tenant->id);
        $viewer = User::withoutGlobalScopes()->create([
            'name' => 'Viewer', 'email' => 'viewer@acme.test', 'password' => 'secret-pass-1',
            'tenant_id' => $this->tenant->id, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        Tenancy::clear();
        $token = $viewer->createToken('test', ['*'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/employees/move-to-company', ['device_id' => $this->jjs->id, 'company_id' => $this->jjsCo->id])
            ->assertForbidden();

        $this->api()->postJson('/api/employees/move-to-company', ['device_id' => 999, 'company_id' => 999])
            ->assertUnprocessable()->assertJsonValidationErrors(['device_id', 'company_id']);
    }
}
