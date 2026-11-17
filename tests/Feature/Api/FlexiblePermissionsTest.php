<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CompanyAccess;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Owner, 2026-11-17: "keep all permissions, I can add or remove any permission to everyone, make it
 * flexible, don't hardcode permissions to any roles". Plus what a GEMS HR account needs: view and
 * take fingerprints only, on GEMS terminals only.
 */
class FlexiblePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Company $gems;

    private Company $camelotCo;

    private Device $gemsTerminal;

    private Device $camelot;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();
        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);
        Tenancy::set($this->tenant->id);
        $this->admin = $this->user('admin@acme.test', 'tenant-admin');
        $this->gems = Company::create(['name' => 'GEMS']);
        $this->camelotCo = Company::create(['name' => 'Camelot']);
        $this->gemsTerminal = Device::create(['name' => 'Gems WorkShop', 'ip_address' => '192.0.2.64', 'port' => 4371, 'protocol' => Device::PROTOCOL_BINARY, 'is_active' => true, 'company_id' => $this->gems->id]);
        $this->camelot = Device::create(['name' => 'Camelot', 'ip_address' => '192.0.2.60', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'is_active' => true, 'company_id' => $this->camelotCo->id]);
        Tenancy::clear();
    }

    private function user(string $email, string $role): User
    {
        $u = User::withoutGlobalScopes()->create([
            'name' => $email, 'email' => $email, 'password' => 'secret-pass-1',
            'tenant_id' => $this->tenant->id, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $u->syncRoles([$role]);

        return $u;
    }

    private function as(User $u)
    {
        $this->app['auth']->forgetGuards();
        CompanyAccess::forget();

        return $this->actingAs($u->fresh(), 'sanctum');
    }

    public function test_the_administrator_holds_a_permission_its_level_was_never_given(): void
    {
        Tenancy::set($this->tenant->id);
        $role = Role::findByName('tenant-admin', 'web');
        $role->revokePermissionTo('employees.fingerprint');

        $this->assertTrue($this->admin->fresh()->can('employees.fingerprint'));
        $this->assertContains('employees.fingerprint', $this->admin->fresh()->effectivePermissionNames()->all());
    }

    public function test_reseeding_never_resets_a_level_edited_on_screen(): void
    {
        Tenancy::set($this->tenant->id);
        $hr = Role::findByName('hr-manager', 'web');
        $hr->syncPermissions(['employees.view', 'employees.fingerprint']);

        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);

        $this->assertSame(['employees.fingerprint', 'employees.view'], Role::findByName('hr-manager', 'web')->permissions->pluck('name')->sort()->values()->all());
    }

    public function test_one_user_can_be_given_and_have_taken_away_permissions_on_top_of_their_level(): void
    {
        Tenancy::set($this->tenant->id);
        $hr = $this->user('hr@acme.test', 'hr-manager'); // employees.view, attendance.view, …
        Tenancy::clear();

        $this->as($this->admin)->putJson("/api/users/{$hr->id}/permissions", [
            'granted' => ['employees.fingerprint'],
            'removed' => ['attendance.view'],
        ])->assertOk()
            ->assertJsonPath('data.granted_permissions', ['employees.fingerprint'])
            ->assertJsonPath('data.removed_permissions', ['attendance.view']);

        $fresh = $hr->fresh();
        Tenancy::set($this->tenant->id);
        $this->assertTrue($fresh->can('employees.fingerprint'));
        $this->assertFalse($fresh->can('attendance.view'));
        $this->assertTrue($fresh->can('employees.view'));
        // Nobody else on the level changed.
        $other = $this->user('hr2@acme.test', 'hr-manager');
        $this->assertTrue($other->can('attendance.view'));
        $this->assertFalse($other->can('employees.fingerprint'));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'user.permissions_changed')->where('auditable_id', $hr->id)->exists());
    }

    public function test_not_for_yourself_not_for_an_administrator_and_not_beyond_what_you_hold(): void
    {
        Tenancy::set($this->tenant->id);
        $otherAdmin = $this->user('admin2@acme.test', 'tenant-admin');
        $manager = $this->user('mgr@acme.test', 'hr-manager');
        $manager->givePermissionTo(['users.view', 'users.update']);
        $target = $this->user('t@acme.test', 'employee');
        Tenancy::clear();

        $this->as($this->admin)->putJson("/api/users/{$this->admin->id}/permissions", ['granted' => [], 'removed' => []])->assertStatus(422);
        $this->as($this->admin)->putJson("/api/users/{$otherAdmin->id}/permissions", ['granted' => [], 'removed' => ['users.view']])->assertStatus(422);
        $this->as($manager)->putJson("/api/users/{$target->id}/permissions", ['granted' => ['devices.delete'], 'removed' => []])->assertForbidden();
    }

    public function test_a_fingerprint_only_user_can_take_a_finger_but_not_delete_one(): void
    {
        Tenancy::set($this->tenant->id);
        $hr = $this->user('gemshr@acme.test', 'hr-manager');
        $hr->givePermissionTo('employees.fingerprint');
        $e = Employee::create(['name' => 'Aslam', 'employee_code' => '860788580', 'company_id' => $this->gems->id, 'is_active' => true]);
        Tenancy::clear();

        $this->app->instance(\App\Contracts\BiometricDeviceDriver::class, new \Tests\Feature\Devices\FakeBiometricDriver);

        // Allowed through the policy (what the terminal answers is its own business: not a 403).
        $status = $this->as($hr)->getJson("/api/employees/{$e->id}/devices/{$this->gemsTerminal->id}/enrollment")->status();
        $this->assertNotSame(403, $status);
        // Destructive actions stay on employees.push.
        $this->as($hr)->deleteJson("/api/employees/{$e->id}/devices/{$this->gemsTerminal->id}/fingerprints/6")->assertForbidden();
        $this->as($hr)->postJson("/api/employees/{$e->id}/devices/{$this->gemsTerminal->id}/push")->assertForbidden();
    }

    public function test_a_user_limited_to_gems_sees_and_uses_only_gems_terminals(): void
    {
        Tenancy::set($this->tenant->id);
        $hr = $this->user('gemshr2@acme.test', 'hr-manager');
        $hr->givePermissionTo('employees.fingerprint');
        DB::table('user_companies')->insert(['user_id' => $hr->id, 'company_id' => $this->gems->id]);
        $e = Employee::create(['name' => 'Aslam', 'employee_code' => '860788580', 'company_id' => $this->gems->id, 'is_active' => true]);
        Tenancy::clear();

        $names = collect($this->as($hr)->getJson('/api/devices/options')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['Gems WorkShop'], $names);
        // Another company's terminal does not exist for them.
        $this->as($hr)->getJson("/api/employees/{$e->id}/devices/{$this->camelot->id}/enrollment")->assertNotFound();
        // An unlimited user still sees every terminal.
        $all = collect($this->as($this->admin)->getJson('/api/devices/options')->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Camelot', 'Gems WorkShop'], $all);
    }
}
