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

/** Terminals → "Enrol fingerprints and cards" (devices.enroll) gates enrolling since 2026-11-26; before, nothing. */
class EnrolPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrol_fingerprints_and_cards_lets_someone_enrol_but_not_remove(): void
    {
        Tenancy::clear();
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($tenant);
        Tenancy::set($tenant->id);
        $hr = User::withoutGlobalScopes()->create(['name' => 'HR', 'email' => 'hr@acme.test', 'password' => 'secret-pass-1',
            'tenant_id' => $tenant->id, 'is_active' => true, 'email_verified_at' => now()]);
        $hr->syncRoles(['employee']);
        $hr->givePermissionTo(['employees.view', 'devices.enroll']);
        $e = Employee::create(['name' => 'Ali', 'employee_code' => '7', 'is_active' => true]);
        $device = Device::create(['name' => 'IT ROOM', 'model' => 'F18', 'ip_address' => '192.0.2.62', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'is_active' => true]);

        $this->assertTrue($hr->can('enrollFingerprint', $e));
        $this->assertTrue($hr->can('enrollCard', $e));
        $this->assertFalse($hr->can('pushToDevice', $e), 'removing, deleting fingers and cards stays on push');
        Tenancy::clear();

        $this->actingAs($hr, 'sanctum')->deleteJson("/api/employees/{$e->id}/devices/{$device->id}/card")->assertForbidden();
        $this->actingAs($hr, 'sanctum')->getJson('/api/devices/options')->assertOk()->assertJsonPath('data.0.protocol', 'binary');
    }
}
