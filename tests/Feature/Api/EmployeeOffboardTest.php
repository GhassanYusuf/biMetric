<?php

namespace Tests\Feature\Api;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Integration\EmployeeOffboarding;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Devices\FakeBiometricDriver;
use Tests\TestCase;

/**
 * Delete, with the choice spelled out (owner, 2026-11-03): the record only; off every
 * terminal and kept as resigned with their fingerprints; or off every terminal and deleted.
 * Every terminal removal saves card and fingers first (rule 19) whichever is chosen.
 */
class EmployeeOffboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Device $door;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(BiometricDeviceDriver::class, new FakeBiometricDriver);
        Tenancy::clear();
        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);
        Tenancy::set($this->tenant->id);
        $this->admin = User::withoutGlobalScopes()->create([
            'name' => 'Admin', 'email' => 'admin@acme.test', 'password' => 'secret-pass-1',
            'tenant_id' => $this->tenant->id, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $this->admin->syncRoles(['tenant-admin']);
        $this->door = Device::create(['name' => 'IT ROOM', 'ip_address' => '192.0.2.62', 'port' => 4370, 'protocol' => 'zkteco']);
        $this->door->forceFill(['adoption_status' => 'adopted'])->save();
        Tenancy::clear();
    }

    private function person(string $code, bool $onTerminal = true): Employee
    {
        Tenancy::set($this->tenant->id);
        $e = Employee::create(['name' => "Ahmed {$code}", 'employee_code' => $code, 'is_active' => true]);
        if ($onTerminal) {
            EmployeeDeviceMap::create(['employee_id' => $e->id, 'device_id' => $this->door->id, 'device_user_id' => $code, 'valid_from' => now()]);
        }
        Tenancy::clear();

        return $e;
    }

    private function offboard(Employee $e, string $mode)
    {
        $token = $this->admin->createToken('test', ['*'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson("/api/employees/{$e->id}/offboard", ['mode' => $mode]);
    }

    private function removals(): int
    {
        return DeviceCommand::withoutGlobalScopes()->where('command', DeviceCommand::REMOVE_USER)->count();
    }

    public function test_record_only_deletes_the_record_and_touches_no_terminal(): void
    {
        $e = $this->person('11');

        $this->offboard($e, 'record')->assertOk()->assertJsonPath('data.deleted', true)
            ->assertJsonPath('message', 'Ahmed 11 deleted from the system. Their access on the terminals is unchanged.');

        $this->assertNull(Employee::withoutGlobalScopes()->find($e->id));
        $this->assertSame(0, $this->removals());
    }

    public function test_resign_takes_them_off_every_terminal_and_keeps_the_record(): void
    {
        $e = $this->person('12');

        $this->offboard($e, 'resign')->assertOk()->assertJsonPath('data.deleted', false)->assertJsonPath('data.device_commands', 1);

        $kept = Employee::withoutGlobalScopes()->find($e->id);
        $this->assertNotNull($kept);
        $this->assertNotNull($kept->resigned_at);
        $this->assertFalse((bool) $kept->is_active);
        $this->assertSame(1, $this->removals());
        $this->assertArrayNotHasKey(EmployeeOffboarding::DELETE_AFTER, DeviceCommand::withoutGlobalScopes()->first()->payload, 'the record is not deleted after');
    }

    public function test_everywhere_removes_from_terminals_then_deletes_once_they_confirm(): void
    {
        $e = $this->person('13');

        $this->offboard($e, 'everywhere')->assertOk()->assertJsonPath('data.deleted', false)->assertJsonPath('data.device_commands', 1);
        $this->assertFalse((bool) Employee::withoutGlobalScopes()->find($e->id)->is_active, 'inactive until every terminal confirms');

        $command = DeviceCommand::withoutGlobalScopes()->firstOrFail();
        $this->assertArrayHasKey(EmployeeOffboarding::DELETE_AFTER, $command->payload);
        $command->forceFill(['status' => DeviceCommand::STATUS_SUCCEEDED])->save();
        app(EmployeeOffboarding::class)->commandFinished($command->fresh());

        $this->assertNull(Employee::withoutGlobalScopes()->find($e->id));
    }

    public function test_everywhere_for_someone_on_no_terminal_deletes_at_once(): void
    {
        $e = $this->person('14', onTerminal: false);

        $this->offboard($e, 'everywhere')->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertNull(Employee::withoutGlobalScopes()->find($e->id));
    }

    public function test_punches_are_kept_whatever_is_chosen(): void
    {
        $e = $this->person('15');
        DB::table('attendance_logs')->insert(['tenant_id' => $this->tenant->id, 'device_id' => $this->door->id, 'device_user_id' => '15',
            'employee_id' => $e->id, 'punched_at' => now(), 'status' => 0, 'verify_method' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->offboard($e, 'record')->assertOk();

        $this->assertSame(1, DB::table('attendance_logs')->where('device_user_id', '15')->count());
    }

    public function test_an_unknown_choice_is_refused(): void
    {
        $this->offboard($this->person('16'), 'shred')->assertUnprocessable()->assertJsonValidationErrors('mode');
    }

    // --- Several at once, ticked on the Employees screen (owner, 2026-11-15) ---------------

    private function bulk(array $ids, string $mode, ?User $as = null)
    {
        $token = ($as ?? $this->admin)->createToken('test', ['*'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/api/employees/bulk-offboard', ['ids' => $ids, 'mode' => $mode]);
    }

    public function test_bulk_everywhere_treats_each_person_as_a_single_delete_would(): void
    {
        $on = $this->person('21');
        $off = $this->person('22', onTerminal: false);

        $this->bulk([$on->id, $off->id], 'everywhere')->assertOk()
            ->assertJsonPath('data.deleted', 1)
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.device_commands', 1);

        // The one on a terminal waits for it (removal queued, card and fingers saved by it);
        // the one on none is gone at once.
        $this->assertSame(1, $this->removals());
        $this->assertFalse((bool) Employee::withoutGlobalScopes()->find($on->id)->is_active);
        $this->assertNull(Employee::withoutGlobalScopes()->find($off->id));
    }

    public function test_bulk_resign_keeps_every_record(): void
    {
        $a = $this->person('23');
        $b = $this->person('24');

        $this->bulk([$a->id, $b->id], 'resign')->assertOk()->assertJsonPath('data.resigned', 2)->assertJsonPath('data.device_commands', 2);

        foreach ([$a, $b] as $e) {
            $this->assertNotNull(Employee::withoutGlobalScopes()->find($e->id)->resigned_at);
        }
    }

    public function test_bulk_record_only_touches_no_terminal(): void
    {
        $a = $this->person('25');

        $this->bulk([$a->id], 'record')->assertOk()->assertJsonPath('data.record_only', 1);

        $this->assertSame(0, $this->removals());
        $this->assertNull(Employee::withoutGlobalScopes()->find($a->id));
    }

    public function test_bulk_reports_an_unknown_id_and_carries_on(): void
    {
        $a = $this->person('26', onTerminal: false);

        $res = $this->bulk([$a->id, 999999], 'everywhere')->assertOk()->assertJsonPath('data.deleted', 1);
        $this->assertSame(['#999999 (not found)'], $res->json('data.refused'));
    }

    public function test_bulk_needs_the_delete_permission(): void
    {
        $a = $this->person('27', onTerminal: false);
        $this->admin->forceFill(['can_delete' => false])->save();

        $res = $this->bulk([$a->id], 'everywhere')->assertOk()->assertJsonPath('data.deleted', 0);
        $this->assertSame([$a->name], $res->json('data.refused'));
        $this->assertNotNull(Employee::withoutGlobalScopes()->find($a->id));
    }

    public function test_bulk_refuses_more_than_two_hundred_and_an_unknown_choice(): void
    {
        $this->bulk(range(1, 201), 'everywhere')->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->bulk([1], 'shred')->assertUnprocessable()->assertJsonValidationErrors('mode');
    }
}
