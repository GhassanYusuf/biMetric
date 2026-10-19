<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Tenant;
use App\Services\Devices\DeviceMorningRoutine;
use App\Services\Settings\TenantSettings;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Promoseven\Biometric\Capability\Capability;
use Tests\TestCase;

/**
 * The daily morning routine (owner, 2026-10-11; casebook DC-019): at 05:00 COMPANY time,
 * every terminal's clock is set from the server, then the past day's attendance is read.
 *
 * Under test: it fires at the company's 05:00, not the server's; per terminal the clock
 * is queued BEFORE the read; the read asks for yesterday and today in the terminal's
 * own dates; each half has its own switch; a custom time is honoured; a terminal never
 * gets a second command of a kind it already has queued; a clock that does not take
 * fails its command.
 */
class DeviceMorningRoutineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Device $f18;

    private FakeBiometricDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $this->tenant = Tenant::where('slug', 'acme')->firstOrFail();
        Tenancy::set($this->tenant->id);
        app(TenantSettings::class)->set('system.timezone', 'Asia/Bahrain');

        $this->f18 = Device::create([
            'tenant_id' => $this->tenant->id, 'name' => 'IT ROOM', 'model' => 'F18', 'ip_address' => '192.0.2.60',
            'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY, 'adoption_status' => Device::ADOPTED, 'is_active' => true,
        ]);
        Tenancy::clear();

        $this->driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $this->driver);
        Queue::fake();
    }

    private function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    private function setting(string $key, mixed $value): void
    {
        Tenancy::set($this->tenant->id);
        app(TenantSettings::class)->set($key, $value);
        Tenancy::clear();
    }

    /** @return list<string> */
    private function commands(): array
    {
        return DeviceCommand::withoutGlobalScopes()->orderBy('id')->pluck('command')->all();
    }

    public function test_at_five_company_time_the_clock_is_set_then_the_past_day_is_read(): void
    {
        // 05:00 in Bahrain is 02:00 UTC.
        $this->assertSame(['clocks' => 1, 'reads' => 1], app(DeviceMorningRoutine::class)->dispatchDue($this->at('2026-10-12 02:00:00')));

        $this->assertSame([DeviceCommand::SYNC_TIME, DeviceCommand::SYNC_ATTENDANCE], $this->commands(), 'clock first, then the read');
        $read = DeviceCommand::withoutGlobalScopes()->where('command', DeviceCommand::SYNC_ATTENDANCE)->first();
        $this->assertSame(['from' => '2026-10-11', 'to' => '2026-10-12'], $read->payload, 'yesterday and today, in the terminal\'s dates');

        // 05:00 UTC is 08:00 in Bahrain: not due.
        DeviceCommand::query()->withoutGlobalScopes()->delete();
        $this->assertSame(['clocks' => 0, 'reads' => 0], app(DeviceMorningRoutine::class)->dispatchDue($this->at('2026-10-12 05:00:00')));
    }

    public function test_each_half_has_its_own_switch_and_a_custom_time_is_honoured(): void
    {
        $this->setting('devices.daily_read_enabled', false);
        $this->assertSame(['clocks' => 1, 'reads' => 0], app(DeviceMorningRoutine::class)->dispatchDue($this->at('2026-10-12 02:00:00')));

        DeviceCommand::query()->withoutGlobalScopes()->delete();
        $this->setting('devices.daily_read_enabled', true);
        $this->setting('devices.clock_sync_enabled', false);
        $this->assertSame(['clocks' => 0, 'reads' => 1], app(DeviceMorningRoutine::class)->dispatchDue($this->at('2026-10-12 02:00:00')));

        DeviceCommand::query()->withoutGlobalScopes()->delete();
        $this->setting('devices.daily_read_enabled', false);
        $this->assertSame(['clocks' => 0, 'reads' => 0], app(DeviceMorningRoutine::class)->dispatchDue($this->at('2026-10-12 02:00:00')));

        $this->setting('devices.clock_sync_enabled', true);
        $this->setting('devices.clock_sync_schedule', '30 4 * * *');
        $this->assertSame(['clocks' => 1, 'reads' => 0], app(DeviceMorningRoutine::class)->dispatchDue($this->at('2026-10-12 01:30:00')));
    }

    public function test_never_a_second_command_of_a_kind_already_queued_or_one_the_terminal_cannot_do(): void
    {
        foreach ([DeviceCommand::SYNC_TIME, DeviceCommand::SYNC_ATTENDANCE] as $kind) {
            DeviceCommand::create(['tenant_id' => $this->tenant->id, 'device_id' => $this->f18->id, 'command' => $kind,
                'status' => DeviceCommand::STATUS_QUEUED, 'available_at' => now(), 'max_attempts' => 3]);
        }
        $this->assertSame(['clocks' => 0, 'reads' => 0], app(DeviceMorningRoutine::class)->dispatchDue($this->at('2026-10-12 02:00:00')));

        DeviceCommand::query()->withoutGlobalScopes()->delete();
        $this->driver->capabilityOverrides = [Capability::SET_TIME => Capability::NOT_IMPLEMENTED];
        $this->assertSame(['clocks' => 0, 'reads' => 1], app(DeviceMorningRoutine::class)->dispatchDue($this->at('2026-10-12 02:00:00')));
    }

    public function test_the_sync_command_fails_when_the_clock_does_not_take(): void
    {
        Tenancy::set($this->tenant->id);
        $this->driver->clock = new \DateTimeImmutable('2026-01-01 00:00:00');
        $this->driver->ignoreClockWrites = true;
        $command = DeviceCommand::create(['tenant_id' => $this->tenant->id, 'device_id' => $this->f18->id, 'command' => DeviceCommand::SYNC_TIME,
            'status' => DeviceCommand::STATUS_RUNNING, 'available_at' => now(), 'max_attempts' => 1]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('accepted the clock but reads back');
        app(\App\Services\Devices\DeviceCommandHandler::class)->handle($command);
    }
}
