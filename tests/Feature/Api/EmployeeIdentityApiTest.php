<?php

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Filtering the roster by enrollment, and the national-id removal of 2026-09-27.
 */
class EmployeeIdentityApiTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-password';

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();

        // The tenant must exist BEFORE the seeder runs: roles are created per tenant
        // (team-scoped assignment), so seeding first leaves this tenant with none.
        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);

        $this->seed(RolesAndPermissionsSeeder::class);
        // Permissions are global; the four ROLES are created per tenant, because role
        // assignment is team-scoped.
        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);
        Tenancy::clear();

        Tenancy::set($this->tenant->id);
        $user = User::withoutGlobalScopes()->create([
            'name' => 'Admin',
            'email' => 'admin@acme.test',
            'password' => Hash::make(self::PASSWORD),
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->syncRoles(['tenant-admin']);
        Tenancy::clear();

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'admin@acme.test',
            'password' => self::PASSWORD,
        ])->json('data.token');

        $this->app['auth']->forgetGuards();
    }

    public function test_the_roster_can_be_filtered_to_people_on_no_terminal(): void
    {
        $this->employee('Nobody Here', 'E-1');

        $rows = $this->get('/api/employees?unenrolled=1')->assertOk()->json('data');

        // Nobody is mapped to a device, so everyone qualifies — the point of the filter
        // is that "cannot clock in anywhere" is answerable at all.
        $this->assertCount(1, $rows);
        $this->assertSame('Nobody Here', $rows[0]['name']);
    }

    public function test_the_national_id_endpoints_are_gone(): void
    {
        // National ids were removed from the system on 2026-09-27.
        $employee = $this->employee('Ali Jaafar', 'E-1');

        $this->put("/api/employees/{$employee->id}/national-id", ['national_id' => '830108246'])
            ->assertNotFound();
        $this->post('/api/employees/lookup', ['national_id' => '830108246'])
            ->assertStatus(405);

        $row = $this->get('/api/employees')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('has_national_id', $row);
        $this->assertArrayNotHasKey('national_id_masked', $row);
    }

    private function employee(string $name, string $code): Employee
    {
        Tenancy::set($this->tenant->id);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'employee_code' => $code,
            'is_active' => true,
        ]);
        Tenancy::clear();

        return $employee;
    }

    /** Every request in this class is authenticated as the tenant admin. */
    protected function withAuth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function get($uri, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson($uri, $headers + $this->withAuth());
    }

    public function put($uri, array $data = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->putJson($uri, $data, $headers + $this->withAuth());
    }

    public function post($uri, array $data = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($uri, $data, $headers + $this->withAuth());
    }
}
