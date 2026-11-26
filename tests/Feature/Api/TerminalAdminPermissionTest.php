<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Employees → "Make employees administrators on terminals" (employees.terminal-admin, owner 2026-11-26). */
class TerminalAdminPermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private Employee $ali;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($tenant);
        Tenancy::set($tenant->id);
        $this->hr = User::withoutGlobalScopes()->create(['name' => 'HR', 'email' => 'hr@acme.test', 'password' => 'secret-pass-1',
            'tenant_id' => $tenant->id, 'is_active' => true, 'email_verified_at' => now()]);
        $this->hr->syncRoles(['employee']);
        $this->hr->givePermissionTo(['employees.view', 'employees.create', 'employees.update', 'employees.push']);
        $this->ali = Employee::create(['name' => 'Ali', 'employee_code' => '7', 'is_active' => true, 'privilege' => 'user']);
        $this->device = Device::create(['name' => 'IT ROOM', 'model' => 'F18', 'ip_address' => '192.0.2.62', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'is_active' => true]);
        Tenancy::clear();
    }

    public function test_push_alone_no_longer_makes_terminal_administrators(): void
    {
        $this->actingAs($this->hr, 'sanctum')->putJson("/api/employees/{$this->ali->id}/devices/{$this->device->id}/privilege", ['admin' => true])->assertForbidden();
        $this->actingAs($this->hr, 'sanctum')->putJson("/api/employees/{$this->ali->id}", ['name' => 'Ali', 'employee_code' => '7', 'privilege' => 'admin'])
            ->assertStatus(422)->assertJsonValidationErrors('privilege');
        $this->actingAs($this->hr, 'sanctum')->postJson('/api/employees', ['name' => 'Sara', 'employee_code' => '8', 'privilege' => 'admin'])
            ->assertStatus(422)->assertJsonValidationErrors('privilege');
    }

    public function test_editing_or_adding_without_touching_the_role_still_works(): void
    {
        $this->actingAs($this->hr, 'sanctum')->putJson("/api/employees/{$this->ali->id}", ['name' => 'Ali Hassan', 'employee_code' => '7', 'privilege' => 'user'])
            ->assertOk();
        $this->actingAs($this->hr, 'sanctum')->postJson('/api/employees', ['name' => 'Sara', 'employee_code' => '8', 'privilege' => 'user'])
            ->assertCreated();
    }

    public function test_with_the_permission_the_role_can_be_changed(): void
    {
        Tenancy::set($this->hr->tenant_id);
        $this->hr->givePermissionTo('employees.terminal-admin');
        Tenancy::clear();

        $this->actingAs($this->hr->fresh(), 'sanctum')->putJson("/api/employees/{$this->ali->id}", ['name' => 'Ali', 'employee_code' => '7', 'privilege' => 'admin'])
            ->assertOk();
    }
}
