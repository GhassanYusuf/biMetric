<?php

namespace Tests\Feature\Devices;

use App\Jobs\CheckAllDeviceHealth;
use App\Jobs\ProcessDeviceCommand;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The 5-minute health check (casebook DC-024). From the day cron first ran it on
 * 2026-10-18 it threw on every terminal: `->tap()` on the new command reached a query
 * Builder, so the row was saved `queued` but never handed to the queue, and every later
 * run skipped the terminal because a check was "already queued".
 *
 * Under test: each active ZKTeco terminal gets one queued check AND a queue job for it;
 * a terminal with a check still pending gets no second one.
 */
class CheckAllDeviceHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_terminal_gets_a_check_and_the_queue_gets_a_job_for_it(): void
    {
        Queue::fake();
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        Tenancy::set($tenant->id);
        $a = Device::create(['name' => 'IT ROOM', 'ip_address' => '192.0.2.62', 'protocol' => Device::PROTOCOL_BINARY, 'model' => 'F18', 'comm_key' => '1', 'is_active' => true]);
        $b = Device::create(['name' => 'Camelot', 'ip_address' => '192.0.2.60', 'protocol' => Device::PROTOCOL_BINARY, 'model' => 'F18', 'comm_key' => '1', 'is_active' => true]);
        Tenancy::clear();

        (new CheckAllDeviceHealth)->handle();

        $commands = DeviceCommand::withoutGlobalScopes()->where('command', DeviceCommand::HEALTH_CHECK)->get();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $commands->pluck('device_id')->all());
        Queue::assertPushed(ProcessDeviceCommand::class, 2);

        // Both still pending: the next run adds nothing.
        (new CheckAllDeviceHealth)->handle();
        $this->assertSame(2, DeviceCommand::withoutGlobalScopes()->where('command', DeviceCommand::HEALTH_CHECK)->count());
        Queue::assertPushed(ProcessDeviceCommand::class, 2);
    }
}
