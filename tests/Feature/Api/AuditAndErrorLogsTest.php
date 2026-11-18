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

    /** Owner, 2026-11-09: "you did not show what the record was before updating". */
    public function test_an_edit_records_before_and_after_and_a_delete_what_it_held(): void
    {
        Tenancy::set($this->tenant->id);
        $this->actingAs($this->admin);
        $p7 = Company::create(['name' => 'PROMOSEVEN']);
        $e = Employee::create(['name' => 'Akhil', 'employee_code' => '701', 'is_active' => true]);
        $e->update(['employee_code' => '921257970', 'company_id' => $p7->id]);
        $e->delete();
        Tenancy::clear();

        $update = AuditLog::withoutGlobalScopes()->where('action', 'employee.updated')->where('auditable_id', $e->id)->latest('id')->first();
        $this->assertSame(['701', '921257970'], $update->changes['employee_code']);
        $this->assertSame([null, $p7->id], array_map(fn ($v) => $v === null ? null : (int) $v, $update->changes['company_id']));
        $deleted = AuditLog::withoutGlobalScopes()->where('action', 'employee.deleted')->where('auditable_id', $e->id)->first();
        $this->assertSame(['921257970', null], $deleted->changes['employee_code']);

        $rows = collect($this->as($this->admin)->getJson('/api/audit-logs?subject_type=Employee&subject_id='.$e->id.'&action=employee.updated')->assertOk()->json('data.0.diff'))->keyBy('field');
        $this->assertSame('recorded', $rows['employee_code']['before_known']);
        $this->assertSame('701', $rows['employee_code']['before']);
        $this->assertSame('PROMOSEVEN', $rows['company_id']['after_name']);
    }

    public function test_an_old_row_gets_its_before_from_the_records_history_and_says_so(): void
    {
        Tenancy::set($this->tenant->id);
        $e = Employee::withoutEvents(fn () => Employee::create(['name' => 'Shahad', 'employee_code' => '119', 'is_active' => true]));
        // The pre-2026-11-09 shapes: a created snapshot, then updates holding new values only.
        AuditLog::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->admin->id, 'action' => 'employee.created', 'auditable_type' => Employee::class, 'auditable_id' => $e->id, 'changes' => ['name' => 'Shahad', 'employee_code' => '119']]);
        AuditLog::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->admin->id, 'action' => 'employee.updated', 'auditable_type' => Employee::class, 'auditable_id' => $e->id, 'changes' => ['employee_code' => '041110790', 'phone' => '3333']]);
        Tenancy::clear();

        $rows = collect($this->as($this->admin)->getJson('/api/audit-logs?subject_type=Employee&subject_id='.$e->id.'&action=employee.updated')->assertOk()->json('data.0.diff'))->keyBy('field');
        $this->assertSame(['119', '041110790', 'from_history'], [$rows['employee_code']['before'], $rows['employee_code']['after'], $rows['employee_code']['before_known']]);
        $this->assertSame('unknown', $rows['phone']['before_known']);
    }
}
