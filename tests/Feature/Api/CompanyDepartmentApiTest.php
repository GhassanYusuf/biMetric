<?php

namespace Tests\Feature\Api;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Company and department as two fields in the v1 API (owner, 2026-11-02, option 1 of 2).
 * Storage is unchanged: employees.department_id holds the department, or the company when
 * there is none, and the website still reads that one column.
 */
class CompanyDepartmentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Department $promoseven;

    private Department $it;

    private Department $camelot;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();

        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($tenant);

        Tenancy::set($tenant->id);
        $this->admin = User::withoutGlobalScopes()->create([
            'name' => 'Admin', 'email' => 'admin@acme.test', 'password' => 'secret-pass-1',
            'tenant_id' => $tenant->id, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $this->admin->syncRoles(['tenant-admin']);
        $this->promoseven = Department::create(['name' => 'PROMOSEVEN']);
        $this->it = Department::create(['name' => 'Information Technology', 'parent_id' => $this->promoseven->id]);
        $this->camelot = Department::create(['name' => 'Camelot']);
        Tenancy::clear();
    }

    private function api(): self
    {
        $token = $this->admin->createToken('test', ['*'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function stored(string $code): ?int
    {
        $id = Employee::withoutGlobalScopes()->where('employee_code', $code)->value('department_id');

        return $id === null ? null : (int) $id;
    }

    public function test_a_company_alone_is_stored_as_the_company_and_shown_without_a_department(): void
    {
        $this->api()->postJson('/api/v1/employees', ['name' => 'A', 'employee_code' => '11', 'company_id' => $this->camelot->id])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->camelot->id)
            ->assertJsonPath('data.department_id', null)
            ->assertJsonPath('data.department_name', null);

        $this->assertSame($this->camelot->id, $this->stored('11'));
    }

    public function test_company_and_department_are_stored_as_the_department(): void
    {
        $this->api()->postJson('/api/v1/employees', ['name' => 'B', 'employee_code' => '12', 'company_id' => $this->promoseven->id, 'department_id' => $this->it->id])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->promoseven->id)
            ->assertJsonPath('data.department_id', $this->it->id)
            ->assertJsonPath('data.department_name', 'Information Technology')
            ->assertJsonPath('data.company.name', 'PROMOSEVEN');

        $this->assertSame($this->it->id, $this->stored('12'));
    }

    public function test_a_company_sent_as_department_id_is_refused(): void
    {
        $this->api()->postJson('/api/v1/employees', ['name' => 'C', 'department_id' => $this->camelot->id])
            ->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->api()->postJson('/api/v1/employees', ['name' => 'C', 'company_id' => $this->it->id])
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
    }

    public function test_a_department_of_another_company_is_refused_by_name(): void
    {
        $this->api()->postJson('/api/v1/employees', ['name' => 'D', 'company_id' => $this->camelot->id, 'department_id' => $this->it->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.department_id.0', 'Information Technology belongs to PROMOSEVEN, not Camelot.');
    }

    public function test_partial_updates_change_only_what_they_name(): void
    {
        $this->api()->postJson('/api/v1/employees', ['name' => 'E', 'employee_code' => '15', 'department_id' => $this->it->id])
            ->assertCreated()->assertJsonPath('data.company_id', $this->promoseven->id);

        // The same company again keeps the department.
        $this->api()->patchJson('/api/v1/employees/15', ['company_id' => $this->promoseven->id])->assertOk();
        $this->assertSame($this->it->id, $this->stored('15'));

        // Removing the department keeps the company.
        $this->api()->patchJson('/api/v1/employees/15', ['department_id' => null])->assertOk()
            ->assertJsonPath('data.company_id', $this->promoseven->id)->assertJsonPath('data.department_id', null);
        $this->assertSame($this->promoseven->id, $this->stored('15'));

        // Another company drops the department that is not in it.
        $this->api()->patchJson('/api/v1/employees/15', ['department_id' => $this->it->id])->assertOk();
        $this->api()->patchJson('/api/v1/employees/15', ['company_id' => $this->camelot->id])->assertOk()
            ->assertJsonPath('data.company_id', $this->camelot->id)->assertJsonPath('data.department_id', null);

        // Something else entirely leaves both alone.
        $this->api()->patchJson('/api/v1/employees/15', ['job_title' => 'Clerk'])->assertOk();
        $this->assertSame($this->camelot->id, $this->stored('15'));
    }

    public function test_the_list_filters_by_company_including_its_departments(): void
    {
        $this->api()->postJson('/api/v1/employees', ['name' => 'Top', 'employee_code' => '21', 'company_id' => $this->promoseven->id])->assertCreated();
        $this->api()->postJson('/api/v1/employees', ['name' => 'Dept', 'employee_code' => '22', 'department_id' => $this->it->id])->assertCreated();
        $this->api()->postJson('/api/v1/employees', ['name' => 'Elsewhere', 'employee_code' => '23', 'company_id' => $this->camelot->id])->assertCreated();

        $names = fn (string $q) => collect($this->api()->getJson('/api/v1/employees?'.$q)->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Dept', 'Top'], $names('company_id='.$this->promoseven->id));
        $this->assertSame(['Dept'], $names('department_id='.$this->it->id));
    }

    public function test_departments_say_whether_they_are_a_company_and_which(): void
    {
        $rows = collect($this->api()->getJson('/api/v1/departments')->assertOk()->json('data'))->keyBy('name');

        $this->assertTrue($rows['PROMOSEVEN']['is_company']);
        $this->assertFalse($rows['Information Technology']['is_company']);
        $this->assertSame($this->promoseven->id, $rows['Information Technology']['company_id']);
        $this->assertSame($this->camelot->id, $rows['Camelot']['company_id']);
    }
}
