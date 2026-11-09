<?php

namespace Tests\Feature\Devices;

use App\Jobs\ProcessDeviceCommand;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Devices → Import staff (owner, 2026-11-09, Gems Main Office: "has not had its roster read yet…
 * I told you to fix this before"). The button QUEUES the import command, whose handler reads
 * the roster first (DC-037); it no longer imports from roster rows that a new terminal has not
 * got yet.
 */
class ImportStaffButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_button_queues_the_import_that_reads_the_roster_first(): void
    {
        Queue::fake();
        Tenancy::clear();
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($tenant);
        Tenancy::set($tenant->id);
        $admin = User::withoutGlobalScopes()->create(['name' => 'A', 'email' => 'a@acme.test', 'password' => 'secret-pass-1', 'tenant_id' => $tenant->id, 'is_active' => true, 'email_verified_at' => now()]);
        $admin->syncRoles(['tenant-admin']);
        $device = Device::create(['name' => 'Gems Main Office', 'ip_address' => '192.0.2.64', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'is_active' => true]);
        Tenancy::clear();

        $res = $this->actingAs($admin, 'sanctum')->postJson("/api/devices/{$device->id}/import-employees")->assertOk();

        $command = DeviceCommand::withoutGlobalScopes()->findOrFail($res->json('data.id'));
        $this->assertSame(DeviceCommand::IMPORT_EMPLOYEES, $command->command);
        $this->assertStringNotContainsString('has not had its roster read yet', $res->json('message'));
        Queue::assertPushed(ProcessDeviceCommand::class);

        // A second press while it is under way does not queue a second import.
        $again = $this->actingAs($admin, 'sanctum')->postJson("/api/devices/{$device->id}/import-employees")->assertOk();
        $this->assertSame($command->id, $again->json('data.id'));
    }
}
