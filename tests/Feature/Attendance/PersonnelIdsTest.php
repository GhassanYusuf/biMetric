<?php

namespace Tests\Feature\Attendance;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Services\Attendance\PunchAttribution;
use App\Services\Devices\AttendanceSyncService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Devices\FakeBiometricDriver;
use Tests\TestCase;

/**
 * attendance:personnel-ids (owner, 2026-11-05): punches from September onwards carry the
 * person's CPR as device_user_id, so it shows in the HR system. The terminal's own number
 * is kept, re-reading the terminal imports nothing twice, re-linking still works, and the
 * change can be undone.
 */
class PersonnelIdsTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    private Employee $akhil;

    private PersonnelIdsTerminal $driver;

    protected function setUp(): void
    {
        parent::setUp();
        // Backups go to a fake disk: a test must never write into the live storage/app.
        \Illuminate\Support\Facades\Storage::fake('local');
        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();
        $tenant = Tenant::query()->firstOrFail();
        Tenancy::set($tenant->id);

        $company = Company::create(['name' => 'PROMOSEVEN']);
        $catering = Department::create(['name' => 'Catering', 'company_id' => $company->id]);
        $this->device = Device::create([
            'name' => 'Catering', 'ip_address' => '192.0.2.120', 'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY, 'timezone' => '+03:00', 'is_active' => true,
        ]);
        $this->akhil = Employee::create(['name' => 'Akhil', 'employee_code' => '921257970', 'company_id' => $company->id, 'department_id' => $catering->id, 'is_active' => true]);

        // 701 until he was moved to his CPR on 2026-10-25, as on the real terminal.
        $moved = Carbon::parse('2026-10-25 13:53:27', 'UTC');
        EmployeeDeviceMap::create(['device_id' => $this->device->id, 'employee_id' => $this->akhil->id, 'device_user_id' => '701', 'valid_to' => $moved, 'closed_reason' => 'Removed from device']);
        EmployeeDeviceMap::create(['device_id' => $this->device->id, 'employee_id' => $this->akhil->id, 'device_user_id' => '921257970', 'valid_from' => $moved]);

        $this->driver = new PersonnelIdsTerminal;
        $this->driver->attendance = [
            $this->punch('2026-08-20 08:00:00', '701'),   // before September: stays 701
            $this->punch('2026-09-10 08:00:00', '701'),
            $this->punch('2026-10-01 17:00:00', '701'),
            $this->punch('2026-11-01 08:00:00', '921257970'),
        ];
        $this->app->instance(BiometricDeviceDriver::class, $this->driver);
        $this->app->make(AttendanceSyncService::class)->sync($this->device, full: true);
        Tenancy::clear();
    }

    private function punch(string $local, string $pin): array
    {
        return ['user_id' => $pin, 'timestamp' => Carbon::parse($local, '+03:00'), 'verify_method' => 1, 'status' => 0, 'raw' => "raw:{$local}"];
    }

    private function ids(): array
    {
        return AttendanceLog::withoutGlobalScopes()->orderBy('punched_at')->get()
            ->map(fn ($l) => [$l->device_user_id, $l->original_device_user_id])->all();
    }

    public function test_without_apply_nothing_changes(): void
    {
        $this->artisan('attendance:personnel-ids', ['--company' => 'PROMOSEVEN', '--department' => 'Catering'])
            ->expectsOutputToContain('2 punch(es) to change.')
            ->assertSuccessful();

        $this->assertSame([['701', null], ['701', null], ['701', null], ['921257970', null]], $this->ids());
    }

    public function test_apply_puts_the_cpr_on_september_onwards_and_keeps_the_terminals_number(): void
    {
        $this->assertSame(4, AttendanceLog::withoutGlobalScopes()->whereNotNull('employee_id')->count());

        $this->artisan('attendance:personnel-ids', ['--company' => 'PROMOSEVEN', '--department' => 'Catering', '--apply' => true])
            ->expectsOutputToContain('2 punch(es) changed and checked.')
            ->assertSuccessful();

        $this->assertSame([['701', null], ['921257970', '701'], ['921257970', '701'], ['921257970', null]], $this->ids());
        // Run again: nothing left to change.
        $this->artisan('attendance:personnel-ids', ['--company' => 'PROMOSEVEN', '--department' => 'Catering'])
            ->expectsOutputToContain('0 punch(es) to change.');
    }

    public function test_reading_the_terminal_again_imports_nothing_twice(): void
    {
        $this->artisan('attendance:personnel-ids', ['--department' => 'Catering', '--apply' => true])->assertSuccessful();

        Tenancy::set($this->device->tenant_id);
        $this->app->make(AttendanceSyncService::class)->sync($this->device->fresh(), full: true);

        $this->assertSame(4, AttendanceLog::withoutGlobalScopes()->count());
        $this->assertSame([['701', null], ['921257970', '701'], ['921257970', '701'], ['921257970', null]], $this->ids());
    }

    public function test_relinking_reads_the_terminals_number_and_keeps_the_person(): void
    {
        $this->artisan('attendance:personnel-ids', ['--department' => 'Catering', '--apply' => true])->assertSuccessful();

        $counts = $this->app->make(PunchAttribution::class)->relink($this->device->fresh());

        $this->assertSame(0, $counts['cleared']);
        $this->assertSame(4, AttendanceLog::withoutGlobalScopes()->where('employee_id', $this->akhil->id)->count());
        $this->assertSame([['701', null], ['921257970', '701'], ['921257970', '701'], ['921257970', null]], $this->ids());
    }

    public function test_a_punch_that_changes_hands_gets_its_terminal_number_back(): void
    {
        $this->artisan('attendance:personnel-ids', ['--department' => 'Catering', '--apply' => true])->assertSuccessful();

        // 701's history withdrawn (say, it was somebody else): no mapping supports Akhil any more.
        EmployeeDeviceMap::withoutGlobalScopes()->where('device_user_id', '701')->delete();
        $counts = $this->app->make(PunchAttribution::class)->relink($this->device->fresh(), '701');

        $this->assertSame(3, $counts['cleared']);
        $this->assertSame([['701', null], ['701', null], ['701', null], ['921257970', null]], $this->ids());
    }

    public function test_undo_puts_the_terminals_numbers_back(): void
    {
        $this->artisan('attendance:personnel-ids', ['--department' => 'Catering', '--apply' => true])->assertSuccessful();
        $file = collect(glob(\Illuminate\Support\Facades\Storage::disk('local')->path('personnel-ids/*.json')))->sort()->last();

        $this->artisan('attendance:personnel-ids', ['--undo' => $file])
            ->expectsOutputToContain('2 of 2 punch(es)')
            ->assertSuccessful();

        $this->assertSame([['701', null], ['701', null], ['701', null], ['921257970', null]], $this->ids());
    }

    public function test_an_employee_without_a_cpr_is_left_alone_and_named(): void
    {
        $this->akhil->forceFill(['employee_code' => 'JJ-14'])->saveQuietly();

        $this->artisan('attendance:personnel-ids', ['--department' => 'Catering', '--apply' => true])
            ->expectsOutputToContain('have no CPR as Personnel ID')
            ->assertSuccessful();

        $this->assertSame([['701', null], ['701', null], ['701', null], ['921257970', null]], $this->ids());
    }

    // --- Attendance → Personnel IDs on punches (the same rules, from the page) ---

    private function owner(): \App\Models\User
    {
        return \App\Models\User::withoutGlobalScopes()->where('tenant_id', $this->device->tenant_id)->firstOrFail();
    }

    private function companyId(): int
    {
        return (int) Company::withoutGlobalScopes()->where('name', 'PROMOSEVEN')->value('id');
    }

    public function test_the_page_previews_applies_lists_and_undoes(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/attendance/personnel-ids/preview?company_id='.$this->companyId().'&from=2026-09-01')
            ->assertOk()
            ->assertJsonPath('data.changes', 2)
            ->assertJsonPath('data.people.0.recorded_as', ['701']);
        $this->assertSame([['701', null], ['701', null], ['701', null], ['921257970', null]], $this->ids());

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/attendance/personnel-ids', ['company_id' => $this->companyId(), 'from' => '2026-09-01'])
            ->assertOk()
            ->assertJsonPath('data.changed', 2);
        $this->assertSame([['701', null], ['921257970', '701'], ['921257970', '701'], ['921257970', null]], $this->ids());

        $runs = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/attendance/personnel-ids/runs')
            ->assertOk()->assertJsonPath('data.0.punches', 2)->assertJsonPath('data.0.undone', false)->json('data');

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/attendance/personnel-ids/runs/'.$runs[0]['id'].'/undo')
            ->assertOk()->assertJsonPath('data.restored', 2);
        $this->assertSame([['701', null], ['701', null], ['701', null], ['921257970', null]], $this->ids());

        $this->actingAs($this->owner(), 'sanctum')->getJson('/api/attendance/personnel-ids/runs')->assertJsonPath('data.0.undone', true);
        $this->actingAs($this->owner(), 'sanctum')->postJson('/api/attendance/personnel-ids/runs/'.$runs[0]['id'].'/undo')->assertStatus(409);
    }

    public function test_the_page_needs_the_system_personnel_ids_permission(): void
    {
        // Its own permission since 2026-11-26 (System → Personnel IDs): changing employees is not enough.
        $viewer = $this->owner();
        Tenancy::set($this->device->tenant_id); // roles are per tenant
        $viewer->syncRoles(['operator']);
        $viewer->givePermissionTo(['employees.update', 'attendance.view']);
        Tenancy::clear();

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/attendance/personnel-ids/preview?company_id='.$this->companyId().'&from=2026-09-01')
            ->assertForbidden();
        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/attendance/personnel-ids', ['company_id' => $this->companyId(), 'from' => '2026-09-01'])
            ->assertForbidden();
        $this->assertSame([['701', null], ['701', null], ['701', null], ['921257970', null]], $this->ids());

        Tenancy::set($this->device->tenant_id);
        $viewer->givePermissionTo('system.personnel-ids');
        Tenancy::clear();
        $this->app['auth']->forgetGuards();
        $this->actingAs($viewer->fresh(), 'sanctum')
            ->getJson('/api/attendance/personnel-ids/preview?company_id='.$this->companyId().'&from=2026-09-01')
            ->assertOk();
    }

    public function test_a_department_of_another_company_is_refused(): void
    {
        Tenancy::set($this->device->tenant_id);
        $other = Company::create(['name' => 'Camelot']);
        $foreign = Department::create(['name' => 'Kitchen', 'company_id' => $other->id]);
        Tenancy::clear();

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/attendance/personnel-ids/preview?company_id='.$this->companyId().'&department_id='.$foreign->id.'&from=2026-09-01')
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
    }
}

/** A terminal that hands over whatever the test put in it: the whole table, always. */
class PersonnelIdsTerminal extends FakeBiometricDriver
{
    /** @var list<array<string,mixed>> */
    public array $attendance = [];

    public function readAttendance(Device $device, ?callable $onProgress = null): array
    {
        return $this->attendance;
    }
}
