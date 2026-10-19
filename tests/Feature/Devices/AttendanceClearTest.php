<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AttendanceLog;
use App\Models\AttendanceRawLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Devices\AttendanceClearService;
use App\Services\Devices\AttendanceSyncService;
use App\Services\Devices\DeviceUserSyncService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Exception\ProtocolException;
use Promoseven\Biometric\Exception\UnsupportedCommandException;
use Promoseven\Biometric\Value\DeviceUser;
use Tests\TestCase;

/**
 * 2026-10-18, owner: "trigger a wipe of the punch data stored in the device, but don't
 * delete the employees", and "change the access on the device" (admin / normal user).
 *
 * The clear is the destructive one, so most of this is about when it REFUSES: nothing
 * is cleared unless every punch the terminal says it holds was read, decoded and stored.
 * Also here: a punch dated in the future is archived, not imported, and cannot move the
 * watermark — Camelot's misaligned reads stored 1,497 of them (casebook DC-020).
 */
class AttendanceClearTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Device $device;

    private FakeBiometricDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $this->tenant = Tenant::query()->firstOrFail();
        Tenancy::set($this->tenant->id);

        $this->device = Device::create([
            'tenant_id' => $this->tenant->id, 'name' => 'IT Room', 'model' => 'F18',
            'ip_address' => '192.0.2.62', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY,
            'timezone' => '+03:00', 'is_active' => true,
        ]);

        $this->driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $this->driver);
    }

    private function punch(string $localTime, string $pin = '101'): array
    {
        return [
            'user_id' => $pin,
            'timestamp' => Carbon::parse($localTime, '+03:00'),
            'verify_method' => 1,
            'status' => 0,
            'raw' => "raw:{$pin}:{$localTime}",
        ];
    }

    private function clear(): array
    {
        return app(AttendanceClearService::class)->clear($this->device);
    }

    public function test_it_stores_every_punch_then_clears_the_terminal(): void
    {
        $this->driver->punchLog = [$this->punch('2026-10-17 08:00:00'), $this->punch('2026-10-17 17:00:00')];
        $this->device->forceFill(['attendance_watermark' => now()->subHour()])->save();

        $result = $this->clear();

        $this->assertSame(2, AttendanceLog::query()->count());
        $this->assertSame(1, $this->driver->clears);
        $this->assertSame([], $this->driver->punchLog);
        $this->assertSame(2, $result['raw']['cleared']);
        $this->assertStringContainsString('Cleared 2 punches from IT Room', $result['message']);
        // Everything on the terminal is new from here, so nothing may be skipped.
        $this->assertNull($this->device->fresh()->attendance_watermark);
    }

    public function test_punches_already_stored_count_as_saved(): void
    {
        $this->driver->punchLog = [$this->punch('2026-10-17 08:00:00')];
        app(AttendanceSyncService::class)->sync($this->device);

        $this->clear();

        $this->assertSame(1, AttendanceLog::query()->count());
        $this->assertSame(1, $this->driver->clears);
    }

    public function test_it_refuses_while_any_record_would_not_decode(): void
    {
        $this->driver->punchLog = [$this->punch('2026-10-17 08:00:00'), ['raw' => 'deadbeef']];

        try {
            $this->clear();
            $this->fail('Expected a refusal.');
        } catch (ProtocolException $e) {
            $this->assertStringContainsString('1 of them could not be read correctly', $e->getMessage());
            $this->assertStringContainsString('Nothing was cleared', $e->getMessage());
        }

        $this->assertSame(0, $this->driver->clears);
        $this->assertCount(2, $this->driver->punchLog);
    }

    public function test_it_refuses_when_the_terminal_cannot_say_how_many_it_holds(): void
    {
        $this->driver->punchLog = [$this->punch('2026-10-17 08:00:00')];
        $this->driver->reportedRecordCount = null;

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('did not say how many punches it holds');

        try {
            $this->clear();
        } finally {
            $this->assertSame(0, $this->driver->clears);
        }
    }

    public function test_it_refuses_when_the_read_never_matches_the_terminal_count(): void
    {
        // The transfer keeps losing a record: the read never equals what it holds.
        $this->driver->punchLog = [$this->punch('2026-10-17 08:00:00')];
        $this->driver->reportedRecordCount = 2;

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('count never settled');

        try {
            $this->clear();
        } finally {
            $this->assertSame(0, $this->driver->clears);
        }
    }

    public function test_a_c3_panel_is_refused_before_anything_is_read(): void
    {
        $this->driver->capabilityOverrides = [Capability::ATTENDANCE_CLEAR => Capability::NOT_IMPLEMENTED];
        $this->driver->punchLog = [$this->punch('2026-10-17 08:00:00')];

        $this->expectException(UnsupportedCommandException::class);

        try {
            $this->clear();
        } finally {
            $this->assertSame(0, AttendanceLog::query()->count());
        }
    }

    public function test_the_endpoint_queues_one_attempt_and_needs_devices_update(): void
    {
        $this->actingAs(User::withoutGlobalScopes()->firstOrFail(), 'sanctum')
            ->postJson("/api/devices/{$this->device->id}/clear-attendance")
            ->assertOk();

        $command = DeviceCommand::query()->where('command', DeviceCommand::CLEAR_ATTENDANCE)->sole();
        $this->assertSame(1, (int) $command->max_attempts);
    }

    public function test_a_future_dated_punch_is_archived_not_imported_and_does_not_move_the_watermark(): void
    {
        $this->driver->punchLog = [
            $this->punch('2026-10-17 08:00:00'),
            $this->punch('2030-01-30 16:49:04'),
        ];

        $result = app(AttendanceSyncService::class)->sync($this->device);

        $this->assertSame(1, AttendanceLog::query()->count());
        $this->assertSame(1, $result['raw']['counts']['future']);
        $this->assertStringContainsString('1 dated in the future were archived, not imported', $result['message']);
        $this->assertSame('2026-10-17 05:00:00', $this->device->fresh()->attendance_watermark->utc()->format('Y-m-d H:i:s'));

        $archived = AttendanceRawLog::query()->where('skip_reason', AttendanceRawLog::SKIP_FUTURE)->sole();
        $this->assertSame('101', $archived->device_user_id);
        $this->assertFalse((bool) $archived->imported);
    }

    public function test_an_employee_is_made_an_administrator_and_it_is_read_back(): void
    {
        $employee = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Ali', 'employee_code' => '205', 'is_active' => true]);
        $map = EmployeeDeviceMap::create([
            'tenant_id' => $this->tenant->id, 'device_id' => $this->device->id, 'employee_id' => $employee->id,
            'device_user_id' => '205', 'device_privilege' => 0, 'valid_from' => now()->subYear(),
        ]);
        $this->driver->roster = [new DeviceUser(uid: 7, userId: '205', name: 'Ali', privilege: 0, cardNumber: 777, fingerSlots: [6])];

        $result = app(DeviceUserSyncService::class)->setPrivilege($this->device, $employee, true);

        $this->assertSame([['205', true]], $this->driver->privilegeWrites);
        $this->assertSame(14, $map->fresh()->device_privilege);
        $this->assertSame('Ali is now an administrator on IT Room.', $result['message']);
        $this->assertSame(777, $this->driver->roster[0]->cardNumber);
    }

    public function test_privilege_is_refused_for_someone_not_on_the_terminal(): void
    {
        $employee = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Sara', 'employee_code' => '300', 'is_active' => true]);

        $this->expectException(\DomainException::class);

        try {
            app(DeviceUserSyncService::class)->setPrivilege($this->device, $employee, true);
        } finally {
            $this->assertSame([], $this->driver->privilegeWrites);
        }
    }

    public function test_privilege_is_refused_where_the_model_cannot_take_it(): void
    {
        $this->driver->capabilityOverrides = [Capability::USER_PRIVILEGE => Capability::UNKNOWN];
        $employee = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Ali', 'employee_code' => '205', 'is_active' => true]);

        $this->expectException(UnsupportedCommandException::class);

        app(DeviceUserSyncService::class)->setPrivilege($this->device, $employee, true);
    }
}
