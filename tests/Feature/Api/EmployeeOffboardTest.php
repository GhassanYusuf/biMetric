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
}
