<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** System → Enrollments tab and Legacy data tab are their own permissions (owner, 2026-11-26). */
class SystemTabsPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tabs_need_their_own_permission_not_employee_rights(): void
    {
        Tenancy::clear();
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($tenant);
        Tenancy::set($tenant->id);
        $hr = User::withoutGlobalScopes()->create(['name' => 'HR', 'email' => 'hr@acme.test', 'password' => 'secret-pass-1',
            'tenant_id' => $tenant->id, 'is_active' => true, 'email_verified_at' => now()]);
        $hr->syncRoles(['employee']);
        $hr->givePermissionTo(['employees.view', 'employees.create', 'employees.update']);
        Tenancy::clear();

        $this->actingAs($hr, 'sanctum')->getJson('/api/enrollment-sessions')->assertForbidden();
        $this->actingAs($hr, 'sanctum')->get('/api/legacy/export')->assertForbidden();

        Tenancy::set($tenant->id);
        $hr->givePermissionTo(['system.enrollments', 'system.legacy']);
        Tenancy::clear();
        $this->app['auth']->forgetGuards();
        $hr = $hr->fresh();

        $this->actingAs($hr, 'sanctum')->getJson('/api/enrollment-sessions')->assertOk();
        $this->actingAs($hr, 'sanctum')->get('/api/legacy/export')->assertOk();
    }
}
