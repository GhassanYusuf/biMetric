<?php

namespace Tests\Feature\Api;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Company › Department tree on the Employees page (2026-10-11). Selecting a node
 * lists that node and everything beneath it, and "Unassigned" (department_id=none)
 * lists the people no node shows. The tree's own count for that node comes from the
 * department list's meta.
 */
class DepartmentTreeFilterTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-password';

    private string $email = 'tree@acme.test';

    private Department $company;

    private Department $finance;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $acme = Tenant::where('slug', 'acme')->firstOrFail();
        Tenancy::set($acme->id);
        User::create([
            'name' => 'Tree Admin',
            'email' => $this->email,
            'password' => Hash::make(self::PASSWORD),
            'tenant_id' => $acme->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ])->syncRoles(['tenant-admin']);

        $this->company = Department::create(['name' => 'Tree Co']);
        $this->finance = Department::create(['name' => 'Finance', 'parent_id' => $this->company->id]);

        Employee::create(['name' => 'Tree Head', 'employee_code' => 'T-1', 'department_id' => $this->company->id, 'is_active' => true]);
        Employee::create(['name' => 'Tree Clerk', 'employee_code' => 'T-2', 'department_id' => $this->finance->id, 'is_active' => true]);
        Employee::create(['name' => 'Tree Nobody', 'employee_code' => 'T-3', 'is_active' => true]);
        Tenancy::clear();
    }

    private function api(string $uri): TestResponse
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => $this->email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson($uri);
    }

    private function codes(TestResponse $response): array
    {
        $codes = collect($response->assertOk()->json('data'))->pluck('employee_code')->sort()->values()->all();

        return array_values(array_filter($codes, fn ($c) => str_starts_with($c, 'T-')));
    }

    public function test_a_company_lists_its_departments_people_too(): void
    {
        $this->assertSame(['T-1', 'T-2'], $this->codes($this->api('/api/employees?per_page=100&department_id='.$this->company->id)));
    }

    public function test_a_department_lists_only_itself(): void
    {
        $this->assertSame(['T-2'], $this->codes($this->api('/api/employees?per_page=100&department_id='.$this->finance->id)));
    }

    public function test_unassigned_lists_people_with_no_department(): void
    {
        $response = $this->api('/api/employees?per_page=100&department_id=none');

        $this->assertSame(['T-3'], $this->codes($response));
        $this->assertSame([], collect($response->json('data'))->whereNotNull('department_id')->all());
    }

    public function test_the_department_list_carries_parents_and_the_unassigned_count(): void
    {
        $response = $this->api('/api/departments')->assertOk();

        $finance = collect($response->json('data'))->firstWhere('id', $this->finance->id);
        $this->assertSame($this->company->id, (int) $finance['parent_id']);
        $this->assertSame(
            Employee::withoutGlobalScopes()->where('tenant_id', Tenant::where('slug', 'acme')->value('id'))->whereNull('department_id')->count(),
            $response->json('meta.unassigned_count'),
        );
    }
}
