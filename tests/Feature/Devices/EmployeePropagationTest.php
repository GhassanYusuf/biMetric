<?php

namespace Tests\Feature\Devices;

use App\Services\Devices\DeviceUserSyncService;

use Promoseven\Biometric\Capability\Capability;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
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

            public function saveUser(Device $device, string $userId, string $name, ?\Closure $guard = null, bool $onlyIfMissing = false, ?int $privilege = null, ?string $password = null): DeviceUser
            {
                if ($device->id === $this->failOn) {
                    throw new DeviceException('The device did not answer.');
                }

                return parent::saveUser($device, $userId, $name, $guard, $onlyIfMissing, $privilege, $password);
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
     * A terminal holding somebody nobody has matched is READ-ONLY. Queueing a push at
     * it would fail on the worker minutes later, by which time the operator has gone
     * — so it is refused here, with the reason, while they are still looking.
     */
    public function test_a_device_that_cannot_be_written_to_is_skipped_with_a_reason(): void
    {
        Queue::fake();

        $this->app->instance(BiometricDeviceDriver::class, new FakeBiometricDriver);

        $unadopted = $this->device('Warehouse', Device::READ_ONLY);
        // Since 2026-10-11 a terminal is only refused when somebody on it is
        // unmatched — otherwise the write adopts it by itself.
        \App\Models\DeviceRosterEntry::create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $unadopted->id,
            'device_user_id' => '77',
            'device_name' => 'Unknown person',
            'claim_status' => \App\Models\DeviceRosterEntry::UNCLAIMED,
        ]);

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

    // ---------------------------------------------------------------- user ID follows the code

    private function onDevice(FakeBiometricDriver $driver, string $pin): void
    {
        $driver->roster[] = new DeviceUser(uid: 7, userId: $pin, name: $this->employee->name, privilege: 14, cardNumber: 2862247, fingerSlots: [5]);
    }

    /**
     * Added 2026-09-23. With to_employee_code the terminal record moves to the code
     * (same uid, card and fingers kept), the OLD mapping is closed rather than edited so
     * punches under the old PIN stay theirs, and the old PIN stays reserved.
     */
    /**
     * The F18 refuses renumbering (ACK_ERROR, 2026-09-23), so on the default capability
     * set the ID is left alone — never attempted — and the name is still pushed under it.
     */
    /*
     * Casebook DC-014 (owner, 2026-10-11): a terminal left on the old ID is a FAILURE,
     * not a green row with a note — every save must put the code on every terminal.
     * The name still goes out.
     */
    public function test_a_model_with_no_way_to_move_the_id_is_reported_failed_with_the_name_still_pushed(): void
    {
        $driver = new FakeBiometricDriver;
        // A model with neither method: no in-place move, and no delete to re-add with.
        $driver->capabilityOverrides = [
            \Promoseven\Biometric\Capability\Capability::USER_RENUMBER => \Promoseven\Biometric\Capability\Capability::NOT_IMPLEMENTED,
            \Promoseven\Biometric\Capability\Capability::USER_DELETE => \Promoseven\Biometric\Capability\Capability::NOT_IMPLEMENTED,
        ];
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        $this->onDevice($driver, '960802797');
        $old = $this->mapTo($this->adopted, '960802797');

        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id],
            'to_employee_code' => true,
        ])->assertOk()->assertJsonCount(0, 'data.written')->assertJsonCount(1, 'data.failed')
            ->assertJsonPath('data.failed.0.error', fn ($e) => str_contains($e, 'Name written, but the user ID is still 960802797'));

        $this->assertSame([], $driver->renumbers);
        $this->assertSame([['960802797', $this->employee->name]], $driver->writes);
        $this->assertNull($old->fresh()->valid_to);
    }

    public function test_to_employee_code_moves_the_terminal_user_id_and_keeps_history(): void
    {
        $driver = new FakeBiometricDriver;
        // Firmware that accepts a renumber — NOT the F18, which refuses it.
        $driver->capabilityOverrides = [\Promoseven\Biometric\Capability\Capability::USER_RENUMBER => 'implemented'];
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        $this->onDevice($driver, '960802797');
        $old = $this->mapTo($this->adopted, '960802797');

        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id],
            'to_employee_code' => true,
        ])->assertOk()->assertJsonCount(1, 'data.written')->assertJsonPath('data.written.0.pin', '501');

        $this->assertSame([['960802797', '501']], $driver->renumbers);
        $this->assertNotNull($old->fresh()->valid_to);
        $this->assertDatabaseHas('employee_device_maps', [
            'employee_id' => $this->employee->id, 'device_id' => $this->adopted->id,
            'device_user_id' => '501', 'valid_to' => null,
        ]);
        // The name push afterwards addressed the NEW user ID.
        $this->assertSame('501', end($driver->writes)[0]);
    }

    /**
     * A C3 panel (2026-09-24): no name or card can be written, but with `user.renumber`
     * the ID move goes through — and ONLY that; the name write is skipped with a note,
     * never attempted and never counted as a failure.
     */
    public function test_a_panel_that_cannot_take_names_still_gets_the_id_move_and_nothing_else(): void
    {
        $driver = new FakeBiometricDriver;
        $driver->capabilityOverrides = [
            \Promoseven\Biometric\Capability\Capability::USER_RENUMBER => 'implemented',
            \Promoseven\Biometric\Capability\Capability::USER_WRITE => 'not_implemented',
        ];
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        $this->onDevice($driver, '656');
        $this->mapTo($this->adopted, '656');

        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id],
            'to_employee_code' => true,
        ])->assertOk()
            ->assertJsonCount(1, 'data.written')->assertJsonCount(0, 'data.failed')
            ->assertJsonPath('data.written.0.pin', '501');

        $this->assertSame([['656', '501']], $driver->renumbers);
        $this->assertSame([], $driver->writes, 'no name write on a panel that cannot take one');

        // Pushed again with nothing to move: a skip with a reason, not a failure.
        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id],
            'to_employee_code' => true,
        ])->assertOk()->assertJsonCount(1, 'data.skipped')->assertJsonCount(0, 'data.failed');
    }

    public function test_without_the_option_a_push_never_changes_the_user_id(): void
    {
        $driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        $this->onDevice($driver, '960802797');
        $this->mapTo($this->adopted, '960802797');

        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id],
        ])->assertOk()->assertJsonPath('data.written.0.pin', '960802797');

        $this->assertSame([], $driver->renumbers);
    }

    /**
     * A code somebody else ever held on this terminal is refused before the device is
     * touched: handing it over would re-attribute that person's punches. Nothing is
     * written to that terminal — not even the name — so it is never half-done.
     */
    public function test_a_user_id_someone_else_held_is_refused_and_nothing_is_written(): void
    {
        $driver = new FakeBiometricDriver;
        $driver->capabilityOverrides = [\Promoseven\Biometric\Capability\Capability::USER_RENUMBER => 'implemented'];
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        $this->onDevice($driver, '960802797');
        $this->mapTo($this->adopted, '960802797');

        $other = Employee::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Former', 'employee_code' => '900', 'is_active' => true,
        ]);
        EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id, 'device_id' => $this->adopted->id, 'employee_id' => $other->id,
            'device_user_id' => '501', 'valid_from' => now()->subYears(2), 'valid_to' => now()->subYear(),
        ]);

        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id],
            'to_employee_code' => true,
        ])->assertOk()->assertJsonCount(0, 'data.written')->assertJsonCount(1, 'data.failed');

        $this->assertSame([], $driver->renumbers);
        $this->assertSame([], $driver->writes);
    }

    /** The device refusing the move (or losing a finger) leaves our records untouched. */
    public function test_a_failed_move_on_the_device_changes_nothing_here(): void
    {
        $driver = new class extends FakeBiometricDriver
        {
            public function renumberUser(Device $device, string $fromUserId, string $toUserId): DeviceUser
            {
                throw new \Promoseven\Biometric\Exception\VerificationException('fingerprint slot(s) 5 are no longer on the device');
            }
        };
        $driver->capabilityOverrides = [\Promoseven\Biometric\Capability\Capability::USER_RENUMBER => 'implemented'];
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        $this->onDevice($driver, '960802797');
        $old = $this->mapTo($this->adopted, '960802797');

        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id],
            'to_employee_code' => true,
        ])->assertOk()->assertJsonCount(1, 'data.failed')
            ->assertJsonPath('data.failed.0.error', 'fingerprint slot(s) 5 are no longer on the device');

        $this->assertNull($old->fresh()->valid_to);
        $this->assertSame([], $driver->writes);
    }

    /*
     * Rule 21 / DC-014: the F18 refuses an in-place move, so its method is to re-add
     * the person under the code with their card and fingers put back (rule 19).
     */
    public function test_on_the_f18_the_id_moves_by_re_adding_with_card_and_fingers_back(): void
    {
        $driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        $this->onDevice($driver, '960802797');
        $this->mapTo($this->adopted, '960802797');
        $blob = "\x4A\x2DSS21".random_bytes(300);
        $driver->templates = [['user_id' => '960802797', 'uid' => 7, 'slot' => 5, 'valid' => 1, 'size' => 306, 'sha1' => sha1($blob), 'template' => $blob]];

        $res = $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id],
            'to_employee_code' => true,
        ])->assertOk();

        $this->assertSame([], $res->json('data.failed'), json_encode($res->json('data.failed')));
        $res->assertJsonPath('data.written.0.pin', '501');
        $this->assertSame(['960802797'], $driver->deletes);
        $this->assertSame([['501', 2862247]], $driver->cardWrites);
        $this->assertSame([['user_id' => '501', 'slot' => 5, 'template' => $blob]], $driver->uploads);
        $this->assertStringContainsString('by re-adding them', $res->json('data.written.0.message'));
        $this->assertSame(14, collect($driver->roster)->firstWhere('userId', '501')->privilege, 'an admin stays an admin');
    }

    public function test_a_model_that_cannot_put_fingers_back_is_refused_before_anything_is_deleted(): void
    {
        $driver = new FakeBiometricDriver;
        // The F22 today: no in-place move proven, no fingerprint write proven.
        $driver->capabilityOverrides = [
            \Promoseven\Biometric\Capability\Capability::USER_RENUMBER => \Promoseven\Biometric\Capability\Capability::UNKNOWN,
            \Promoseven\Biometric\Capability\Capability::TEMPLATE_UPLOAD => \Promoseven\Biometric\Capability\Capability::UNKNOWN,
        ];
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        $this->onDevice($driver, '960802797');
        $this->mapTo($this->adopted, '960802797');

        $res = $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id],
            'to_employee_code' => true,
        ])->assertOk()->assertJsonCount(1, 'data.failed');

        $this->assertStringContainsString('cannot take fingerprints from this app yet', $res->json('data.failed.0.error'));
        $this->assertSame([], $driver->deletes);
        $this->assertSame([], $driver->writes);
    }

    /*
     * The pre-check and the claim are ONE check (2026-10-11): a number whose terminal
     * roster entry the owner has given to this employee — no mapping behind it — is
     * free for the move, and the claim after the delete accepts it too.
     */
    public function test_a_number_given_to_them_on_the_roster_is_claimable_after_the_delete(): void
    {
        $driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        $this->onDevice($driver, '960802797');
        $this->mapTo($this->adopted, '960802797');
        \App\Models\DeviceRosterEntry::create([
            'tenant_id' => $this->adopted->tenant_id, 'device_id' => $this->adopted->id, 'device_user_id' => '501',
            'device_name' => 'old test record', 'claim_status' => \App\Models\DeviceRosterEntry::CLAIMED,
            'claimed_employee_id' => $this->employee->id, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $driver->templates = [];
        $driver->roster[0] = new DeviceUser(uid: 7, userId: '960802797', name: $this->employee->name, cardNumber: 2862247, fingerSlots: []);

        $res = $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id], 'to_employee_code' => true,
        ])->assertOk();

        $this->assertSame([], $res->json('data.failed'), json_encode($res->json('data.failed')));
        $res->assertJsonPath('data.written.0.pin', '501');
    }

    // ---------------------------------------------------------------- the buttons on a red row (DC-014)

    private function unlinkedHolder(FakeBiometricDriver $driver, string $pin, string $name, ?int $claimedBy = null): void
    {
        $driver->roster[] = new DeviceUser(uid: 6, userId: $pin, name: $name, cardNumber: 2862247, fingerSlots: [5]);
        \App\Models\DeviceRosterEntry::create([
            'tenant_id' => $this->tenant->id, 'device_id' => $this->adopted->id, 'device_user_id' => $pin,
            'device_name' => $name, 'claim_status' => \App\Models\DeviceRosterEntry::CLAIMED,
            'claimed_employee_id' => $claimedBy, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    /*
     * The Ali case: his code is held on IT ROOM by "ali-it", a test record linked to no
     * employee. The push row offers to free it; freeing saves the record and its finger
     * first, deletes it, and the next push moves him onto the number.
     */
    public function test_a_number_held_by_an_unlinked_record_is_offered_freed_and_then_taken(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        $driver->roster[] = new DeviceUser(uid: 7, userId: '960802797', name: $this->employee->name, cardNumber: 7511517, fingerSlots: []);
        $this->mapTo($this->adopted, '960802797');
        $this->unlinkedHolder($driver, '501', 'ali-it');
        $driver->templates = [['user_id' => '501', 'uid' => 6, 'slot' => 5, 'valid' => 1, 'size' => 4, 'sha1' => sha1('AAAA'), 'template' => 'AAAA']];

        $first = $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id], 'to_employee_code' => true,
        ])->assertOk();
        $first->assertJsonPath('data.failed.0.action.type', 'release_number')
            ->assertJsonPath('data.failed.0.action.device_user_id', '501')
            ->assertJsonPath('data.failed.0.action.holder', 'ali-it');
        $this->assertSame([], $driver->deletes, 'nothing changes until the operator presses the button');

        $release = $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/devices/{$this->adopted->id}/release-number", [
            'device_user_id' => '501',
        ])->assertOk();
        $this->assertSame(['501'], $driver->deletes);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($release->json('data.snapshot'));
        $saved = json_decode(\Illuminate\Support\Facades\Crypt::decryptString(\Illuminate\Support\Facades\Storage::disk('local')->get($release->json('data.snapshot'))), true);
        $this->assertSame('ali-it', $saved['record']['name']);
        $this->assertSame(base64_encode('AAAA'), $saved['templates'][0]['template']);

        $driver->templates = [];
        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id], 'to_employee_code' => true,
        ])->assertOk()->assertJsonCount(0, 'data.failed')->assertJsonPath('data.written.0.pin', '501');
    }

    /*
     * "ali-it and Ali Mohamed are the same person" (owner, 2026-10-11). The duplicate's
     * finger becomes the employee's copy, its punches are credited to them, it is
     * removed, and the next push gives them ONE record at the code with every finger.
     */
    public function test_same_person_merges_the_duplicate_then_one_record_carries_everything(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        $driver->roster[] = new DeviceUser(uid: 7, userId: '960802797', name: $this->employee->name, privilege: 14, cardNumber: 7511517, fingerSlots: []);
        $this->mapTo($this->adopted, '960802797');
        $this->unlinkedHolder($driver, '501', 'ali-it');
        $five = "\x4A\x2DSS21".random_bytes(100);
        $driver->templates = [['user_id' => '501', 'uid' => 6, 'slot' => 5, 'valid' => 1, 'size' => strlen($five), 'sha1' => sha1($five), 'template' => $five]];
        AttendanceLog::create(['tenant_id' => $this->tenant->id, 'device_id' => $this->adopted->id, 'device_user_id' => '501', 'punched_at' => now()->subWeek(), 'status' => 0]);

        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/devices/{$this->adopted->id}/release-number", [
            'device_user_id' => '501', 'same_person' => true,
        ])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'merged into'));

        $this->assertSame(['501'], $driver->deletes);
        $this->assertSame([5], app(\App\Services\Employees\FingerprintVault::class)->storedSlots($this->employee->id));
        $this->assertSame($this->employee->id, (int) AttendanceLog::query()->where('device_user_id', '501')->value('employee_id'));

        $driver->templates = [];
        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id], 'to_employee_code' => true,
        ])->assertOk()->assertJsonCount(0, 'data.failed')->assertJsonPath('data.written.0.pin', '501');

        $one = collect($driver->roster)->filter(fn ($u) => $u->name === $this->employee->name)->values();
        $this->assertCount(1, $one, 'one record for the person');
        $this->assertSame('501', $one[0]->userId);
        $this->assertSame([5], $one[0]->fingerSlots);
        $this->assertSame(14, $one[0]->privilege);
        $this->assertSame([['501', 7511517]], $driver->cardWrites);
    }

    public function test_with_nobody_missing_a_finger_the_test_uses_a_temporary_user_and_deletes_it(): void
    {
        $driver = new FakeBiometricDriver;
        $driver->capabilityOverrides = [\Promoseven\Biometric\Capability\Capability::TEMPLATE_UPLOAD => \Promoseven\Biometric\Capability\Capability::UNKNOWN];
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        $this->onDevice($driver, '960802797'); // already holds finger 5
        $this->mapTo($this->adopted, '960802797');
        $other = $this->device('IT ROOM', Device::ADOPTED);
        $blob = "\x4A\x2DSS21".random_bytes(150);
        \App\Models\BiometricTemplate::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'device_id' => $other->id,
            'finger_slot' => 5, 'template' => base64_encode($blob), 'template_sha1' => sha1($blob),
            'template_size' => strlen($blob), 'template_format' => 'binary:F18', 'verified_at' => now(),
        ]);

        $this->asAdmin()->postJson("/api/devices/{$this->adopted->id}/fingerprint-write-test")
            ->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'temporary user'));

        $this->assertCount(1, $driver->uploads);
        $temp = $driver->uploads[0]['user_id'];
        $this->assertNotSame('960802797', $temp, 'no real person received the test finger');
        $this->assertSame([$temp], $driver->deletes, 'only the temporary user was deleted');
        $this->assertFalse(collect($driver->roster)->contains(fn ($u) => $u->userId === $temp));
        $this->assertSame('implemented', $this->adopted->fresh()->capabilities_cache['template.upload']);
    }

    public function test_a_number_that_belonged_to_an_employee_is_never_offered_or_freed(): void
    {
        $driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        $driver->roster[] = new DeviceUser(uid: 7, userId: '960802797', name: $this->employee->name, fingerSlots: []);
        $this->mapTo($this->adopted, '960802797');
        $other = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Real person', 'employee_code' => '999', 'is_active' => true]);
        $this->unlinkedHolder($driver, '501', 'Real person', $other->id);

        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id], 'to_employee_code' => true,
        ])->assertOk()->assertJsonPath('data.failed.0.action', null);

        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/devices/{$this->adopted->id}/release-number", [
            'device_user_id' => '501',
        ])->assertStatus(409);
        $this->assertSame([], $driver->deletes);
    }

    /*
     * On The Road: the F22 never wrote a fingerprint. The push row offers the test;
     * the test ADDS one finger the system holds to someone missing it there — nobody
     * deleted — and raises the level for that terminal only.
     */
    public function test_the_fingerprint_write_test_adds_one_held_finger_and_unlocks_only_that_terminal(): void
    {
        $driver = new FakeBiometricDriver;
        $driver->capabilityOverrides = [
            \Promoseven\Biometric\Capability\Capability::USER_RENUMBER => \Promoseven\Biometric\Capability\Capability::UNKNOWN,
            \Promoseven\Biometric\Capability\Capability::TEMPLATE_UPLOAD => \Promoseven\Biometric\Capability\Capability::UNKNOWN,
        ];
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        $this->onDevice($driver, '960802797'); // fingers [5] on the terminal
        $this->mapTo($this->adopted, '960802797');
        $other = $this->device('IT ROOM', Device::ADOPTED);
        $blob = "\x4A\x2DSS21".random_bytes(200);
        \App\Models\BiometricTemplate::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'device_id' => $other->id,
            'finger_slot' => 6, 'template' => base64_encode($blob), 'template_sha1' => sha1($blob),
            'template_size' => strlen($blob), 'template_format' => 'binary:F18', 'verified_at' => now(),
        ]);

        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", [
            'device_ids' => [$this->adopted->id], 'to_employee_code' => true,
        ])->assertOk()->assertJsonPath('data.failed.0.action.type', 'test_fingerprint_write');

        $this->asAdmin()->postJson("/api/devices/{$this->adopted->id}/fingerprint-write-test")
            ->assertOk()->assertJsonPath('data.slot', 6);

        $this->assertSame([['user_id' => '960802797', 'slot' => 6, 'template' => $blob]], $driver->uploads);
        $this->assertSame([], $driver->deletes);
        $this->assertSame('implemented', $this->adopted->fresh()->capabilities_cache['template.upload']);
        $this->assertArrayNotHasKey('template.upload', $other->fresh()->capabilities_cache ?? []);
    }

    /*
     * The keypad password (2026-10-12). A push writes the system's password only where
     * the model's password.write is CONFIRMED; until then it keeps the terminal's own,
     * which library releases before 2.13.0 erased on every write.
     */
    public function test_a_push_keeps_the_terminal_password_until_writing_one_is_confirmed(): void
    {
        $driver = new FakeBiometricDriver;
        // Every model this project owns is CONFIRMED since 2026-10-20; the hold-back is
        // for one that is not (a new model, a generic terminal).
        $driver->capabilityOverrides = [Capability::PASSWORD_WRITE => Capability::IMPLEMENTED];
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        Tenancy::set($this->tenant->id);
        $this->employee->update(['device_password' => '4321']);
        Tenancy::clear();

        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", ['device_ids' => [$this->adopted->id]])->assertOk();

        $this->assertCount(1, $driver->passwordsWritten);
        $this->assertNull(array_values($driver->passwordsWritten)[0], 'held back: null keeps the terminal\'s password');
    }

    /*
     * The Device role on the form (2026-10-20, owner: "make it really work"). Applied
     * only when the save changed it (apply_role), only where user.privilege is
     * CONFIRMED, through setPrivilege() — read back and audited.
     */
    public function test_a_changed_device_role_is_applied_where_confirmed_and_audited(): void
    {
        $driver = new FakeBiometricDriver;
        $driver->capabilityOverrides = [Capability::USER_PRIVILEGE => Capability::CONFIRMED];
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        Tenancy::set($this->tenant->id);
        $this->employee->update(['privilege' => 'superadmin']);
        Tenancy::clear();

        $res = $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", ['device_ids' => [$this->adopted->id], 'apply_role' => true])->assertOk();

        $this->assertSame([[array_values($driver->privilegeWrites)[0][0], true]], $driver->privilegeWrites, 'Super admin is an administrator on the terminal');
        $this->assertStringContainsString('is now an administrator', $res->json('data.written.0.message'));
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'device.privilege_changed')->exists());
    }

    public function test_a_save_that_did_not_change_the_role_never_touches_it(): void
    {
        $driver = new FakeBiometricDriver;
        $driver->capabilityOverrides = [Capability::USER_PRIVILEGE => Capability::CONFIRMED];
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        Tenancy::set($this->tenant->id);
        $this->employee->update(['privilege' => 'user']);
        Tenancy::clear();

        // Real roles differ per terminal: a name-only save must not level them.
        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", ['device_ids' => [$this->adopted->id]])->assertOk();

        $this->assertSame([], $driver->privilegeWrites);
    }

    public function test_a_role_change_is_held_back_where_it_was_never_proven_and_says_so(): void
    {
        $driver = new FakeBiometricDriver;
        // The F18 and F22 are CONFIRMED since 2026-10-20; the hold-back is for a model
        // that is not (a new model, a generic terminal).
        $driver->capabilityOverrides = [Capability::USER_PRIVILEGE => Capability::IMPLEMENTED];
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        Tenancy::set($this->tenant->id);
        $this->employee->update(['privilege' => 'admin']);
        Tenancy::clear();

        $res = $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", ['device_ids' => [$this->adopted->id], 'apply_role' => true])->assertOk();

        $this->assertSame([], $driver->privilegeWrites);
        $this->assertStringContainsString('has not been proven on this terminal', $res->json('data.written.0.message'));
    }

    public function test_a_push_writes_the_password_once_the_model_has_confirmed_it(): void
    {
        $driver = new FakeBiometricDriver;
        $driver->capabilityOverrides = [Capability::PASSWORD_WRITE => Capability::CONFIRMED];
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        Tenancy::set($this->tenant->id);
        $this->employee->update(['device_password' => '4321']);
        Tenancy::clear();

        $this->asAdmin()->postJson("/api/employees/{$this->employee->id}/propagate", ['device_ids' => [$this->adopted->id]])->assertOk();

        $this->assertSame(['4321'], array_values($driver->passwordsWritten));
    }

    public function test_a_roster_read_keeps_a_password_only_the_terminal_knew_and_never_overwrites_ours(): void
    {
        $driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $driver);
        Tenancy::set($this->tenant->id);
        $this->mapTo($this->adopted, '501');
        $other = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Omar', 'employee_code' => '502', 'is_active' => true, 'device_password' => '1111']);
        EmployeeDeviceMap::create(['tenant_id' => $this->tenant->id, 'device_id' => $this->adopted->id, 'employee_id' => $other->id, 'device_user_id' => '502', 'valid_from' => now()->subYear()]);
        $driver->roster = [
            new DeviceUser(uid: 1, userId: '501', name: 'Aisha Rahman', password: '4321'),
            new DeviceUser(uid: 2, userId: '502', name: 'Omar', password: '9999'),
        ];

        app(DeviceUserSyncService::class)->syncEnrollmentState($this->adopted);

        $this->assertSame('4321', $this->employee->fresh()->device_password, 'captured from the terminal');
        $this->assertSame('1111', $other->fresh()->device_password, 'the system\'s own password is never overwritten by a read');
        Tenancy::clear();
    }
}
