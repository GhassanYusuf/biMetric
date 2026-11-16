<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CompanyAccess;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Move several ticked employees to a company and, optionally, a department in it (owner,
 * 2026-11-16): between departments of one company, or to another company with or without a
 * department. POST /api/employees/bulk-move.
 */
class EmployeeBulkMoveTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Company $gems;

    private Company $p7;

    private Department $workshop;

    private Department $office;

    private Department $p7Sales;

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
        $this->gems = Company::create(['name' => 'GEMS']);
        $this->p7 = Company::create(['name' => 'PROMOSEVEN']);
        $this->workshop = Department::create(['company_id' => $this->gems->id, 'name' => 'Workshop']);
        $this->office = Department::create(['company_id' => $this->gems->id, 'name' => 'Office']);
        $this->p7Sales = Department::create(['company_id' => $this->p7->id, 'name' => 'Sales']);
        Tenancy::clear();
    }

    private function person(string $code, Company $company, ?Department $department = null): Employee
    {
        Tenancy::set($this->tenant->id);
        $e = Employee::create(['name' => "Ahmed {$code}", 'employee_code' => $code, 'is_active' => true,
            'company_id' => $company->id, 'department_id' => $department?->id]);
        Tenancy::clear();

        return $e;
    }

    private function move(array $ids, int $companyId, ?int $departmentId = null, ?User $as = null)
    {
        $token = ($as ?? $this->admin)->createToken('test', ['*'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        CompanyAccess::forget();

        return $this->withToken($token)->postJson('/api/employees/bulk-move', array_filter([
            'ids' => $ids, 'company_id' => $companyId, 'department_id' => $departmentId,
        ], fn ($v) => $v !== null));
    }

    private function fresh(Employee $e): Employee
    {
        return Employee::withoutGlobalScopes()->find($e->id);
    }

    public function test_between_departments_of_one_company(): void
    {
        $a = $this->person('1', $this->gems, $this->workshop);
        $b = $this->person('2', $this->gems, $this->workshop);

        $this->move([$a->id, $b->id], $this->gems->id, $this->office->id)->assertOk()
            ->assertJsonPath('data.moved', 2)->assertJsonPath('data.to', 'GEMS › Office');

        foreach ([$a, $b] as $e) {
            $this->assertSame($this->office->id, (int) $this->fresh($e)->department_id);
            $this->assertSame('Office', $this->fresh($e)->department); // the free-text column follows
        }
    }

    public function test_to_another_company_and_one_of_its_departments(): void
    {
        $a = $this->person('3', $this->gems, $this->workshop);

        $this->move([$a->id], $this->p7->id, $this->p7Sales->id)->assertOk()->assertJsonPath('data.moved', 1);

        $this->assertSame($this->p7->id, (int) $this->fresh($a)->company_id);
        $this->assertSame($this->p7Sales->id, (int) $this->fresh($a)->department_id);
    }

    public function test_to_another_company_with_no_department(): void
    {
        $a = $this->person('4', $this->gems, $this->workshop);

        $this->move([$a->id], $this->p7->id)->assertOk()->assertJsonPath('data.to', 'PROMOSEVEN');

        $this->assertSame($this->p7->id, (int) $this->fresh($a)->company_id);
        $this->assertNull($this->fresh($a)->department_id);
    }

    public function test_a_department_of_another_company_is_refused(): void
    {
        $a = $this->person('5', $this->gems, $this->workshop);

        $this->move([$a->id], $this->gems->id, $this->p7Sales->id)->assertUnprocessable();

        $this->assertSame($this->workshop->id, (int) $this->fresh($a)->department_id);
    }

    public function test_those_already_there_and_unknown_ids_are_reported(): void
    {
        $a = $this->person('6', $this->gems, $this->office);
        $b = $this->person('7', $this->gems, $this->workshop);

        $res = $this->move([$a->id, $b->id, 999999], $this->gems->id, $this->office->id)->assertOk()
            ->assertJsonPath('data.moved', 1)->assertJsonPath('data.already', 1);
        $this->assertSame(['#999999 (not found)'], $res->json('data.refused'));
    }

    public function test_every_move_is_audited(): void
    {
        $a = $this->person('8', $this->gems, $this->workshop);

        $this->move([$a->id], $this->gems->id, $this->office->id)->assertOk();

        $this->assertTrue(DB::table('audit_logs')->where('auditable_id', $a->id)->where('action', 'employee.updated')->exists());
    }

    public function test_a_user_limited_to_one_company_cannot_move_people_into_another(): void
    {
        $a = $this->person('9', $this->gems, $this->workshop);
        DB::table('user_companies')->insert(['user_id' => $this->admin->id, 'company_id' => $this->gems->id]);

        $this->move([$a->id], $this->p7->id)->assertUnprocessable();

        $this->assertSame($this->gems->id, (int) $this->fresh($a)->company_id);
    }

    public function test_it_needs_the_update_permission(): void
    {
        Tenancy::set($this->tenant->id);
        $staff = User::withoutGlobalScopes()->create([
            'name' => 'Staff', 'email' => 'staff@acme.test', 'password' => 'secret-pass-1',
            'tenant_id' => $this->tenant->id, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        Tenancy::clear();
        $a = $this->person('10', $this->gems, $this->workshop);

        // A user without employees.update: refused per person (or by the route), never moved.
        $res = $this->move([$a->id], $this->gems->id, $this->office->id, $staff);
        $this->assertTrue($res->status() !== 200 || (int) $res->json('data.moved') === 0);
        $this->assertSame($this->workshop->id, (int) $this->fresh($a)->department_id);
    }
}
