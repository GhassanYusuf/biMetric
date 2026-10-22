<?php

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * End-to-end coverage of the multi-tenant, permission-gated API. Runs against
 * an in-memory SQLite database (see phpunit.xml), fully isolated from the real
 * data.
 *
 * The DatabaseSeeder builds the tenants, roles and domain data but deliberately
 * creates only ONE login (the owner). These tests therefore mint their own
 * role-scoped users on the acme tenant, so coverage of the role/permission
 * matrix never depends on demo accounts existing in the seeder.
 */
class ApiFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-password';

    protected function setUp(): void
    {
        parent::setUp();
        // The Tenancy context is process-static; clear any value left by a
        // previous test BEFORE seeding, or a tenant-less row would be stamped
        // with a stale tenant_id and fail its FK.
        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $acme = Tenant::where('slug', 'acme')->firstOrFail();
        $this->makeUser('admin@acme.test', 'tenant-admin', $acme);
        $this->makeUser('hr@acme.test', 'hr-manager', $acme);
        $this->makeUser('operator@acme.test', 'operator', $acme);
    }

    /**
     * Creates a user on $tenant holding exactly $role. Role assignment is
     * team-scoped, so the tenant context must be set before syncRoles().
     */
    private function makeUser(string $email, string $role, Tenant $tenant): User
    {
        Tenancy::set($tenant->id);

        $user = User::withoutGlobalScopes()->create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'tenant_id' => $tenant->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->syncRoles([$role]);

        Tenancy::clear();

        return $user;
    }

    private function tokenFor(string $email): string
    {
        $token = $this->postJson('/api/auth/login', ['email' => $email, 'password' => self::PASSWORD])
            ->json('data.token');
        // The test process reuses one app instance, so Sanctum's RequestGuard
        // would cache the first authenticated user and ignore later tokens.
        // Flush guards so each identity switch resolves the correct user.
        $this->app['auth']->forgetGuards();

        return $token;
    }

    private function actingWithToken(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_login_returns_token_roles_and_permissions(): void
    {
        $res = $this->postJson('/api/auth/login', [
            'email' => 'admin@acme.test',
            'password' => self::PASSWORD,
        ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.roles.0', 'tenant-admin')
            ->assertJsonStructure(['success', 'message', 'data' => ['token', 'user' => ['permissions']], 'meta', 'errors']);

        $this->assertContains('employees.create', $res->json('data.user.permissions'));
    }

    public function test_bad_credentials_return_422_envelope(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@acme.test', 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_unauthenticated_request_is_401(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('success', false);
    }

    public function test_tenant_isolation_blocks_cross_tenant_access(): void
    {
        // An employee that belongs to the DEFAULT tenant...
        $defaultTenant = Tenant::where('slug', 'default')->first();
        Tenancy::set($defaultTenant->id);
        $foreign = Employee::create(['name' => 'Foreign', 'employee_code' => 'X1']);
        Tenancy::clear();

        // ...must be invisible to an acme admin (404, not 200).
        $token = $this->tokenFor('admin@acme.test');
        $this->withToken($token)->getJson("/api/employees/{$foreign->id}")->assertStatus(404);
    }

    public function test_permission_denied_for_operator_deleting_employee(): void
    {
        $adminToken = $this->tokenFor('admin@acme.test');
        $created = $this->actingWithToken($adminToken)
            ->postJson('/api/employees', ['name' => 'Temp', 'employee_code' => '5555'])
            ->assertStatus(201)
            ->json('data.id');

        $operatorToken = $this->tokenFor('operator@acme.test');
        $this->actingWithToken($operatorToken)->deleteJson("/api/employees/{$created}")->assertStatus(403);
    }

    /** Owner, 2026-10-21: delete by Personnel ID (employee_code), not the row id. */
    public function test_an_employee_can_be_deleted_by_personnel_id(): void
    {
        $token = $this->tokenFor('admin@acme.test');
        $id = $this->actingWithToken($token)
            ->postJson('/api/employees', ['name' => 'Code Delete', 'employee_code' => '99901'])
            ->assertStatus(201)
            ->json('data.id');

        $this->actingWithToken($token)->deleteJson('/api/employees/by-code/99901')
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.employee_code', '99901')
            ->assertJsonPath('data.name', 'Code Delete')
            // On no terminal, so nothing to queue: deleted at once.
            ->assertJsonPath('meta.deleted', true)
            ->assertJsonPath('meta.device_commands', []);

        $this->actingWithToken($token)->getJson("/api/employees/{$id}")->assertStatus(404);
        // Gone, so a second delete says so rather than succeeding again.
        $this->actingWithToken($token)->deleteJson('/api/employees/by-code/99901')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    /** Owner, 2026-10-22: the signed-in API finds an employee by Personnel ID too. */
    public function test_an_employee_is_found_by_personnel_id(): void
    {
        $token = $this->tokenFor('admin@acme.test');
        $id = $this->actingWithToken($token)->postJson('/api/employees', ['name' => 'Get Me', 'employee_code' => '99911'])->assertStatus(201)->json('data.id');

        $this->actingWithToken($token)->getJson('/api/employees/by-code/99911')
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.name', 'Get Me');
        $this->actingWithToken($token)->getJson('/api/employees/by-code/099911')
            ->assertStatus(404)->assertJsonPath('success', false);
    }

    /** The code is text: "0099902" must not find "99902", nor the other way round. */
    public function test_delete_by_personnel_id_matches_the_code_exactly(): void
    {
        $token = $this->tokenFor('admin@acme.test');
        $this->actingWithToken($token)->postJson('/api/employees', ['name' => 'Short', 'employee_code' => '99902'])->assertStatus(201);

        $this->actingWithToken($token)->deleteJson('/api/employees/by-code/0099902')->assertStatus(404);
        $this->actingWithToken($token)->getJson('/api/employees?search=99902')->assertJsonPath('meta.pagination.total', 1);
    }

    /** Another company's code is not found, and the delete permission still applies. */
    public function test_delete_by_personnel_id_respects_tenant_and_permission(): void
    {
        $defaultTenant = Tenant::where('slug', 'default')->first();
        Tenancy::set($defaultTenant->id);
        Employee::create(['name' => 'Foreign', 'employee_code' => '77001']);
        Tenancy::clear();

        $admin = $this->tokenFor('admin@acme.test');
        $this->actingWithToken($admin)->deleteJson('/api/employees/by-code/77001')->assertStatus(404);
        Tenancy::set($defaultTenant->id);
        $this->assertTrue(Employee::where('employee_code', '77001')->exists());
        Tenancy::clear();

        $this->actingWithToken($admin)->postJson('/api/employees', ['name' => 'Kept', 'employee_code' => '77002'])->assertStatus(201);
        $operator = $this->tokenFor('operator@acme.test');
        $this->actingWithToken($operator)->deleteJson('/api/employees/by-code/77002')->assertStatus(403);
    }

    public function test_employee_crud_and_tenant_scoped_uniqueness(): void
    {
        $token = $this->tokenFor('admin@acme.test');

        $this->withToken($token)->postJson('/api/employees', ['name' => 'Uniq', 'employee_code' => '4242'])
            ->assertStatus(201);

        // Same code again within the tenant → 422.
        $this->withToken($token)->postJson('/api/employees', ['name' => 'Dup', 'employee_code' => '4242'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['employee_code']]);
    }

    public function test_waiver_creates_persisted_notification_for_hr(): void
    {
        $hrToken = $this->tokenFor('hr@acme.test');
        $employee = Employee::where('employee_code', '1002')->first();

        $this->withToken($hrToken)
            ->postJson("/api/employees/{$employee->id}/waivers", ['date' => '2026-07-20', 'reason' => 'Leave'])
            ->assertOk()
            ->assertJsonPath('data.waived', true);

        // HR should now have exactly one unread notification.
        $this->withToken($hrToken)->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
    }
}
