<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Company and department as two fields in the v1 API (owner, 2026-11-02). Since the
 * companies table (2026-11-03) they are two columns too; the API's shape did not change.
 */
class CompanyDepartmentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $promoseven;

    private Department $it;

    private Company $camelot;

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
        $this->promoseven = Company::create(['name' => 'PROMOSEVEN']);
        $this->it = Department::create(['name' => 'Information Technology', 'company_id' => $this->promoseven->id]);
        $this->camelot = Company::create(['name' => 'Camelot']);
        Tenancy::clear();
    }

    private function api(): self
    {
        $token = $this->admin->createToken('test', ['*'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** [company_id, department_id] as stored. */
    private function stored(string $code): array
    {
        $row = Employee::withoutGlobalScopes()->where('employee_code', $code)->first(['company_id', 'department_id']);

        return [$row->company_id === null ? null : (int) $row->company_id, $row->department_id === null ? null : (int) $row->department_id];
    }

    public function test_a_company_alone_is_stored_without_a_department(): void
    {
        $this->api()->postJson('/api/v1/employees', ['name' => 'A', 'employee_code' => '11', 'company_id' => $this->camelot->id])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->camelot->id)
            ->assertJsonPath('data.department_id', null)
            ->assertJsonPath('data.department_name', null);

        $this->assertSame([$this->camelot->id, null], $this->stored('11'));
    }

    public function test_company_and_department_are_stored_together(): void
    {
        $this->api()->postJson('/api/v1/employees', ['name' => 'B', 'employee_code' => '12', 'company_id' => $this->promoseven->id, 'department_id' => $this->it->id])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->promoseven->id)
            ->assertJsonPath('data.department_id', $this->it->id)
            ->assertJsonPath('data.department_name', 'Information Technology')
            ->assertJsonPath('data.company.name', 'PROMOSEVEN');

        $this->assertSame([$this->promoseven->id, $this->it->id], $this->stored('12'));
    }

    public function test_an_id_that_is_not_a_company_or_department_is_refused(): void
    {
        $this->api()->postJson('/api/v1/employees', ['name' => 'C', 'department_id' => 999])
            ->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->api()->postJson('/api/v1/employees', ['name' => 'C', 'company_id' => 999])
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
        $this->assertSame([$this->promoseven->id, $this->it->id], $this->stored('15'));

        // Removing the department keeps the company.
        $this->api()->patchJson('/api/v1/employees/15', ['department_id' => null])->assertOk()
            ->assertJsonPath('data.company_id', $this->promoseven->id)->assertJsonPath('data.department_id', null);
        $this->assertSame([$this->promoseven->id, null], $this->stored('15'));

        // Another company drops the department that is not in it.
        $this->api()->patchJson('/api/v1/employees/15', ['department_id' => $this->it->id])->assertOk();
        $this->api()->patchJson('/api/v1/employees/15', ['company_id' => $this->camelot->id])->assertOk()
            ->assertJsonPath('data.company_id', $this->camelot->id)->assertJsonPath('data.department_id', null);

        // Something else entirely leaves both alone.
        $this->api()->patchJson('/api/v1/employees/15', ['job_title' => 'Clerk'])->assertOk();
        $this->assertSame([$this->camelot->id, null], $this->stored('15'));
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

    /** Companies have their own endpoint since 2026-11-03; departments name their company. */
    public function test_companies_and_departments_are_listed_apart(): void
    {
        $companies = collect($this->api()->getJson('/api/v1/companies')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['Camelot', 'PROMOSEVEN'], $companies);

        $departments = collect($this->api()->getJson('/api/v1/departments')->assertOk()->json('data'));
        $this->assertSame(['Information Technology'], $departments->pluck('name')->all());
        $this->assertSame($this->promoseven->id, $departments[0]['company_id']);
        $this->assertSame('PROMOSEVEN', $departments[0]['company_name']);
        $this->assertArrayNotHasKey('parent_id', $departments[0]);

        $this->api()->getJson("/api/v1/companies/{$this->camelot->id}")->assertOk()->assertJsonPath('data.name', 'Camelot');
    }
    /** Owner, 2026-11-02: other systems send "Male"; it is stored as "male". */
    public function test_gender_is_accepted_in_any_capitalisation(): void
    {
        $this->api()->postJson('/api/v1/employees', ['name' => 'G', 'employee_code' => '31', 'gender' => 'Male'])
            ->assertCreated()->assertJsonPath('data.gender', 'male');
        $this->api()->patchJson('/api/v1/employees/31', ['gender' => ' FEMALE '])
            ->assertOk()->assertJsonPath('data.gender', 'female');
        $this->api()->putJson('/api/v1/employees/by-external-id/G-1', ['name' => 'G2', 'gender' => 'Male'])
            ->assertCreated()->assertJsonPath('data.gender', 'male');
        $this->api()->patchJson('/api/v1/employees/31', ['gender' => 'Other'])
            ->assertUnprocessable()->assertJsonValidationErrors('gender');
    }
}
