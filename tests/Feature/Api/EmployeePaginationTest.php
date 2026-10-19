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
 * The roster is only fully browsable because the list endpoint pages: the real
 * company has 362 employees and the SPA asks for 100 at a time, so the pager it
 * renders is driven entirely by the `meta.pagination` block in this response.
 * These tests pin that block, the default page size, and the bound on an
 * over-large request.
 */
class EmployeePaginationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-password';

    private string $email = 'roster@acme.test';

    /** Employees the seeder already put on this tenant. */
    private int $baseline;

    /** Keeps generated employee codes unique across several calls. */
    private int $nextCode = 90000;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $acme = Tenant::where('slug', 'acme')->firstOrFail();
        Tenancy::set($acme->id);
        $user = User::create([
            'name' => 'Roster Admin',
            'email' => $this->email,
            'password' => Hash::make(self::PASSWORD),
            'tenant_id' => $acme->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->syncRoles(['tenant-admin']);

        // Measured, not assumed: the seeder creates demo employees, and this
        // suite asserts on totals that must include them.
        $this->baseline = Employee::withoutGlobalScopes()->count();

        Tenancy::clear();
    }

    /**
     * Logs in over the real endpoint, then forgets the guards. The test process
     * reuses one app instance, so a cached RequestGuard would otherwise serve
     * the first authenticated user to every later request.
     */
    private function api(string $query = ''): TestResponse
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => $this->email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/employees'.$query);
    }

    /** Creates $count employees, named so ordering by name is predictable. */
    private function makeEmployees(int $count, string $prefix = 'Roster'): void
    {
        Tenancy::set(Tenant::where('slug', 'acme')->value('id'));

        foreach (range(1, $count) as $n) {
            Employee::create([
                'name' => sprintf('%s %03d', $prefix, $n),
                'employee_code' => (string) ++$this->nextCode,
                'is_active' => true,
            ]);
        }

        Tenancy::clear();
    }

    public function test_the_default_page_size_is_twenty_five(): void
    {
        $this->makeEmployees(30);

        $response = $this->api()->assertOk();

        $this->assertCount(25, $response->json('data'));
        $this->assertSame(25, $response->json('meta.pagination.per_page'));
        $this->assertSame($this->baseline + 30, $response->json('meta.pagination.total'));
    }

    public function test_meta_describes_every_page_so_the_pager_can_be_rendered(): void
    {
        $this->makeEmployees(362);

        $meta = $this->api('?per_page=100&page=2')->assertOk()->json('meta.pagination');

        // Exactly the keys the SPA reads out of meta.pagination. A renamed or
        // dropped key would leave the pager showing "Page 1 of 1" over a
        // silently truncated list, so pin the whole set.
        $this->assertSame(
            ['current_page', 'per_page', 'total', 'last_page', 'has_more'],
            array_keys($meta),
        );

        $total = $this->baseline + 362;
        $this->assertSame(2, $meta['current_page']);
        $this->assertSame($total, $meta['total']);
        $this->assertSame((int) ceil($total / 100), $meta['last_page']);
        $this->assertSame(100, $meta['per_page']);
        $this->assertTrue($meta['has_more']);
    }

    public function test_every_employee_is_reachable_across_the_pages(): void
    {
        $this->makeEmployees(362);

        $total = $this->baseline + 362;
        $lastPage = (int) ceil($total / 100);

        $seen = [];
        foreach (range(1, $lastPage) as $page) {
            $data = $this->api("?per_page=100&page={$page}")->assertOk()->json('data');

            $expected = $page === $lastPage ? $total % 100 : 100;
            $this->assertCount($expected, $data, "page {$page} should hold {$expected} rows");
            $seen = array_merge($seen, array_column($data, 'id'));
        }

        // No duplicates and nothing skipped: every employee must be reachable,
        // which is the whole reason the pager exists.
        $this->assertCount($total, array_unique($seen));
        $this->assertFalse($this->api("?per_page=100&page={$lastPage}")->json('meta.pagination.has_more'));
    }

    public function test_an_over_large_page_request_is_capped(): void
    {
        $this->makeEmployees(210);

        $response = $this->api('?per_page=100000')->assertOk();

        // Without the cap this would ask for the whole roster, relations and
        // all, in one response.
        $this->assertSame(200, $response->json('meta.pagination.per_page'));
        $this->assertSame($this->baseline + 210, $response->json('meta.pagination.total'));
    }

    public function test_a_nonsense_page_size_clamps_instead_of_erroring(): void
    {
        $this->makeEmployees(5);

        foreach (['0', '-10', 'abc'] as $perPage) {
            $response = $this->api("?per_page={$perPage}")->assertOk();

            $this->assertSame(1, $response->json('meta.pagination.per_page'), "per_page={$perPage} should clamp to 1");
            $this->assertCount(1, $response->json('data'), "per_page={$perPage} should return one row");
        }
    }

    public function test_a_page_past_the_end_is_empty_rather_than_an_error(): void
    {
        $this->makeEmployees(30);

        $this->api('?per_page=25&page=99')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_search_counts_and_pages_the_matches_not_the_whole_roster(): void
    {
        // Prefixes the seeder's demo names cannot collide with, so the totals
        // below are exact rather than baseline-relative.
        $this->makeEmployees(40, 'Keeper');
        $this->makeEmployees(60, 'Other');

        // The count follows the filter, so the pager reads "Page 1 of 2 · 40"
        // rather than offering pages of people who do not match.
        $meta = $this->api('?search=Keeper&per_page=25')->assertOk()->json('meta.pagination');
        $this->assertSame(40, $meta['total']);
        $this->assertSame(2, $meta['last_page']);

        $second = $this->api('?search=Keeper&per_page=25&page=2')->assertOk();
        $this->assertCount(15, $second->json('data'));
        foreach ($second->json('data') as $row) {
            $this->assertStringStartsWith('Keeper', $row['name']);
        }
    }

    public function test_search_matching_nothing_is_an_empty_page_not_an_error(): void
    {
        $this->makeEmployees(10);

        // This is the response that used to blank the roster: the SPA swapped
        // the whole list for a skeleton whenever rows came back empty.
        $response = $this->api('?search=nobodymatchesthis')->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.pagination.total'));
        $this->assertSame(1, $response->json('meta.pagination.last_page'));
        $this->assertFalse($response->json('meta.pagination.has_more'));
    }

    public function test_filtering_still_narrows_the_paged_list(): void
    {
        Tenancy::set(Tenant::where('slug', 'acme')->value('id'));
        $active = Employee::create([
            'name' => 'Stays Active', 'employee_code' => (string) ++$this->nextCode, 'is_active' => true,
        ]);
        $inactive = Employee::create([
            'name' => 'Goes Inactive', 'employee_code' => (string) ++$this->nextCode, 'is_active' => false,
        ]);
        Tenancy::clear();

        $ids = array_column($this->api('?is_active=1&per_page=200')->assertOk()->json('data'), 'id');

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    /**
     * The ZKAccess import fills BOTH the free-text `department` column and the
     * `department_id` relation key. Eloquent exposes the attribute before the
     * relation, so `$this->department` in the resource is a string — the roster
     * 500'd reading `?->name` off it. The relation name must still come through.
     */
    public function test_a_filled_department_column_cannot_shadow_the_relation(): void
    {
        Tenancy::set(Tenant::where('slug', 'acme')->value('id'));

        $dept = Department::create([
            'tenant_id' => Tenant::where('slug', 'acme')->value('id'),
            'name' => 'Logistics',
        ]);
        $employee = Employee::create([
            'name' => 'Dept Lens', 'employee_code' => (string) ++$this->nextCode, 'is_active' => true,
            'department' => 'LOGISTICS',
            'department_id' => $dept->id,
        ]);
        Tenancy::clear();

        $row = collect($this->api('?per_page=200&search=Dept%20Lens')->assertOk()->json('data'))
            ->firstWhere('id', $employee->id);

        $this->assertNotNull($row, 'the department-bearing employee must appear in the roster');
        $this->assertSame('LOGISTICS', $row['department']);
        $this->assertSame('Logistics', $row['department_name']);
    }

    public function test_tenant_scope_is_untouched_by_paging(): void
    {
        $other = Tenant::where('slug', 'default')->firstOrFail();
        Tenancy::set($other->id);
        Employee::create([
            'name' => 'Keeper Foreign', 'employee_code' => (string) ++$this->nextCode, 'is_active' => true,
        ]);
        Tenancy::clear();

        // A search that would match the other tenant's employee still must not
        // leak it, and must not inflate the total the pager is built from.
        $meta = $this->api('?search=Keeper')->assertOk()->json('meta.pagination');
        $this->assertSame(0, $meta['total']);
    }
}
