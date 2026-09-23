<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Employees\EmployeeDevicePropagator;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Promoseven\Biometric\Exception\DeviceException;
use Promoseven\Biometric\Value\DeviceUser;
use Tests\TestCase;

/**
 * Saving an employee, and pushing that save out to the terminals.
 *
 * The promise being tested is narrow and easy to break: an edit changes the
 * DATABASE, and the terminals change only because somebody was asked and said yes.
 *
 * The push itself is SYNCHRONOUS by the owner's instruction (2026-09-15) — a
 * deliberate exception to rule 11, because pressing Push is exactly the moment an
 * operator needs an answer rather than a queue position. So what is tested here is
 * that the write actually reaches the driver in the request, that a device which
 * cannot take a write is skipped without being attempted, and — the one that matters
 * most — that a failure on one terminal never cancels the others.
 */
class EmployeePropagationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-password';

    private Tenant $tenant;

    private Employee $employee;

    private Device $adopted;

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

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Aisha Rahman',
            'employee_code' => '501',
            'is_active' => true,
        ]);

        $this->adopted = $this->device('Main Gate', Device::ADOPTED);

        Tenancy::clear();
    }

    private function device(string $name, string $adoption, bool $active = true): Device
    {
        return Device::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'ip_address' => '192.0.2.'.random_int(10, 250),
            'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY,
            'adoption_status' => $adoption,
            'is_active' => $active,
        ]);
    }

    private function mapTo(Device $device, string $pin, ?string $closedAt = null): EmployeeDeviceMap
    {
        return EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $device->id,
            'employee_id' => $this->employee->id,
            'device_user_id' => $pin,
            'valid_from' => now()->subYear(),
            'valid_to' => $closedAt,
        ]);
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

    public function test_editing_an_employee_does_not_touch_any_device_by_itself(): void
    {
        Queue::fake();

        $this->asAdmin()->putJson("/api/employees/{$this->employee->id}", [
            'name' => 'Aisha Rahman-Khan',
            'employee_code' => '501',
        ])->assertOk();

        $this->assertDatabaseHas('employees', [
            'id' => $this->employee->id,
            'name' => 'Aisha Rahman-Khan',
        ]);

        /*
         * THE POINT. A name correction is not a hardware operation and must never
         * silently become one — six terminals would be written to because somebody
         * fixed a spelling. The push is a separate, explicit call.
         */
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_propagating_writes_to_every_device_in_the_request(): void
    {
        Queue::fake();

        $driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $driver);

        $second = $this->device('Back Door', Device::ADOPTED);

        $res = $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id, $second->id],
        ]);

        $res->assertOk()->assertJsonCount(2, 'data.written')->assertJsonCount(0, 'data.failed');

        // The writes REACHED the fake hardware during the request — not a queue entry
        // that would have run later, which is the whole point of the change.
        $this->assertCount(2, $driver->writes);
        $this->assertSame([$this->employee->name, $this->employee->name], array_column($driver->writes, 1));

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('device_commands', 0);
    }

    /**
     * A person pushed to a terminal they are not on yet has no PIN there, and
     * pushEmployee() refuses rather than inventing one — so the propagator claims one
     * first. Without this the "Add them to" half of the dialog could only ever fail.
     */
    public function test_pushing_to_a_new_terminal_allocates_a_pin_first(): void
    {
        $driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $driver);

        $this->assertDatabaseCount('employee_device_maps', 0);

        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id],
        ])->assertOk()->assertJsonCount(1, 'data.written');

        $this->assertDatabaseHas('employee_device_maps', [
            'employee_id' => $this->employee->id,
            'device_id' => $this->adopted->id,
            'valid_to' => null,
        ]);
    }

    /**
     * THE ONE THAT MATTERS. One dead terminal must not cancel the writes to healthy
     * ones — that turns a partial outage into a total one, and the operator has no way
     * to tell which happened.
     */
    public function test_a_failure_on_one_terminal_does_not_stop_the_others(): void
    {
        $failing = $this->device('Broken Gate', Device::ADOPTED);

        $driver = new class extends FakeBiometricDriver
        {
            public ?int $failOn = null;

            public function saveUser(Device $device, string $userId, string $name, ?\Closure $guard = null, bool $onlyIfMissing = false): DeviceUser
            {
                if ($device->id === $this->failOn) {
                    throw new DeviceException('The device did not answer.');
                }

                return parent::saveUser($device, $userId, $name, $guard, $onlyIfMissing);
            }
        };
        $driver->failOn = $failing->id;
        $this->app->instance(BiometricDeviceDriver::class, $driver);

        $res = $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$failing->id, $this->adopted->id],
        ])->assertOk();

        $res->assertJsonCount(1, 'data.written')
            ->assertJsonPath('data.written.0.device_name', 'Main Gate')
            ->assertJsonCount(1, 'data.failed')
            ->assertJsonPath('data.failed.0.device_name', 'Broken Gate');

        // And the message says BOTH, with the failure first.
        $this->assertStringContainsString('FAILED on Broken Gate', $res->json('message'));
        $this->assertStringContainsString('Main Gate', $res->json('message'));
    }

    /**
     * A terminal whose roster has never been read is READ-ONLY. Queueing a push at
     * it would fail on the worker minutes later, by which time the operator has gone
     * — so it is refused here, with the reason, while they are still looking.
     */
    public function test_a_device_that_cannot_be_written_to_is_skipped_with_a_reason(): void
    {
        Queue::fake();

        $this->app->instance(BiometricDeviceDriver::class, new FakeBiometricDriver);

        $unadopted = $this->device('Warehouse', Device::UNADOPTED);

        $res = $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id, $unadopted->id],
        ]);

        $res->assertOk()
            ->assertJsonCount(1, 'data.written')
            ->assertJsonCount(1, 'data.skipped')
            ->assertJsonPath('data.skipped.0.device_id', $unadopted->id);

        $this->assertStringContainsString('read-only', $res->json('data.skipped.0.reason'));

        // And the message an operator reads says BOTH halves — a sentence naming
        // only the successes would be a lie by omission.
        $this->assertStringContainsString('Main Gate', $res->json('message'));
        $this->assertStringContainsString('Warehouse', $res->json('message'));
    }

    public function test_a_disabled_device_is_skipped_too(): void
    {
        Queue::fake();

        $disabled = $this->device('Old Gate', Device::ADOPTED, active: false);

        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$disabled->id],
        ])->assertOk()
            ->assertJsonCount(0, 'data.written')
            ->assertJsonPath('data.skipped.0.reason', 'it is disabled');

        // Nothing was ATTEMPTED — a device that cannot take a write is not a device
        // that failed, and the two must not be reported as one.
        $this->assertDatabaseCount('device_commands', 0);
    }

    /**
     * `enrolledOn()` is what the edit form offers as "update them on". A CLOSED
     * mapping is a PIN they used to hold — kept so their old punches still resolve
     * to them — and whoever holds that slot now would be overwritten by a push.
     */
    public function test_only_live_enrollments_count_as_devices_they_are_on(): void
    {
        Tenancy::set($this->tenant->id);

        $former = $this->device('Former Site', Device::ADOPTED);
        $this->mapTo($this->adopted, '7');
        $this->mapTo($former, '7', closedAt: now()->subMonth()->toDateTimeString());

        $devices = app(EmployeeDevicePropagator::class)
            ->enrolledOn($this->employee->fresh());

        $this->assertSame([$this->adopted->id], $devices->pluck('id')->all());
    }

    // --- Merging duplicate records -----------------------------------------

    public function test_merging_moves_punches_and_mappings_and_deletes_the_folded_record(): void
    {
        Queue::fake();

        Tenancy::set($this->tenant->id);

        $duplicate = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Aisha Rahman',
            'employee_code' => '902',
            'is_active' => true,
        ]);

        // The duplicate is the one carrying the history — the common case, because
        // the record somebody searches from is often the newer, emptier one.
        $this->mapTo($this->adopted, '7');
        EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $this->adopted->id,
            'employee_id' => $duplicate->id,
            'device_user_id' => '902',
            'valid_from' => now()->subYear(),
        ]);

        AttendanceLog::create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $this->adopted->id,
            'device_user_id' => '902',
            'employee_id' => $duplicate->id,
            'punched_at' => now()->subDay(),
            'verify_method' => 1,
            'status' => 0,
        ]);

        Tenancy::clear();

        $this->asAdmin()->postJson('/api/employees/merge', [
            'keep_id' => $this->employee->id,
            'merge_ids' => [$duplicate->id],
        ])->assertOk();

        Tenancy::set($this->tenant->id);

        // The punch followed the human.
        $this->assertSame(1, AttendanceLog::where('employee_id', $this->employee->id)->count());

        // Both device identities are now theirs — being on several terminals under
        // several PINs is the normal case, not the problem being fixed.
        $this->assertEqualsCanonicalizing(
            ['7', '902'],
            EmployeeDeviceMap::where('employee_id', $this->employee->id)->pluck('device_user_id')->all(),
        );

        $this->assertDatabaseMissing('employees', ['id' => $duplicate->id]);

        /*
         * AND NO DEVICE WAS CONTACTED. This is the property that makes a merge safe
         * to run against read-only or unadopted terminals: the fingerprints never
         * move, because they never leave the hardware at all.
         */
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('device_commands', 0);
    }

    /**
     * The constraint case, and the reason closeCompetingMappings() exists.
     *
     * Two records for one human, both live on the SAME terminal under different PINs
     * — which is precisely what "I have two accounts" looks like when both were
     * enrolled at the same door. One live mapping per (device, employee) is enforced
     * by a partial unique index, so the merge has to decide which PIN is current
     * instead of dying on a constraint error mid-transaction.
     */
    public function test_two_records_live_on_the_same_terminal_merge_without_violating_the_index(): void
    {
        Tenancy::set($this->tenant->id);

        $duplicate = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Aisha Rahman',
            'employee_code' => '904',
            'is_active' => true,
        ]);

        $keepersMap = $this->mapTo($this->adopted, '7');
        $duplicatesMap = EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $this->adopted->id,
            'employee_id' => $duplicate->id,
            'device_user_id' => '904',
            'valid_from' => now()->subYear(),
        ]);

        Tenancy::clear();

        $this->asAdmin()->postJson('/api/employees/merge', [
            'keep_id' => $this->employee->id,
            'merge_ids' => [$duplicate->id],
        ])->assertOk();

        Tenancy::set($this->tenant->id);

        // Exactly one live identity on that terminal, and it is the keeper's own.
        $live = EmployeeDeviceMap::where('device_id', $this->adopted->id)->whereNull('valid_to')->get();
        $this->assertCount(1, $live);
        $this->assertSame($keepersMap->id, $live->first()->id);

        /*
         * And the loser is CLOSED, not deleted — still pointing at the merged human,
         * so punches made under PIN 904 before today still resolve to them. Dropping
         * the row would have orphaned exactly the history the merge exists to unify.
         */
        $loser = EmployeeDeviceMap::find($duplicatesMap->id);
        $this->assertNotNull($loser);
        $this->assertNotNull($loser->valid_to);
        $this->assertSame($this->employee->id, $loser->employee_id);
    }

    public function test_a_merge_is_recorded_in_the_audit_log(): void
    {
        Tenancy::set($this->tenant->id);
        $duplicate = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Aisha Rahman',
            'employee_code' => '903',
            'is_active' => true,
        ]);
        Tenancy::clear();

        $this->asAdmin()->postJson('/api/employees/merge', [
            'keep_id' => $this->employee->id,
            'merge_ids' => [$duplicate->id],
        ])->assertOk();

        // Irreversible from inside the app, so what happened has to survive it.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.merged',
            'auditable_id' => $this->employee->id,
        ]);
    }

    public function test_merging_a_record_into_itself_is_refused(): void
    {
        $this->asAdmin()->postJson('/api/employees/merge', [
            'keep_id' => $this->employee->id,
            'merge_ids' => [$this->employee->id],
        ])->assertStatus(422);

        $this->assertDatabaseHas('employees', ['id' => $this->employee->id]);
    }

    public function test_another_tenants_employee_cannot_be_merged_in(): void
    {
        $other = Tenant::where('slug', '!=', 'acme')->first();

        if ($other === null) {
            $this->markTestSkipped('The seed provides only one tenant.');
        }

        Tenancy::set($other->id);
        $foreign = Employee::create([
            'tenant_id' => $other->id,
            'name' => 'Someone Else',
            'employee_code' => '7001',
            'is_active' => true,
        ]);
        Tenancy::clear();

        $this->asAdmin()->postJson('/api/employees/merge', [
            'keep_id' => $this->employee->id,
            'merge_ids' => [$foreign->id],
        ])->assertStatus(422);

        // Two companies employing one human is the Person layer's job. Merging the
        // EMPLOYMENTS would erase the fact that they hold two.
        $this->assertDatabaseHas('employees', ['id' => $foreign->id]);
    }

    public function test_another_tenants_device_cannot_be_targeted(): void
    {
        Queue::fake();

        $other = Tenant::where('slug', '!=', 'acme')->first();

        if ($other === null) {
            $this->markTestSkipped('The seed provides only one tenant.');
        }

        Tenancy::set($other->id);
        $foreign = Device::create([
            'tenant_id' => $other->id,
            'name' => 'Their Gate',
            'ip_address' => '198.51.100.7',
            'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY,
            'adoption_status' => Device::ADOPTED,
            'is_active' => true,
        ]);
        Tenancy::clear();

        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$foreign->id],
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }
}
