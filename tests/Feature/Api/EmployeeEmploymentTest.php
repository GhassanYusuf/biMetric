<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessDeviceCommand;
use App\Models\Department;
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
use Tests\TestCase;

/**
 * HR's five changes (2026-10-12): Company and Department as two levels, job title as a
 * short field, employment type, resignation, and the phone format from the code picker.
 *
 * A resignation makes the employee inactive and takes them off the terminals; a
 * resigned employee is never pushed. The list leaves them out when asked to.
 */
class EmployeeEmploymentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

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
        Tenancy::clear();
    }

    /** @param list<string> $abilities */
    private function as(array $abilities = ['*']): self
    {
        $token = $this->admin->createToken('test', $abilities)->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function employee(string $name, string $code, array $extra = []): Employee
    {
        Tenancy::set($this->tenant->id);
        $e = Employee::create(['name' => $name, 'employee_code' => $code, 'is_active' => true] + $extra);
        Tenancy::clear();

        return $e;
    }

    /** @return array<string,mixed> the SPA form's whole-record body */
    private function body(Employee $e, array $changes = []): array
    {
        return ['name' => $e->name, 'employee_code' => $e->employee_code] + $changes;
    }

    public function test_employment_type_defaults_to_full_time_and_can_be_part_time(): void
    {
        $e = $this->employee('Sara', '101');
        $this->assertSame('full_time', $e->fresh()->employment_type);

        $this->as()->putJson("/api/employees/{$e->id}", $this->body($e, ['employment_type' => 'part_time']))
            ->assertOk()
            ->assertJsonPath('data.employment_type', 'part_time');

        $this->as()->putJson("/api/employees/{$e->id}", $this->body($e, ['employment_type' => 'casual']))
            ->assertStatus(422)->assertJsonValidationErrors('employment_type');
    }

    public function test_a_resignation_makes_the_employee_inactive_and_cannot_be_in_the_future(): void
    {
        $e = $this->employee('Omar', '102');

        $this->as()->putJson("/api/employees/{$e->id}", $this->body($e, ['resigned_at' => now()->addDay()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors('resigned_at');

        $this->as()->putJson("/api/employees/{$e->id}", $this->body($e, ['resigned_at' => '2026-10-01', 'is_active' => true]))
            ->assertOk()
            ->assertJsonPath('data.resigned_at', '2026-10-01')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_the_list_leaves_resigned_people_out_when_asked(): void
    {
        $this->employee('Still Here', '201');
        $this->employee('Gone', '202', ['resigned_at' => '2026-09-30']);

        $names = fn ($res) => collect($res->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Still Here'], $names($this->as()->getJson('/api/employees?resigned=0')));
        $this->assertSame(['Gone'], $names($this->as()->getJson('/api/employees?resigned=1')));
        $this->assertCount(2, $names($this->as()->getJson('/api/employees')));
    }

    public function test_a_resigned_employee_is_not_pushed_to_a_terminal(): void
    {
        $e = $this->employee('Gone', '203', ['resigned_at' => '2026-09-30']);
        Tenancy::set($this->tenant->id);
        $device = Device::create(['name' => 'Front door', 'ip_address' => '10.0.0.5', 'port' => 4370, 'protocol' => 'zkteco']);
        Tenancy::clear();

        $this->as()->postJson("/api/employees/{$e->id}/propagate", ['device_ids' => [$device->id]])
            ->assertStatus(409);
        $this->as()->postJson("/api/employees/{$e->id}/devices/{$device->id}/push")
            ->assertStatus(409);
    }

    public function test_company_and_department_come_from_the_tree_and_the_text_column_follows(): void
    {
        Tenancy::set($this->tenant->id);
        $company = Department::create(['name' => 'PROMOSEVEN']);
        $it = Department::create(['name' => 'IT', 'parent_id' => $company->id]);
        Tenancy::clear();
        $e = $this->employee('Ali', '301', ['department_id' => $company->id]);

        $this->as()->putJson("/api/employees/{$e->id}", $this->body($e, ['department_id' => $it->id, 'job_title' => 'Accountant']))
            ->assertOk()
            ->assertJsonPath('data.company.name', 'PROMOSEVEN')
            ->assertJsonPath('data.department_name', 'IT')
            ->assertJsonPath('data.job_title', 'Accountant');

        // The old free-text column, which reports and exports print, now says IT.
        $this->assertSame('IT', $e->fresh()->department);

        $row = collect($this->as()->getJson('/api/employees')->assertOk()->json('data'))->firstWhere('id', $e->id);
        $this->assertSame(['id' => $company->id, 'name' => 'PROMOSEVEN'], $row['company']);
    }

    public function test_phone_numbers_take_the_code_picker_format_and_refuse_letters(): void
    {
        $e = $this->employee('Huda', '401');

        $this->as()->putJson("/api/employees/{$e->id}", $this->body($e, ['mobile_phone' => '+973 3344 5566']))
            ->assertOk()->assertJsonPath('data.mobile_phone', '+973 3344 5566');

        $this->as()->putJson("/api/employees/{$e->id}", $this->body($e, ['home_phone' => 'call me']))
            ->assertStatus(422)->assertJsonValidationErrors('home_phone');
    }

    public function test_resigning_through_the_integration_api_queues_removal_and_keeps_the_record(): void
    {
        Queue::fake();
        Tenancy::set($this->tenant->id);
        $device = Device::create(['name' => 'Front door', 'ip_address' => '10.0.0.5', 'port' => 4370, 'protocol' => 'zkteco']);
        $device->forceFill(['adoption_status' => 'adopted'])->save();
        Tenancy::clear();
        $e = $this->employee('Leaver', '12');
        Tenancy::set($this->tenant->id);
        EmployeeDeviceMap::create(['employee_id' => $e->id, 'device_id' => $device->id, 'device_user_id' => '12']);
        Tenancy::clear();

        $res = $this->as(['employees:write'])->patchJson("/api/v1/employees/{$e->id}", [
            'resigned_at' => '2026-10-01',
            // Ignored: a resignation takes them off, it does not put them on.
            'push_to_device_ids' => [$device->id],
        ])->assertOk()->assertJsonPath('data.is_active', false);

        if ($device->fresh()->userWriteRefusal()) {
            $res->assertJsonCount(1, 'meta.skipped_devices')->assertJsonCount(0, 'meta.device_commands');
        } else {
            $res->assertJsonCount(1, 'meta.device_commands');
            Queue::assertPushed(ProcessDeviceCommand::class);
            $this->assertSame(
                [DeviceCommand::REMOVE_USER],
                DeviceCommand::withoutGlobalScopes()->pluck('command')->all(),
            );
        }

        $this->assertNotNull(Employee::withoutGlobalScopes()->find($e->id), 'a resignation keeps the record');

        // A second save of an already-resigned employee queues nothing more.
        $this->as(['employees:write'])->patchJson("/api/v1/employees/{$e->id}", ['job_title' => 'Ex-clerk'])
            ->assertOk()->assertJsonCount(0, 'meta.device_commands');
    }

    public function test_a_company_or_department_with_anything_in_it_cannot_be_deleted(): void
    {
        Tenancy::set($this->tenant->id);
        $company = Department::create(['name' => 'P7H']);
        $it = Department::create(['name' => 'IT', 'parent_id' => $company->id]);
        $empty = Department::create(['name' => 'Spare', 'parent_id' => $company->id]);
        Tenancy::clear();
        $this->employee('Ali', '501', ['department_id' => $it->id]);

        $this->as()->deleteJson("/api/departments/{$company->id}")->assertStatus(409);
        $this->as()->deleteJson("/api/departments/{$it->id}")->assertStatus(409);
        $this->as()->deleteJson("/api/departments/{$empty->id}")->assertOk();
    }

    public function test_renaming_a_department_renames_it_on_its_employees(): void
    {
        Tenancy::set($this->tenant->id);
        $company = Department::create(['name' => 'P7H']);
        $it = Department::create(['name' => 'IT', 'parent_id' => $company->id]);
        Tenancy::clear();
        $follows = $this->employee('Ali', '601', ['department_id' => $it->id]);
        $own = $this->employee('Imported', '602', ['department_id' => $it->id, 'department' => 'INFO TECH']);

        $this->as()->putJson("/api/departments/{$it->id}", ['name' => 'Technology', 'parent_id' => $company->id])
            ->assertOk()->assertJsonPath('data.name', 'Technology');

        $this->assertSame('Technology', $follows->fresh()->department);
        $this->assertSame('INFO TECH', $own->fresh()->department, 'an imported text of its own is kept');
    }
}
