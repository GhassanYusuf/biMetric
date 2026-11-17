<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Employees\AccessExpiry;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ending terminal access on the day (owner, 2026-11-17: "if I keep a person as resigned on 29 Nov,
 * will the access be removed on 29 Nov?" — "yes build both"): a future resignation date, and the
 * end of a valid period. employees:end-access → AccessExpiry.
 */
class AccessExpiryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Device $door;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(now()->setTimezone(config('attendance.timezone'))->setTime(12, 0)); // midday at the office
        Tenancy::clear();
        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);
        Tenancy::set($this->tenant->id);
        $this->door = Device::create(['name' => 'Gems WorkShop', 'model' => 'F18', 'ip_address' => '192.0.2.64', 'port' => 4371, 'protocol' => Device::PROTOCOL_BINARY, 'adoption_status' => Device::ADOPTED, 'is_active' => true]);
        Tenancy::clear();
    }

    private function person(string $code, array $attrs = [], ?Device $on = null): Employee
    {
        Tenancy::set($this->tenant->id);
        $e = Employee::create(['name' => "Worker {$code}", 'employee_code' => $code, 'is_active' => true] + $attrs);
        EmployeeDeviceMap::create(['device_id' => ($on ?? $this->door)->id, 'employee_id' => $e->id, 'device_user_id' => $code, 'valid_from' => now()->subYear()]);
        Tenancy::clear();

        return $e;
    }

    private function day(int $offset): string
    {
        return now(config('attendance.timezone'))->addDays($offset)->toDateString();
    }

    private function endAccess(): array
    {
        Tenancy::set($this->tenant->id);
        try {
            return app(AccessExpiry::class)->runForTenant();
        } finally {
            Tenancy::clear();
        }
    }

    private function queuedFor(Employee $e): bool
    {
        return DeviceCommand::withoutGlobalScopes()->where('command', DeviceCommand::REMOVE_USERS)->get()
            ->contains(fn ($c) => collect($c->payload['employees'])->contains(fn ($r) => (int) $r['id'] === $e->id));
    }

    public function test_a_future_resignation_keeps_them_active_and_on_the_terminals_until_the_day(): void
    {
        $e = $this->person('2296', ['resigned_at' => $this->day(12)]);
        $fresh = Employee::withoutGlobalScopes()->find($e->id);

        $this->assertTrue($fresh->is_active);
        $this->assertFalse($fresh->isResigned());
        $this->assertTrue($fresh->resignsLater());
        $this->assertNull($fresh->accessEndedReason());
        $this->endAccess();
        $this->assertFalse($this->queuedFor($e));
        // Still current staff in the roster.
        Tenancy::set($this->tenant->id);
        $current = \App\Services\Employees\EmployeeRosterQuery::build(Request::create('/', 'GET', ['resigned' => '0']))->pluck('id')->all();
        $this->assertContains($e->id, $current);
    }

    public function test_on_the_day_they_are_made_inactive_and_taken_off_the_terminals(): void
    {
        $e = $this->person('2352', ['resigned_at' => $this->day(12)]);
        $this->travel(12)->days();

        $r = $this->endAccess();

        $this->assertSame(1, $r['resigned']);
        $this->assertFalse((bool) Employee::withoutGlobalScopes()->find($e->id)->is_active);
        $this->assertTrue($this->queuedFor($e), 'a batch removal is queued for their terminal');
        $this->assertNotNull(Employee::withoutGlobalScopes()->find($e->id), 'the record is kept');
    }

    public function test_a_valid_period_ends_the_day_after_its_end_date(): void
    {
        $endsToday = $this->person('2401', ['valid_period_enabled' => true, 'access_valid_from' => $this->day(-30), 'access_valid_to' => $this->day(0)]);
        $endedYesterday = $this->person('2402', ['valid_period_enabled' => true, 'access_valid_from' => $this->day(-30), 'access_valid_to' => $this->day(-1)]);

        $this->endAccess();

        $this->assertFalse($this->queuedFor($endsToday), 'valid through today');
        $this->assertTrue($this->queuedFor($endedYesterday));
        $this->assertTrue((bool) Employee::withoutGlobalScopes()->find($endedYesterday->id)->is_active, 'not resigned: the record is unchanged');
        $this->assertStringContainsString('valid period', Employee::withoutGlobalScopes()->find($endedYesterday->id)->accessEndedReason());
    }

    public function test_someone_already_in_a_queued_removal_is_left_to_it_and_a_second_run_queues_nothing_new(): void
    {
        $this->person('2089', ['resigned_at' => $this->day(-1)]);

        $this->endAccess();
        $this->endAccess();

        $this->assertSame(1, DeviceCommand::withoutGlobalScopes()->where('command', DeviceCommand::REMOVE_USERS)->count());
    }

    public function test_a_terminal_that_cannot_be_written_is_reported_to_whoever_may_edit_employees(): void
    {
        Tenancy::set($this->tenant->id);
        $admin = User::withoutGlobalScopes()->create(['name' => 'A', 'email' => 'a@acme.test', 'password' => 'secret-pass-1', 'tenant_id' => $this->tenant->id, 'is_active' => true, 'email_verified_at' => now()]);
        $admin->syncRoles(['tenant-admin']);
        $locked = Device::create(['name' => 'Unadopted', 'ip_address' => '192.0.2.70', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'is_active' => true]);
        Tenancy::clear();
        $this->person('2500', ['resigned_at' => $this->day(-1)], $locked);

        $r = $this->endAccess();

        $this->assertArrayHasKey('Unadopted', $r['skipped']);
        $this->assertTrue(Notification::withoutGlobalScopes()->where('user_id', $admin->id)->where('type', 'employees.access_not_ended')->exists());
    }

    public function test_a_person_whose_access_ended_is_not_pushed_back_by_hand(): void
    {
        $e = $this->person('2600', ['valid_period_enabled' => true, 'access_valid_from' => $this->day(-30), 'access_valid_to' => $this->day(-2)]);
        Tenancy::set($this->tenant->id);
        $admin = User::withoutGlobalScopes()->create(['name' => 'B', 'email' => 'b@acme.test', 'password' => 'secret-pass-1', 'tenant_id' => $this->tenant->id, 'is_active' => true, 'email_verified_at' => now()]);
        $admin->syncRoles(['tenant-admin']);
        Tenancy::clear();

        $this->actingAs($admin, 'sanctum')->postJson("/api/employees/{$e->id}/devices/{$this->door->id}/push")
            ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'valid period'));
    }
}
