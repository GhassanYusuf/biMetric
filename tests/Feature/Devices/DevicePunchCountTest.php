<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Devices\AttendanceSyncService;
use App\Services\Devices\DeviceHealthService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Device punches" next to "System punches" on the devices list (owner, 2026-10-21).
 * After a clear or a wipe the system still holds what it saved, so one number alone
 * read as "the wipe did not work". The terminal's own count is stored from the health
 * check and from every read; never counted stays null, not 0 (rule 10).
 */
class DevicePunchCountTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    private FakeBiometricDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $tenant = Tenant::query()->firstOrFail();
        Tenancy::set($tenant->id);

        $this->device = Device::create([
            'tenant_id' => $tenant->id, 'name' => 'IT-Office', 'model' => 'F18',
            'ip_address' => '192.0.2.64', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY,
            'timezone' => '+03:00', 'is_active' => true,
        ]);

        $this->driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $this->driver);
    }

    private function punch(string $localTime): array
    {
        return ['user_id' => '101', 'timestamp' => Carbon::parse($localTime, '+03:00'), 'verify_method' => 1, 'status' => 0, 'raw' => "raw:{$localTime}"];
    }

    public function test_the_health_check_stores_the_terminals_own_count(): void
    {
        $this->driver->punchLog = [$this->punch('2026-10-21 08:00:00'), $this->punch('2026-10-21 09:00:00')];

        app(DeviceHealthService::class)->check($this->device);

        $this->device->refresh();
        $this->assertSame(2, $this->device->terminal_punch_count);
        $this->assertNotNull($this->device->terminal_punch_counted_at);
        // Counted, not imported: the system still holds none of them.
        $this->assertSame(0, AttendanceLog::count());
    }

    /** A terminal that cannot say leaves the last real count alone — never a 0 nobody read. */
    public function test_a_terminal_that_cannot_count_keeps_the_last_count(): void
    {
        $this->device->forceFill(['terminal_punch_count' => 7, 'terminal_punch_counted_at' => now()->subHour()])->save();
        $this->driver->reportedRecordCount = null;

        app(DeviceHealthService::class)->check($this->device);

        $this->assertSame(7, $this->device->fresh()->terminal_punch_count);
    }

    public function test_a_read_stores_how_many_the_terminal_sent(): void
    {
        $this->driver->punchLog = [$this->punch('2026-10-21 08:00:00'), $this->punch('2026-10-21 09:00:00'), $this->punch('2026-10-21 10:00:00')];

        app(AttendanceSyncService::class)->sync($this->device, full: true);

        $this->assertSame(3, $this->device->fresh()->terminal_punch_count);
    }

    public function test_the_devices_list_gets_both_counts(): void
    {
        $this->device->forceFill(['terminal_punch_count' => 0, 'terminal_punch_counted_at' => now()])->save();
        AttendanceLog::create(['tenant_id' => $this->device->tenant_id, 'device_id' => $this->device->id, 'device_user_id' => '1', 'punched_at' => '2022-11-18 09:39:15', 'status' => 0, 'verify_method' => 1]);

        $row = collect($this->actingAs(User::withoutGlobalScopes()->firstOrFail(), 'sanctum')->getJson('/api/devices')->assertOk()->json('data'))
            ->firstWhere('id', $this->device->id);

        $this->assertSame(0, $row['terminal_punch_count']);
        $this->assertSame(1, $row['attendance_logs_count']);
    }
}
