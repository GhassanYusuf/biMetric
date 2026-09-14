<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessDeviceCommand;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceGroup;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Promoseven\Biometric\Capability\Capability;
use Tests\TestCase;

/**
 * The device layer's HTTP surface, with no hardware anywhere near it.
 *
 * Every assertion here is about a promise the API makes to its callers:
 *
 *  - device work is QUEUED, never performed in the request
 *  - a command the firmware cannot run is refused BEFORE it is queued
 *  - one tenant can never address another tenant's terminal
 *
 * The queue is faked, so these tests prove the controller's contract rather than
 * the worker's behaviour.
 */
class DeviceQueueTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-password';

    private Tenant $acme;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $this->acme = Tenant::where('slug', 'acme')->firstOrFail();
        $this->makeUser('admin@acme.test', 'tenant-admin', $this->acme);

        Tenancy::set($this->acme->id);
        $this->device = Device::create([
            'tenant_id' => $this->acme->id,
            'name' => 'Main Gate',
            'ip_address' => '192.0.2.10',
            'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY,
            'is_active' => true,
        ]);
        Tenancy::clear();
    }

    private function makeUser(string $email, string $role, Tenant $tenant): User
    {
        Tenancy::set($tenant->id);

        $user = User::withoutGlobalScopes()->create([
            'name' => ucfirst($role),
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

    private function asAdmin()
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => 'admin@acme.test',
            'password' => self::PASSWORD,
        ])->json('data.token');

        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    // --- Queueing ----------------------------------------------------------

    /**
     * The core promise. A fingerprint capture blocks for up to 30 seconds waiting
     * for a human; if the controller ever performed device work inline, this test
     * would hang against a real terminal instead of returning a command id.
     */
    public function test_dispatching_a_command_queues_it_and_returns_immediately(): void
    {
        Queue::fake();

        $res = $this->asAdmin()->postJson("/api/devices/{$this->device->id}/commands", [
            'command' => DeviceCommand::SYNC_ATTENDANCE,
        ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.command', DeviceCommand::SYNC_ATTENDANCE)
            ->assertJsonPath('data.status', DeviceCommand::STATUS_QUEUED);

        $this->assertDatabaseHas('device_commands', [
            'device_id' => $this->device->id,
            'command' => DeviceCommand::SYNC_ATTENDANCE,
            'status' => DeviceCommand::STATUS_QUEUED,
        ]);

        Queue::assertPushed(ProcessDeviceCommand::class);
    }

    public function test_a_full_resync_carries_the_flag_through_to_the_command_payload(): void
    {
        Queue::fake();

        $this->asAdmin()->postJson("/api/devices/{$this->device->id}/commands", [
            'command' => DeviceCommand::SYNC_ATTENDANCE,
            'full' => true,
        ])->assertOk();

        // The payload is what DeviceCommandHandler reads to decide whether the
        // watermark applies. If it were dropped here the button would look like it
        // worked and quietly run an ordinary incremental sync.
        $command = DeviceCommand::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertTrue($command->payload['full']);
    }

    public function test_a_sync_defaults_to_incremental_when_full_is_not_asked_for(): void
    {
        Queue::fake();

        $this->asAdmin()->postJson("/api/devices/{$this->device->id}/commands", [
            'command' => DeviceCommand::SYNC_ATTENDANCE,
        ])->assertOk();

        $command = DeviceCommand::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertFalse($command->payload['full']);
    }

    public function test_the_console_command_queues_a_full_sync_when_asked(): void
    {
        Queue::fake();

        $this->artisan('app:sync-biometric-devices', ['--device' => [$this->device->id], '--full' => true])
            ->assertSuccessful();

        $command = DeviceCommand::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertSame(DeviceCommand::SYNC_ATTENDANCE, $command->command);
        $this->assertTrue($command->payload['full']);
    }

    public function test_the_console_command_is_incremental_by_default(): void
    {
        Queue::fake();

        $this->artisan('app:sync-biometric-devices', ['--device' => [$this->device->id]])
            ->assertSuccessful();

        $command = DeviceCommand::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertFalse($command->payload['full']);
    }

    public function test_a_queued_command_appears_in_the_history_with_its_device(): void
    {
        Queue::fake();
        $this->asAdmin()->postJson("/api/devices/{$this->device->id}/commands", [
            'command' => DeviceCommand::HEALTH_CHECK,
        ])->assertOk();

        $res = $this->asAdmin()->getJson('/api/device-commands');

        $res->assertOk()
            ->assertJsonPath('data.0.command', DeviceCommand::HEALTH_CHECK)
            ->assertJsonPath('data.0.device_name', 'Main Gate');
    }

    public function test_an_unknown_command_is_rejected_by_validation(): void
    {
        Queue::fake();

        $this->asAdmin()
            ->postJson("/api/devices/{$this->device->id}/commands", ['command' => 'drop_all_data'])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    // --- Capability gating -------------------------------------------------

    /**
     * A refusal must happen BEFORE anything is queued, or the queue fills with
     * work that can only fail — and the operator learns nothing until it does.
     */
    public function test_a_command_the_firmware_cannot_run_is_refused_before_queueing(): void
    {
        Queue::fake();

        Tenancy::set($this->acme->id);
        $this->device->update([
            'capabilities_cache' => [Capability::ATTENDANCE_READ => Capability::NOT_IMPLEMENTED],
            'capabilities_probed_at' => now(),
        ]);
        Tenancy::clear();

        $res = $this->asAdmin()->postJson("/api/devices/{$this->device->id}/commands", [
            'command' => DeviceCommand::SYNC_ATTENDANCE,
        ]);

        $res->assertStatus(422);
        // The refusal names the capability, so the message is diagnosable.
        $this->assertStringContainsString(Capability::ATTENDANCE_READ, $res->json('message'));

        $this->assertDatabaseCount('device_commands', 0);
        Queue::assertNothingPushed();
    }

    /** `implemented` is usable: offered, labelled unproven, not blocked. */
    public function test_an_unproven_but_implemented_capability_is_still_allowed(): void
    {
        Queue::fake();

        Tenancy::set($this->acme->id);
        $this->device->update([
            'capabilities_cache' => [Capability::SET_TIME => Capability::IMPLEMENTED],
            'capabilities_probed_at' => now(),
        ]);
        Tenancy::clear();

        $this->asAdmin()
            ->postJson("/api/devices/{$this->device->id}/commands", ['command' => DeviceCommand::SYNC_TIME])
            ->assertOk();
    }

    public function test_health_and_info_commands_are_never_capability_gated(): void
    {
        Queue::fake();

        foreach ([DeviceCommand::HEALTH_CHECK, DeviceCommand::READ_INFO, DeviceCommand::PROBE_CAPABILITIES] as $c) {
            $this->asAdmin()
                ->postJson("/api/devices/{$this->device->id}/commands", ['command' => $c])
                ->assertOk();
        }
    }

    public function test_the_device_resource_exposes_every_capability_with_its_level(): void
    {
        $res = $this->asAdmin()->getJson("/api/devices/{$this->device->id}");

        $res->assertOk();
        $caps = $res->json('data.capabilities');

        $this->assertNotEmpty($caps);
        foreach ($caps as $key => $entry) {
            $this->assertArrayHasKey('level', $entry, $key);
            $this->assertArrayHasKey('usable', $entry, $key);
            $this->assertArrayHasKey('confirmed', $entry, $key);
            $this->assertArrayHasKey('description', $entry, $key);
        }
    }

    /**
     * `is_online` is DERIVED from last_seen_at, never stored — a device that just
     * stops answering never gets to mark itself offline, so a stored flag would
     * keep claiming it is up.
     */
    public function test_is_online_is_derived_and_can_disagree_with_the_stored_health(): void
    {
        Tenancy::set($this->acme->id);
        $this->device->update([
            'health_status' => Device::HEALTH_ONLINE,
            'last_seen_at' => now()->subDay(),
        ]);
        Tenancy::clear();

        $res = $this->asAdmin()->getJson("/api/devices/{$this->device->id}");

        $res->assertOk()
            ->assertJsonPath('data.health_status', Device::HEALTH_ONLINE)
            ->assertJsonPath('data.is_online', false);
    }

    // --- Command lifecycle -------------------------------------------------

    public function test_a_queued_command_can_be_cancelled(): void
    {
        Queue::fake();
        $id = $this->asAdmin()->postJson("/api/devices/{$this->device->id}/commands", [
            'command' => DeviceCommand::HEALTH_CHECK,
        ])->json('data.id');

        $this->asAdmin()->postJson("/api/device-commands/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', DeviceCommand::STATUS_CANCELLED);
    }

    /**
     * Cancelling twice is idempotent, not an error.
     *
     * A second cancel means the operator wants the command not to run, and it is
     * already not going to run — so it succeeds and the row keeps the finished_at
     * of the FIRST cancellation rather than being restamped.
     */
    public function test_cancelling_an_already_cancelled_command_is_idempotent(): void
    {
        Queue::fake();
        $id = $this->asAdmin()->postJson("/api/devices/{$this->device->id}/commands", [
            'command' => DeviceCommand::HEALTH_CHECK,
        ])->json('data.id');

        $first = $this->asAdmin()->postJson("/api/device-commands/{$id}/cancel")->assertOk();

        $this->asAdmin()->postJson("/api/device-commands/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', DeviceCommand::STATUS_CANCELLED)
            ->assertJsonPath('data.finished_at', $first->json('data.finished_at'));
    }

    // --- Groups ------------------------------------------------------------

    /**
     * One command PER DEVICE, not one for the group. A group run that half
     * succeeds has to stay legible per terminal, and a retry must not re-hit the
     * devices that already worked.
     */
    public function test_a_group_command_queues_one_command_per_member_device(): void
    {
        Queue::fake();

        Tenancy::set($this->acme->id);
        $second = Device::create([
            'tenant_id' => $this->acme->id,
            'name' => 'Back Door',
            'ip_address' => '192.0.2.11',
            'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY,
            'is_active' => true,
        ]);
        Tenancy::clear();

        $groupId = $this->asAdmin()->postJson('/api/device-groups', [
            'name' => 'All terminals',
            'device_ids' => [$this->device->id, $second->id],
        ])->assertStatus(201)->json('data.id');

        $res = $this->asAdmin()->postJson("/api/device-groups/{$groupId}/commands", [
            'command' => DeviceCommand::HEALTH_CHECK,
        ]);

        $res->assertOk();
        $this->assertCount(2, $res->json('data.command_ids'));
        $this->assertDatabaseCount('device_commands', 2);
    }

    public function test_a_group_with_no_addressable_terminals_is_refused(): void
    {
        Queue::fake();

        $groupId = $this->asAdmin()->postJson('/api/device-groups', ['name' => 'Empty'])
            ->assertStatus(201)->json('data.id');

        $this->asAdmin()
            ->postJson("/api/device-groups/{$groupId}/commands", ['command' => DeviceCommand::HEALTH_CHECK])
            ->assertStatus(422);
    }

    public function test_deleting_a_group_leaves_its_devices_alone(): void
    {
        $groupId = $this->asAdmin()->postJson('/api/device-groups', [
            'name' => 'Temporary',
            'device_ids' => [$this->device->id],
        ])->assertStatus(201)->json('data.id');

        $this->asAdmin()->deleteJson("/api/device-groups/{$groupId}")->assertOk();

        $this->assertDatabaseMissing('device_groups', ['id' => $groupId]);
        $this->assertDatabaseHas('devices', ['id' => $this->device->id]);
    }

    /**
     * `exists:devices,id` does NOT respect the global tenant scope, so without an
     * explicit re-filter a caller could attach another tenant's device by guessing
     * an id.
     */
    public function test_a_group_cannot_absorb_another_tenants_device(): void
    {
        $other = Tenant::where('slug', '!=', 'acme')->first()
            ?? Tenant::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);

        Tenancy::set($other->id);
        $foreign = Device::create([
            'tenant_id' => $other->id,
            'name' => 'Not Yours',
            'ip_address' => '198.51.100.5',
            'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY,
            'is_active' => true,
        ]);
        Tenancy::clear();

        $groupId = $this->asAdmin()->postJson('/api/device-groups', [
            'name' => 'Mine',
            'device_ids' => [$this->device->id, $foreign->id],
        ])->assertStatus(201)->json('data.id');

        $group = DeviceGroup::withoutGlobalScopes()->with('devices')->findOrFail($groupId);

        $this->assertSame([$this->device->id], $group->devices->pluck('id')->all());
    }

    // --- Tenant isolation --------------------------------------------------

    public function test_another_tenants_device_is_not_reachable(): void
    {
        $other = Tenant::where('slug', '!=', 'acme')->first()
            ?? Tenant::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);

        Tenancy::set($other->id);
        $foreign = Device::create([
            'tenant_id' => $other->id,
            'name' => 'Not Yours',
            'ip_address' => '198.51.100.6',
            'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY,
            'is_active' => true,
        ]);
        Tenancy::clear();

        $this->asAdmin()->getJson("/api/devices/{$foreign->id}")->assertNotFound();
    }

    // --- Enrollment sessions ----------------------------------------------

    public function test_a_fingerprint_capture_is_queued_rather_than_performed(): void
    {
        Queue::fake();

        Tenancy::set($this->acme->id);
        $employee = Employee::first();
        Tenancy::clear();

        $this->assertNotNull($employee, 'the seeder should provide at least one employee');

        $res = $this->asAdmin()->postJson(
            "/api/employees/{$employee->id}/devices/{$this->device->id}/enrollment/fingerprint",
            ['finger_slot' => 6],
        );

        $res->assertOk()->assertJsonPath('data.command', DeviceCommand::ENROLL_FINGERPRINT);
        Queue::assertPushed(ProcessDeviceCommand::class);
    }

    public function test_an_out_of_range_finger_slot_is_rejected(): void
    {
        Queue::fake();

        Tenancy::set($this->acme->id);
        $employee = Employee::first();
        Tenancy::clear();

        foreach ([-1, 10, 42] as $slot) {
            $this->asAdmin()->postJson(
                "/api/employees/{$employee->id}/devices/{$this->device->id}/enrollment/fingerprint",
                ['finger_slot' => $slot],
            )->assertStatus(422);
        }

        Queue::assertNothingPushed();
    }

    // --- Reconciliation ----------------------------------------------------

    /** The summary reads STORED state, so it must never touch the device. */
    public function test_the_reconciliation_summary_reports_without_contacting_the_device(): void
    {
        $res = $this->asAdmin()->getJson('/api/device-maps/summary');

        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('device_id', $this->device->id);

        $this->assertNotNull($row);
        foreach (['in_sync', 'missing_on_device', 'missing_in_system', 'mismatched', 'unattributed_punches'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
    }
}
