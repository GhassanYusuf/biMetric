<?php

namespace Tests\Feature;

use App\Exceptions\DeviceBusyException;
use App\Models\Device;
use App\Models\Tenant;
use App\Services\Devices\DeviceDriverManager;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * One conversation per terminal at a time (2026-10-12): every call that reaches a
 * terminal waits for that terminal's lock, so two operators — or an operator and a
 * queued job — line up instead of interleaving one stateful session (rule 11).
 * No device is contacted: a busy terminal refuses before anything is sent.
 */
class DeviceSessionQueueTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    private DeviceDriverManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        Tenancy::set($tenant->id);
        // A TEST-NET address: nothing answers there if a call ever got through.
        $this->device = Device::create(['name' => 'Entrance', 'ip_address' => '192.0.2.1', 'port' => 4370, 'protocol' => 'zkteco']);
        Tenancy::clear();

        $this->manager = app(DeviceDriverManager::class);
        DeviceDriverManager::$lockWaitSeconds = 0;
    }

    protected function tearDown(): void
    {
        DeviceDriverManager::$lockWaitSeconds = 60;
        parent::tearDown();
    }

    /** Runs the private queue directly, so the test needs no terminal. */
    private function exclusive(Device $device, \Closure $work): mixed
    {
        return (fn () => $this->exclusive($device, $work))->call($this->manager);
    }

    public function test_a_terminal_in_use_elsewhere_is_reported_busy_and_nothing_is_sent(): void
    {
        $held = Cache::lock("device-session:{$this->device->id}", 60);
        $this->assertTrue($held->get());

        try {
            $this->manager->ping($this->device);
            $this->fail('a busy terminal must refuse');
        } catch (DeviceBusyException $e) {
            $this->assertStringContainsString('Entrance is busy', $e->getMessage());
            $this->assertTrue($e->isRetryable(), 'a queued command retries a busy terminal');
        } finally {
            $held->release();
        }
    }

    public function test_the_lock_is_freed_afterwards_even_when_the_work_throws(): void
    {
        $this->assertSame('done', $this->exclusive($this->device, fn () => 'done'));

        try {
            $this->exclusive($this->device, fn () => throw new \RuntimeException('device fell over'));
        } catch (\RuntimeException) {
        }

        $next = Cache::lock("device-session:{$this->device->id}", 60);
        $this->assertTrue($next->get(), 'the terminal is free for the next caller');
        $next->release();
    }

    public function test_a_call_inside_a_held_session_on_the_same_terminal_does_not_wait_for_itself(): void
    {
        $inner = $this->exclusive($this->device, fn () => $this->exclusive($this->device, fn () => 'nested'));

        $this->assertSame('nested', $inner);
    }

    public function test_different_terminals_do_not_wait_for_each_other(): void
    {
        Tenancy::set($this->device->tenant_id);
        $other = Device::create(['name' => 'Glass Door', 'ip_address' => '192.0.2.2', 'port' => 4370, 'protocol' => 'zkteco']);
        Tenancy::clear();

        $held = Cache::lock("device-session:{$this->device->id}", 60);
        $held->get();
        try {
            $this->assertSame('ok', $this->exclusive($other, fn () => 'ok'));
        } finally {
            $held->release();
        }
    }
}
