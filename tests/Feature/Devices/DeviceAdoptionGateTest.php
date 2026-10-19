<?php

namespace Tests\Feature\Devices;

use App\Models\Device;
use App\Models\DeviceRosterEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The gate that decides whether a terminal will accept a write, and the button that
 * opens it.
 *
 * The gate itself is old and is not being weakened here. What was missing was any way
 * to TAKE the step from the UI — the endpoint and a console command existed and no
 * screen called either — so every push came back refused with an instruction the
 * operator could not act on. These tests cover the two halves that must stay true:
 * the refusal is real while anything on the device is undecided, and the payload says
 * so BEFORE an action is offered (rule 9).
 */
class DeviceAdoptionGateTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-password';

    private Tenant $tenant;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $this->tenant = Tenant::where('slug', 'acme')->firstOrFail();

        Tenancy::set($this->tenant->id);

        $user = User::withoutGlobalScopes()->create([
            'name' => 'Admin',
            'email' => 'admin@acme.test',
            'password' => Hash::make(self::PASSWORD),
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->syncRoles(['tenant-admin']);

        $this->device = Device::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main Gate',
            'ip_address' => '192.0.2.40',
            'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY,
            'model' => 'F18', // rule 21: write levels come from the model
            'adoption_status' => Device::READ_ONLY,
            'roster_read_at' => now()->subHour(),
            'is_active' => true,
        ]);

        Tenancy::clear();
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

    private function rosterEntry(string $claimStatus): DeviceRosterEntry
    {
        Tenancy::set($this->tenant->id);

        $entry = DeviceRosterEntry::create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $this->device->id,
            'device_user_id' => (string) random_int(100, 999),
            'device_name' => 'Somebody',
            'claim_status' => $claimStatus,
        ]);

        Tenancy::clear();

        return $entry;
    }

    /**
     * The payload has to SAY a device is read-only, or every screen offers pushes the
     * hardware will refuse and the only way to find out is to try one.
     */
    public function test_the_device_payload_states_whether_it_will_accept_a_write(): void
    {
        $this->rosterEntry(DeviceRosterEntry::UNCLAIMED);

        $res = $this->asAdmin()->getJson('/api/devices')->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', $this->device->id);

        $this->assertFalse($row['is_writable']);
        $this->assertSame(Device::READ_ONLY, $row['adoption_status']);
        $this->assertSame(1, $row['unclaimed_roster_entries']);
        $this->assertStringContainsString('read-only', $row['write_refusal']);
    }

    public function test_adopting_is_refused_while_anything_on_the_device_is_undecided(): void
    {
        $this->rosterEntry(DeviceRosterEntry::UNCLAIMED);

        $res = $this->asAdmin()->postJson("/api/devices/{$this->device->id}/adopt")->assertStatus(422);

        // The refusal names the count, because that is the number the operator has to
        // work down before the button does anything.
        $this->assertStringContainsString('1 roster entr', $res->json('message'));

        $this->assertSame(Device::READ_ONLY, $this->device->fresh()->adoption_status);
    }

    public function test_adopting_unlocks_writes_once_every_entry_is_decided(): void
    {
        $this->rosterEntry(DeviceRosterEntry::IGNORED);

        $this->asAdmin()->postJson("/api/devices/{$this->device->id}/adopt")->assertOk();

        $device = $this->device->fresh();

        $this->assertSame(Device::ADOPTED, $device->adoption_status);
        $this->assertTrue($device->isWritable());
        // And the refusal is gone, which is what the propagator checks.
        $this->assertNull($device->userWriteRefusal());
        $this->assertNotNull($device->adopted_at);
    }

    /**
     * Reading a roster is how a device becomes adoptable, so a device that has never
     * been read cannot be adopted — there is no evidence about who is already on it.
     */
    public function test_a_device_whose_roster_was_never_read_cannot_be_adopted(): void
    {
        Tenancy::set($this->tenant->id);
        $this->device->forceFill(['roster_read_at' => null])->save();
        Tenancy::clear();

        $this->asAdmin()->postJson("/api/devices/{$this->device->id}/adopt")->assertStatus(422);

        $this->assertSame(Device::READ_ONLY, $this->device->fresh()->adoption_status);
    }

    /**
     * A refused write must reach the screen with its reason (2026-10-11). It used to
     * fall through to a generic 500 "Server error.", so the Employees page could only
     * say "could not be removed" with nothing the operator could act on.
     */
    public function test_a_write_to_an_unadopted_terminal_is_a_409_that_says_why(): void
    {
        $this->rosterEntry(DeviceRosterEntry::UNCLAIMED);

        Tenancy::set($this->tenant->id);
        $employee = \App\Models\Employee::where('employee_code', '1001')->firstOrFail();
        Tenancy::clear();

        $res = $this->asAdmin()
            ->postJson("/api/employees/{$employee->id}/devices/{$this->device->id}/remove")
            ->assertStatus(409);

        // It names who is in the way, because that is what the operator must decide.
        $this->assertStringContainsString('Main Gate holds 1 person nobody has matched', $res->json('message'));
        $this->assertStringContainsString('Somebody', $res->json('message'));
        $this->assertSame('not_adopted', $res->json('errors.reason'));
    }
}
