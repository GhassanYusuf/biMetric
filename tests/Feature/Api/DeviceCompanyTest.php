<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Devices under their company (owner, 2026-11-11: "when I click GEMS I see its devices"). */
class DeviceCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_terminal_is_given_a_company_and_the_list_says_which(): void
    {
        Tenancy::clear();
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($tenant);
        Tenancy::set($tenant->id);
        $admin = User::withoutGlobalScopes()->create(['name' => 'A', 'email' => 'a@acme.test', 'password' => 'secret-pass-1', 'tenant_id' => $tenant->id, 'is_active' => true, 'email_verified_at' => now()]);
        $admin->syncRoles(['tenant-admin']);
        $gems = Company::create(['name' => 'GEMS']);
        $device = Device::create(['name' => 'Gems', 'ip_address' => '192.0.2.64', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'is_active' => true]);
        Tenancy::clear();

        $this->actingAs($admin, 'sanctum')->putJson("/api/devices/{$device->id}", [
            'name' => 'Gems', 'ip_address' => '192.0.2.64', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'company_id' => $gems->id,
        ])->assertOk();

        $row = collect($this->actingAs($admin, 'sanctum')->getJson('/api/devices')->assertOk()->json('data'))->firstWhere('id', $device->id);
        $this->assertSame($gems->id, $row['company_id']);
        $this->assertSame('GEMS', $row['company_name']);

        // The company card's terminal counts, by the Active switch (2026-11-11).
        Tenancy::set($device->tenant_id);
        Device::create(['name' => 'Gems 2', 'ip_address' => '192.0.2.65', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'is_active' => false, 'company_id' => $gems->id]);
        Tenancy::clear();
        $card = collect($this->actingAs($admin, 'sanctum')->getJson('/api/companies')->assertOk()->json('data'))->firstWhere('id', $gems->id);
        $this->assertSame([1, 1], [$card['devices_active_count'], $card['devices_inactive_count']]);

        // Back to no company.
        $this->actingAs($admin, 'sanctum')->putJson("/api/devices/{$device->id}", [
            'name' => 'Gems', 'ip_address' => '192.0.2.64', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'company_id' => null,
        ])->assertOk();
        $this->assertNull(Device::withoutGlobalScopes()->find($device->id)->company_id);

        // Another account's company is refused.
        $this->actingAs($admin, 'sanctum')->putJson("/api/devices/{$device->id}", [
            'name' => 'Gems', 'ip_address' => '192.0.2.64', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'company_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors('company_id');
    }
}
