<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CompanyAccess;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The Audit log and Error logs screens (owner, 2026-11-09). */
class AuditAndErrorLogsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();
        CompanyAccess::forget();
        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);
        $this->admin = $this->user('admin@acme.test', 'tenant-admin');

        Tenancy::set($this->tenant->id);
        $sara = Employee::create(['name' => 'Sara', 'employee_code' => '900711060', 'is_active' => true]);
        AuditLog::query()->delete();
        AuditLog::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->admin->id, 'action' => 'employee.deleted', 'auditable_type' => Employee::class, 'auditable_id' => $sara->id, 'changes' => ['name' => ['Sara', null]], 'ip_address' => '192.168.1.145']);
        AuditLog::create(['tenant_id' => $this->tenant->id, 'user_id' => null, 'action' => 'device.updated', 'changes' => ['last_seen_at' => ['a', 'b']]]);
        Tenancy::clear();
    }

    private function user(string $email, string $role, array $extra = []): User
    {
        Tenancy::set($this->tenant->id);
        $u = User::withoutGlobalScopes()->create(['name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'tenant_id' => $this->tenant->id, 'is_active' => true, 'email_verified_at' => now(), ...$extra]);
        $u->syncRoles([$role]);
        Tenancy::clear();

        return $u;
    }

    private function as(User $u)
    {
        CompanyAccess::forget();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    public function test_the_audit_log_shows_peoples_actions_by_default_with_names(): void
    {
        $res = $this->as($this->admin)->getJson('/api/audit-logs')->assertOk();
        $this->assertSame(['employee.deleted'], collect($res->json('data'))->pluck('action')->all());
        $res->assertJsonPath('data.0.subject_name', 'Sara (900711060)')
            ->assertJsonPath('data.0.actor', 'admin@acme.test');
        $this->assertContains('device.updated', $res->json('meta.actions'));

        $this->assertCount(2, $this->as($this->admin)->getJson('/api/audit-logs?who=all')->json('data'));
        $this->assertSame(['device.updated'], collect($this->as($this->admin)->getJson('/api/audit-logs?who=system')->json('data'))->pluck('action')->all());
        $this->assertCount(1, $this->as($this->admin)->getJson('/api/audit-logs?action=employee.&search=Sara')->json('data'));
        $this->assertCount(0, $this->as($this->admin)->getJson('/api/audit-logs?from=2000-01-01&to=2000-01-02')->json('data'));
    }

    public function test_audit_needs_its_permission_and_refuses_a_company_limited_user(): void
    {
        $this->as($this->user('op@acme.test', 'operator'))->getJson('/api/audit-logs')->assertForbidden();

        $limited = $this->user('lim@acme.test', 'tenant-admin');
        Tenancy::set($this->tenant->id);
        $c = Company::create(['name' => 'PROMOSEVEN']);
        Tenancy::clear();
        DB::table('user_companies')->insert(['user_id' => $limited->id, 'company_id' => $c->id]);
        $this->as($limited)->getJson('/api/audit-logs')->assertForbidden();
        $this->as($limited)->getJson('/api/error-logs')->assertForbidden();
    }

    public function test_error_logs_answer_administrators_only(): void
    {
        $this->as($this->admin)->getJson('/api/error-logs?level=warning')->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['counts' => ['server', 'device', 'browser', 'error', 'warning'], 'pagination']]);
        $this->as($this->user('hr@acme.test', 'hr-manager'))->getJson('/api/error-logs')->assertForbidden();
    }
}
