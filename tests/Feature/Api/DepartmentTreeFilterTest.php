<?php

namespace Tests\Feature\Api;

use App\Models\Company;
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

    private Company $company;

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

        $this->company = Company::create(['name' => 'Tree Co']);
        $this->finance = Department::create(['name' => 'Finance', 'company_id' => $this->company->id]);

        Employee::create(['name' => 'Tree Head', 'employee_code' => 'T-1', 'company_id' => $this->company->id, 'is_active' => true]);
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
        $this->assertSame(['T-1', 'T-2'], $this->codes($this->api('/api/employees?per_page=100&company_id='.$this->company->id)));
    }

    public function test_a_department_lists_only_itself(): void
    {
        $this->assertSame(['T-2'], $this->codes($this->api('/api/employees?per_page=100&department_id='.$this->finance->id)));
    }

    public function test_unassigned_lists_people_in_no_company(): void
    {
        $response = $this->api('/api/employees?per_page=100&company_id=none');

        $this->assertSame(['T-3'], $this->codes($response));
        $this->assertSame([], collect($response->json('data'))->whereNotNull('company_id')->all());
    }

    /**
     * Owner, 2026-11-03: "PROMOSEVEN shows 117 but the list says 111". The list hides the
     * resigned by default, so the tree's current_count must say what the list will show.
     */
    public function test_the_current_count_is_what_the_list_shows_by_default(): void
    {
        Tenancy::set(Tenant::where('slug', 'acme')->value('id'));
        Employee::create(['name' => 'Tree Leaver', 'employee_code' => 'T-4', 'company_id' => $this->company->id, 'resigned_at' => '2026-10-01']);
        Employee::create(['name' => 'Tree Gone', 'employee_code' => 'T-5', 'resigned_at' => '2026-10-01']);
        Tenancy::clear();

        $tree = collect($this->api('/api/companies')->assertOk()->json('data'))->firstWhere('id', $this->company->id);
        $this->assertSame(3, $tree['employees_count']);
        $this->assertSame(2, $tree['current_count']);
        $listed = $this->codes($this->api('/api/employees?per_page=100&resigned=0&company_id='.$this->company->id));
        $this->assertCount($tree['current_count'], $listed);

        $finance = collect($this->api('/api/departments')->json('data'))->firstWhere('id', $this->finance->id);
        $this->assertSame(1, $finance['current_count']);

        $meta = $this->api('/api/companies')->json('meta');
        $this->assertSame($meta['unassigned_count'] - 1, $meta['unassigned_current_count']);
    }

    /** Since the companies table (2026-11-03): departments name their company, companies count the unassigned. */
    public function test_departments_carry_their_company_and_companies_the_unassigned_count(): void
    {
        $finance = collect($this->api('/api/departments')->assertOk()->json('data'))->firstWhere('id', $this->finance->id);
        $this->assertSame($this->company->id, $finance['company_id']);
        $this->assertSame('Tree Co', $finance['company_name']);

        $companies = $this->api('/api/companies')->assertOk();
        $tree = collect($companies->json('data'))->firstWhere('id', $this->company->id);
        $this->assertSame(2, $tree['employees_count'], 'everyone in the company, its departments included');
        $this->assertSame(1, $tree['departments_count']);
        $this->assertSame(
            Employee::withoutGlobalScopes()->where('tenant_id', Tenant::where('slug', 'acme')->value('id'))->whereNull('company_id')->count(),
            $companies->json('meta.unassigned_count'),
        );
    }
}
