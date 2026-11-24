<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Device;
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
 * Owner, 2026-11-05: "in permission I need to hide some companies for someone; and a delete
 * permission given to someone and someone not". A user limited to some companies sees only
 * those (employees in no company and unlinked punches hidden); "Can delete: off" refuses
 * every delete whatever the level; nobody gives more than they have.
 */
class UserAccessLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Company $promoseven;

    private Company $avana;

    private Employee $ali;

    private Employee $shafi;

    private Employee $nobody;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();
        CompanyAccess::forget();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);
        Tenancy::set($this->tenant->id);

        $this->admin = $this->user('admin@acme.test', 'tenant-admin');
        $this->promoseven = Company::create(['name' => 'PROMOSEVEN']);
        $this->avana = Company::create(['name' => 'AVANA']);
        $it = Department::create(['name' => 'IT', 'company_id' => $this->promoseven->id]);
        $this->ali = Employee::create(['name' => 'Ali', 'employee_code' => '960802797', 'company_id' => $this->promoseven->id, 'department_id' => $it->id, 'is_active' => true]);
        $this->shafi = Employee::create(['name' => 'Shafi', 'employee_code' => '673', 'company_id' => $this->avana->id, 'is_active' => true]);
        $this->nobody = Employee::create(['name' => 'Loose', 'employee_code' => '999', 'is_active' => true]);

        $device = Device::create(['name' => 'Entrance', 'ip_address' => '192.0.2.60', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'is_active' => true]);
        foreach ([[$this->ali, '960802797'], [$this->shafi, '673'], [null, '5']] as $i => [$e, $pin]) {
            AttendanceLog::create(['device_id' => $device->id, 'device_user_id' => $pin, 'employee_id' => $e?->id, 'punched_at' => now()->subHours($i + 1), 'verify_method' => 1, 'status' => 0]);
        }
        Tenancy::clear();
    }

    private function user(string $email, string $role, array $extra = []): User
    {
        Tenancy::set($this->tenant->id);
        $u = User::withoutGlobalScopes()->create([
            'name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'tenant_id' => $this->tenant->id,
            'is_active' => true, 'email_verified_at' => now(), ...$extra,
        ]);
        $u->syncRoles([$role]);

        return $u;
    }

    private function limit(User $u, array $companies): void
    {
        foreach ($companies as $c) {
            DB::table('user_companies')->insert(['user_id' => $u->id, 'company_id' => $c->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        CompanyAccess::forget();
    }

    private function as(User $u)
    {
        CompanyAccess::forget();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    public function test_a_user_with_every_company_sees_everything_as_before(): void
    {
        $names = collect($this->as($this->admin)->getJson('/api/employees?per_page=100')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Ali', 'Loose', 'Shafi'], $names);
        $this->assertCount(3, $this->as($this->admin)->getJson('/api/attendance')->assertOk()->json('data'));
    }

    public function test_a_limited_user_sees_only_their_companies(): void
    {
        $hr = $this->user('hr@acme.test', 'hr-manager');
        $this->limit($hr, [$this->promoseven]);

        $this->assertSame(['Ali'], collect($this->as($hr)->getJson('/api/employees?per_page=100')->assertOk()->json('data'))->pluck('name')->all());
        $this->assertSame(['PROMOSEVEN'], collect($this->as($hr)->getJson('/api/companies')->assertOk()->json('data'))->pluck('name')->all());
        $this->as($hr)->getJson("/api/employees/{$this->shafi->id}")->assertNotFound();
        $this->as($hr)->getJson("/api/employees/{$this->nobody->id}")->assertNotFound();
        $this->assertSame(['960802797'], collect($this->as($hr)->getJson('/api/attendance')->assertOk()->json('data'))->pluck('device_user_id')->all());
        $this->as($hr)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.company_ids', [$this->promoseven->id]);
    }

    public function test_can_delete_off_refuses_every_delete_whatever_the_level(): void
    {
        $admin2 = $this->user('admin2@acme.test', 'tenant-admin', ['can_delete' => false]);

        $this->as($admin2)->deleteJson("/api/employees/{$this->shafi->id}")->assertForbidden();
        $permissions = $this->as($admin2)->getJson('/api/auth/me')->assertOk()->json('data.permissions');
        $this->assertNotContains('employees.delete', $permissions);
        $this->assertNotContains('devices.delete', $permissions);
        $this->assertContains('employees.update', $permissions);
        $this->assertTrue(Employee::withoutGlobalScopes()->whereKey($this->shafi->id)->exists());
    }

    public function test_the_users_screen_saves_both_and_shows_them(): void
    {
        $res = $this->as($this->admin)->postJson('/api/users', [
            'name' => 'Sara', 'email' => 'sara@acme.test', 'password' => 'secret-pass-1', 'role' => 'hr-manager',
            'can_delete' => false, 'company_ids' => [$this->promoseven->id],
        ])->assertCreated()->assertJsonPath('data.can_delete', false)->assertJsonPath('data.company_ids', [$this->promoseven->id]);

        $id = $res->json('data.id');
        $this->as($this->admin)->putJson("/api/users/{$id}", ['company_ids' => []])->assertOk()->assertJsonPath('data.company_ids', null);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'user.access_changed')->exists());
    }

    public function test_nobody_gives_more_than_they_have(): void
    {
        $limited = $this->user('limited@acme.test', 'tenant-admin');
        $this->limit($limited, [$this->promoseven]);
        $noDelete = $this->user('nodelete@acme.test', 'tenant-admin', ['can_delete' => false]);

        $body = ['name' => 'X', 'email' => 'x@acme.test', 'password' => 'secret-pass-1', 'role' => 'hr-manager'];
        $this->as($limited)->postJson('/api/users', $body + ['company_ids' => []])->assertForbidden();
        $this->as($limited)->postJson('/api/users', $body + ['company_ids' => [$this->avana->id]])->assertForbidden(); // not one of theirs
        $this->as($limited)->postJson('/api/users', $body + ['company_ids' => [$this->promoseven->id]])->assertCreated();

        $this->as($noDelete)->postJson('/api/users', ['email' => 'y@acme.test'] + $body + ['can_delete' => true])->assertForbidden();
        $this->as($noDelete)->putJson("/api/users/{$noDelete->id}", ['can_delete' => true])->assertStatus(422);
    }

    /** Owner, 2026-11-05: HR sees the company tree on Attendance, not the Companies or Employees tab. */
    public function test_attendance_only_level_reads_the_company_tree_but_nothing_else(): void
    {
        Tenancy::set($this->tenant->id);
        $level = \Spatie\Permission\Models\Role::create(['name' => 'HR attendance', 'guard_name' => 'web']);
        $level->givePermissionTo('attendance.view');
        $hr = $this->user('hrview@acme.test', 'HR attendance');

        $this->assertCount(2, $this->as($hr)->getJson('/api/companies')->assertOk()->json('data'));
        $this->as($hr)->getJson('/api/departments')->assertOk();
        $this->as($hr)->getJson('/api/attendance')->assertOk();
        $this->as($hr)->getJson('/api/employees')->assertForbidden();
        $this->as($hr)->postJson('/api/companies', ['name' => 'New'])->assertForbidden();
        $this->as($hr)->getJson("/api/companies/{$this->promoseven->id}")->assertForbidden();
    }

    public function test_a_deleted_company_in_someones_access_does_not_block_saving_them(): void
    {
        // 2026-11-24: the Seven Hotel's finance user kept City One Hotel after it was deleted; saving him did nothing.
        $finance = $this->user('finance@acme.test', 'employee');
        $this->limit($finance, [$this->promoseven, $this->avana]);
        Tenancy::set($this->tenant->id);
        $third = Company::create(['name' => 'THE7']);
        $this->avana->delete();
        Tenancy::clear();

        $this->as($this->admin)->putJson("/api/users/{$finance->id}", ['company_ids' => [$this->promoseven->id, $this->avana->id, $third->id]])
            ->assertOk()->assertJsonPath('data.company_ids', [$this->promoseven->id, $third->id]);
    }

    public function test_dropping_the_only_deleted_company_never_opens_every_company(): void
    {
        $finance = $this->user('finance@acme.test', 'employee');
        $this->limit($finance, [$this->avana]);
        Tenancy::set($this->tenant->id);
        $this->avana->delete();
        Tenancy::clear();

        $this->as($this->admin)->putJson("/api/users/{$finance->id}", ['company_ids' => [$this->avana->id]])
            ->assertStatus(422)->assertJsonValidationErrors('company_ids');
        CompanyAccess::forget();
        $this->assertSame([$this->avana->id], CompanyAccess::for($finance->fresh()), 'still limited, never every company');
    }
}
