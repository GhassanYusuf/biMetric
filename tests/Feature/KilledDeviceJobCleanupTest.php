<?php

namespace Tests\Feature;

use App\Jobs\ProcessDeviceCommand;
use App\Models\AttendanceSyncRun;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Tenant;
use App\Services\Devices\DeviceDriverManager;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Casebook DC-041 (2026-11-09): the queue killed two full reads of Gems Main Office at the job
 * limit; each left the terminal locked for 30 minutes and its sync run "running" for ever,
 * because a killed job never reaches its own finally blocks. ProcessDeviceCommand::failed()
 * now lets go of both — and only of a lock its own process holds.
 */
class KilledDeviceJobCleanupTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    private DeviceCommand $command;

    private AttendanceSyncRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        Tenancy::set($tenant->id);
        $this->device = Device::create(['name' => 'Gems Main Office', 'ip_address' => '192.0.2.64', 'port' => 4370, 'protocol' => 'zkteco']);
        $this->command = DeviceCommand::create([
            'device_id' => $this->device->id, 'command' => DeviceCommand::SYNC_ATTENDANCE, 'payload' => ['full' => true],
            'status' => DeviceCommand::STATUS_RUNNING, 'max_attempts' => 3,
        ]);
        $this->run = AttendanceSyncRun::create([
            'device_id' => $this->device->id, 'device_command_id' => $this->command->id, 'mode' => 'full',
            'status' => 'running', 'started_at' => now(),
        ]);
        Tenancy::clear();
        DeviceDriverManager::$lockWaitSeconds = 0;
    }

    protected function tearDown(): void
    {
        DeviceDriverManager::$lockWaitSeconds = 60;
        parent::tearDown();
    }

    private function inTerminalSession(\Closure $work): void
    {
        $manager = app(DeviceDriverManager::class);
        $device = $this->device;
        (fn () => $this->exclusive($device, $work))->call($manager);
    }

    private function lockIsFree(): bool
    {
        $probe = Cache::lock("device-session:{$this->device->id}", 5);
        $free = $probe->get();
        if ($free) {
            $probe->release();
        }

        return (bool) $free;
    }

    public function test_a_job_killed_mid_read_frees_the_terminal_and_closes_its_read(): void
    {
        $freedInside = null;
        // The queue calls failed() from the timeout handler while the read still holds the lock.
        $this->inTerminalSession(function () use (&$freedInside) {
            $this->assertFalse($this->lockIsFree());
            (new ProcessDeviceCommand($this->command->id))->failed(new TimeoutExceededException('App\Jobs\ProcessDeviceCommand has timed out.'));
            $freedInside = $this->lockIsFree();
        });

        $this->assertTrue($freedInside, 'The terminal stayed locked after the job was killed.');
        $run = AttendanceSyncRun::withoutGlobalScopes()->find($this->run->id);
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(DeviceCommand::STATUS_FAILED, DeviceCommand::withoutGlobalScopes()->find($this->command->id)->status);
    }

    public function test_a_lock_held_by_another_job_is_left_alone(): void
    {
        $other = Cache::lock("device-session:{$this->device->id}", 60);
        $this->assertTrue($other->get());

        (new ProcessDeviceCommand($this->command->id))->failed(new TimeoutExceededException('timed out'));

        $this->assertFalse($this->lockIsFree(), 'Another job\'s terminal lock was released.');
        $other->release();
    }

    public function test_the_job_limit_fits_a_ten_minute_read_and_stays_under_the_locks(): void
    {
        $job = new ProcessDeviceCommand($this->command->id);
        $this->assertGreaterThanOrEqual(900, $job->timeout);
        $this->assertLessThan(1800, $job->timeout); // worker --timeout and LOCK_TTL_SECONDS
    }
}
