<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Users screen and its permission levels (owner, 2026-10-19).
 *
 * What matters is what must NOT happen: a company locking itself out (the last
 * Administrator removed, somebody deactivating themselves), a user climbing above
 * their own rights by giving out a level or permission they lack, and a deactivated
 * or reset user staying signed in.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-password';

    private Tenant $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $this->acme = Tenant::where('slug', 'acme')->firstOrFail();
        $this->admin = $this->makeUser('admin@acme.test', 'tenant-admin');
        $this->makeUser('operator@acme.test', 'operator');
    }

    private function makeUser(string $email, string $role, ?Tenant $tenant = null): User
    {
        $tenant ??= $this->acme;
        Tenancy::set($tenant->id);

        $user = User::withoutGlobalScopes()->create([
            'name' => ucfirst(strtok($email, '@')),
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

    private function token(string $email, string $password = self::PASSWORD): ?string
    {
        $token = $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])->json('data.token');
        $this->app['auth']->forgetGuards();

        return $token;
    }

    private function as(string $email)
    {
        return $this->withToken($this->token($email));
    }

    /** A custom level, made by the admin over the API. */
    private function makeLevel(string $name, array $permissions): int
    {
        $res = $this->as('admin@acme.test')->postJson('/api/roles', ['name' => $name, 'permissions' => $permissions]);
        $res->assertCreated();

        return (int) $res->json('data.id');
    }

    // --- Users --------------------------------------------------------------

    public function test_an_admin_sees_the_companys_users_and_an_operator_does_not(): void
    {
        $res = $this->as('admin@acme.test')->getJson('/api/users')->assertOk();

        $emails = collect($res->json('data'))->pluck('email');
        $this->assertEqualsCanonicalizing(['admin@acme.test', 'operator@acme.test'], $emails->all());
        $this->assertSame('Administrator', collect($res->json('data'))->firstWhere('email', 'admin@acme.test')['role_label']);

        $this->as('operator@acme.test')->getJson('/api/users')->assertStatus(403);
    }

    public function test_an_added_user_signs_in_with_the_levels_permissions(): void
    {
        $this->as('admin@acme.test')->postJson('/api/users', [
            'name' => 'Huda HR',
            'email' => 'huda@acme.test',
            'password' => 'starting-pass-1',
            'role' => 'hr-manager',
        ])->assertCreated()->assertJsonPath('data.role', 'hr-manager');

        $me = $this->withToken($this->token('huda@acme.test', 'starting-pass-1'))->getJson('/api/auth/me')->assertOk();
        $this->assertContains('reports.view', $me->json('data.permissions'));
        $this->assertNotContains('users.view', $me->json('data.permissions'));

        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'user.created')->exists());
        $this->assertStringNotContainsString('starting-pass-1', json_encode(AuditLog::withoutGlobalScopes()->get()->toArray()));
    }

    public function test_adding_a_user_checks_email_password_and_level(): void
    {
        $this->as('admin@acme.test')->postJson('/api/users', [
            'name' => 'Dup', 'email' => 'operator@acme.test', 'password' => 'short', 'role' => 'no-such-level',
        ])->assertStatus(422)->assertJsonValidationErrors(['email', 'password', 'role']);
    }

    public function test_nobody_changes_their_own_level_or_deactivates_or_deletes_themselves(): void
    {
        $api = $this->as('admin@acme.test');

        $api->putJson("/api/users/{$this->admin->id}", ['role' => 'operator'])->assertStatus(422);
        $api->putJson("/api/users/{$this->admin->id}", ['is_active' => false])->assertStatus(422);
        $api->deleteJson("/api/users/{$this->admin->id}")->assertStatus(422);

        // Their own name is fine.
        $api->putJson("/api/users/{$this->admin->id}", ['name' => 'Renamed Admin'])->assertOk();
    }

    public function test_the_last_administrator_cannot_be_removed_and_levels_cannot_be_climbed(): void
    {
        // A user manager who is not an Administrator.
        $this->makeLevel('User manager', ['users.view', 'users.update']);
        $manager = $this->makeUser('manager@acme.test', 'User manager');

        $api = $this->as('manager@acme.test');
        $api->putJson("/api/users/{$this->admin->id}", ['is_active' => false])->assertStatus(409);
        $api->putJson("/api/users/{$this->admin->id}", ['role' => 'operator'])->assertStatus(403); // operator holds devices.* the manager lacks
        $api->putJson("/api/users/{$manager->id}", ['role' => 'tenant-admin'])->assertStatus(422);  // own level
        $operator = User::withoutGlobalScopes()->where('email', 'operator@acme.test')->first();
        $api->putJson("/api/users/{$operator->id}", ['role' => 'tenant-admin'])->assertStatus(403);

        // With a second Administrator, the first can be deactivated.
        $this->makeUser('admin2@acme.test', 'tenant-admin');
        $this->as('admin2@acme.test')->putJson("/api/users/{$this->admin->id}", ['is_active' => false])->assertOk();
    }

    public function test_deactivating_signs_the_person_out_and_stops_new_sign_ins(): void
    {
        $operator = User::withoutGlobalScopes()->where('email', 'operator@acme.test')->first();
        $token = $this->token('operator@acme.test');

        $this->as('admin@acme.test')->putJson("/api/users/{$operator->id}", ['is_active' => false])->assertOk();
        $this->assertSame(0, $operator->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
        $this->postJson('/api/auth/login', ['email' => 'operator@acme.test', 'password' => self::PASSWORD])->assertStatus(403);
    }

    public function test_a_password_reset_works_and_signs_the_person_out(): void
    {
        $operator = User::withoutGlobalScopes()->where('email', 'operator@acme.test')->first();
        $old = $this->token('operator@acme.test');

        $this->as('admin@acme.test')->putJson("/api/users/{$operator->id}/password", ['password' => 'brand-new-pass'])->assertOk();
        $this->assertSame(0, $operator->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($old)->getJson('/api/auth/me')->assertStatus(401);
        $this->assertNotNull($this->token('operator@acme.test', 'brand-new-pass'));
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'user.password_reset')->exists());
    }

    public function test_only_a_user_with_no_history_can_be_deleted(): void
    {
        $api = $this->as('admin@acme.test');
        $fresh = (int) $api->postJson('/api/users', ['name' => 'Typo', 'email' => 'typo@acme.test', 'password' => 'whatever-123', 'role' => 'employee'])->json('data.id');
        $api->deleteJson("/api/users/{$fresh}")->assertOk();
        $this->assertNull(User::withoutGlobalScopes()->find($fresh));

        // The admin's own changes above are in the audit trail, so a second admin
        // cannot delete them — only deactivate.
        $this->makeUser('admin2@acme.test', 'tenant-admin');
        $this->as('admin2@acme.test')->deleteJson("/api/users/{$this->admin->id}")->assertStatus(409);
    }

    public function test_another_companys_user_is_not_found(): void
    {
        $default = Tenant::where('slug', '!=', 'acme')->firstOrFail();
        $stranger = $this->makeUser('stranger@other.test', 'operator', $default);

        $this->as('admin@acme.test')->putJson("/api/users/{$stranger->id}", ['name' => 'Hacked'])->assertStatus(404);
        $this->as('admin@acme.test')->deleteJson("/api/users/{$stranger->id}")->assertStatus(404);
    }

    // --- Permission levels ---------------------------------------------------

    public function test_levels_list_the_four_built_in_ones_with_the_permission_groups(): void
    {
        $res = $this->as('admin@acme.test')->getJson('/api/roles')->assertOk();

        $this->assertSame(['tenant-admin', 'hr-manager', 'operator', 'employee'], collect($res->json('data'))->pluck('name')->all());
        $admin = collect($res->json('data'))->firstWhere('name', 'tenant-admin');
        $this->assertTrue($admin['locked']);
        $this->assertSame(1, $admin['users_count']);
        $this->assertNotEmpty($res->json('meta.groups'));

        $this->as('operator@acme.test')->getJson('/api/roles')->assertStatus(403);
    }

    public function test_a_custom_level_is_created_edited_and_deleted_once_unused(): void
    {
        $id = $this->makeLevel('Payroll viewer', ['reports.view', 'attendance.view']);
        $api = $this->as('admin@acme.test');

        $api->putJson("/api/roles/{$id}", ['name' => 'Payroll', 'permissions' => ['reports.view']])
            ->assertOk()->assertJsonPath('data.label', 'Payroll')->assertJsonPath('data.permissions', ['reports.view']);

        $user = (int) $api->postJson('/api/users', ['name' => 'Pay', 'email' => 'pay@acme.test', 'password' => 'payroll-123', 'role' => 'Payroll'])->json('data.id');
        $api->deleteJson("/api/roles/{$id}")->assertStatus(409);

        $api->putJson("/api/users/{$user}", ['role' => 'employee'])->assertOk();
        $api->deleteJson("/api/roles/{$id}")->assertOk();
    }

    public function test_the_administrator_is_locked_and_built_in_levels_keep_their_names(): void
    {
        $api = $this->as('admin@acme.test');
        $roles = collect($api->getJson('/api/roles')->json('data'))->keyBy('name');

        $api->putJson("/api/roles/{$roles['tenant-admin']['id']}", ['permissions' => []])->assertStatus(422);
        $api->putJson("/api/roles/{$roles['operator']['id']}", ['name' => 'Technician', 'permissions' => ['devices.view']])->assertStatus(422);
        $api->deleteJson("/api/roles/{$roles['operator']['id']}")->assertStatus(422);

        // Their permissions are editable.
        $api->putJson("/api/roles/{$roles['operator']['id']}", ['permissions' => ['devices.view', 'reports.view']])->assertOk();
        $me = $this->as('operator@acme.test')->getJson('/api/auth/me');
        $this->assertContains('reports.view', $me->json('data.permissions'));

        // A custom level cannot borrow a built-in name; platform permissions are refused.
        $api = $this->as('admin@acme.test');
        $api->postJson('/api/roles', ['name' => 'Administrator', 'permissions' => []])->assertStatus(422);
        $api->postJson('/api/roles', ['name' => 'Platform', 'permissions' => ['tenants.manage']])->assertStatus(422);
    }

    public function test_nobody_puts_into_a_level_a_permission_they_lack(): void
    {
        $this->makeLevel('Level editor', ['roles.view', 'roles.manage', 'reports.view']);
        $this->makeUser('editor@acme.test', 'Level editor');

        $api = $this->as('editor@acme.test');
        $api->postJson('/api/roles', ['name' => 'Sneaky', 'permissions' => ['users.delete']])->assertStatus(403);
        $api->postJson('/api/roles', ['name' => 'Fine', 'permissions' => ['reports.view']])->assertCreated();

        // Removing what they lack from an existing level is allowed.
        $hr = Role::where('name', 'hr-manager')->where('team_id', $this->acme->id)->firstOrFail();
        $api->putJson("/api/roles/{$hr->id}", ['permissions' => ['reports.view']])->assertOk();
    }
}
