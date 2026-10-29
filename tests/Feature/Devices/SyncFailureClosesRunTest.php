<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AttendanceSyncRun;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Tenant;
use App\Services\Devices\AttendanceSyncService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Casebook DC-036 (2026-10-29): Camelot's full read decoded junk, the database refused the
 * import, and the failure could not even be recorded because its message quoted bytes that
 * are not UTF-8. The command read "job lost" and the sync run stayed `running` / `importing`
 * for ever, so the operator's screen never stopped loading.
 *
 * Two promises are pinned here: ANY failure closes its run, not only a device failure; and a
 * failure is always recordable, whatever bytes its message carries.
 */
class SyncFailureClosesRunTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $tenant = Tenant::query()->firstOrFail();
        Tenancy::set($tenant->id);

        $this->device = Device::create([
            'tenant_id' => $tenant->id,
            'name' => 'Camelot',
            'ip_address' => '192.0.2.60',
            'port' => 4370,
            'protocol' => Device::PROTOCOL_BINARY,
            'timezone' => '+03:00',
            'is_active' => true,
        ]);
    }

    public function test_a_failure_that_is_not_a_device_error_still_closes_the_run(): void
    {
        $this->app->instance(BiometricDeviceDriver::class, new class extends FakeBiometricDriver
        {
            public function readAttendance(Device $device, ?callable $onProgress = null): array
            {
                throw new \RuntimeException("insert refused: user id Fl!\x01\x9c\xff");
            }
        });

        try {
            app(AttendanceSyncService::class)->sync($this->device, full: true);
            $this->fail('The failure must still reach the caller.');
        } catch (\RuntimeException) {
        }

        $run = AttendanceSyncRun::query()->sole();
        $this->assertSame(AttendanceSyncRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->progress(), 'a closed run shows no in-flight progress');
        $this->assertSame('The read could not be stored. The details are in the log.', $run->message);
        $this->assertSame(\RuntimeException::class, $run->error['exception']);
        $this->assertTrue(mb_check_encoding($run->error['message'], 'UTF-8'));
        $this->assertSame('error', $this->device->fresh()->last_sync_status);
    }

    public function test_a_command_failure_quoting_terminal_bytes_is_recorded(): void
    {
        $command = DeviceCommand::create([
            'tenant_id' => $this->device->tenant_id,
            'device_id' => $this->device->id,
            'command' => 'sync_attendance',
            'payload' => ['full' => true],
            'status' => DeviceCommand::STATUS_RUNNING,
            'attempts' => 1,
            'max_attempts' => 1,
        ]);

        $command->markFailed("Read failed near \x9cERMISSION", [
            'exception' => 'Illuminate\\Database\\QueryException',
            'message' => "translating string for input param 120: \xff\xfe",
            'context' => ['bytes' => "\x01\x9c"],
        ]);

        $command->refresh();
        $this->assertSame(DeviceCommand::STATUS_FAILED, $command->status);
        $this->assertSame('Read failed near ?ERMISSION', $command->message);
        $this->assertTrue(mb_check_encoding($command->error['message'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($command->error['context']['bytes'], 'UTF-8'));
    }
}
