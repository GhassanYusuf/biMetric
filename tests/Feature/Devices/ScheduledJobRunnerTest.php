<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Tenant;
use App\Models\ScheduledJob;
use App\Services\Devices\ScheduledJobRunner;
use App\Services\Settings\TenantSettings;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Promoseven\Biometric\Capability\Capability;
use Tests\TestCase;

/**
 * The Cron jobs runner (owner, 2026-10-20; was the 2026-10-11 morning routine, casebook
 * DC-019, which is now the job "set clocks, then read the past day" at 05:00 and 10:00).
 *
 * Under test: a job fires at the COMPANY's time, not the server's; per terminal the
 * clock is queued BEFORE the read; the read asks for yesterday and today in the
 * terminal's own dates; every time in the expression is a run; a paused job, another
 * company's job and a terminal the job does not name are left alone; a terminal never
 * gets a second command of a kind it already has queued, nor one it cannot do; each
 * action queues what it says; a clock that does not take fails its command.
 */
class ScheduledJobRunnerTest extends TestCase
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

    private function job(string $action, string $schedule, array $extra = []): ScheduledJob
    {
        return ScheduledJob::create(['tenant_id' => $this->tenant->id, 'name' => $action, 'action' => $action, 'schedule' => $schedule] + $extra);
    }

    private function due(string $utc): array
    {
        return app(ScheduledJobRunner::class)->dispatchDue($this->at($utc));
    }

    private function reset(): void
    {
        DeviceCommand::query()->withoutGlobalScopes()->delete();
    }

    /** @return list<string> */
    private function commands(): array
    {
        return DeviceCommand::withoutGlobalScopes()->orderBy('id')->pluck('command')->all();
    }

    public function test_clock_then_read_fires_at_company_time_with_the_clock_first(): void
    {
        $job = $this->job('clock_and_read', '0 5,10 * * *');

        // 05:00 in Bahrain is 02:00 UTC.
        $this->assertSame([DeviceCommand::SYNC_TIME => 1, DeviceCommand::SYNC_ATTENDANCE => 1], $this->due('2026-10-12 02:00:00'));
        $this->assertSame([DeviceCommand::SYNC_TIME, DeviceCommand::SYNC_ATTENDANCE], $this->commands(), 'clock first, then the read');
        $read = DeviceCommand::withoutGlobalScopes()->where('command', DeviceCommand::SYNC_ATTENDANCE)->first();
        $this->assertSame(['from' => '2026-10-11', 'to' => '2026-10-12'], $read->payload, 'yesterday and today, in the terminal\'s dates');
        $this->assertNotNull($job->fresh()->last_run_at);
        $this->assertStringContainsString('Queued 2 commands on 1 terminal', $job->fresh()->last_result);

        // 10:00 Bahrain (07:00 UTC) is the second run, clock first again (owner, 2026-10-20).
        $this->reset();
        $this->assertSame([DeviceCommand::SYNC_TIME => 1, DeviceCommand::SYNC_ATTENDANCE => 1], $this->due('2026-10-12 07:00:00'));
        $this->assertSame([DeviceCommand::SYNC_TIME, DeviceCommand::SYNC_ATTENDANCE], $this->commands());

        // 05:00 UTC is 08:00 in Bahrain: not due.
        $this->reset();
        $this->assertSame([], $this->due('2026-10-12 05:00:00'));
    }

    public function test_each_action_queues_what_it_says(): void
    {
        $expect = [
            'read_past_day' => [DeviceCommand::SYNC_ATTENDANCE],
            'read_all_new' => [DeviceCommand::SYNC_ATTENDANCE],
            'set_clocks' => [DeviceCommand::SYNC_TIME],
            'copy_credentials' => [DeviceCommand::SYNC_ENROLLMENT],
        ];
        foreach ($expect as $action => $commands) {
            ScheduledJob::query()->withoutGlobalScopes()->delete();
            $this->reset();
            $this->job($action, '0 5 * * *');
            $this->due('2026-10-12 02:00:00');
            $this->assertSame($commands, $this->commands(), $action);
        }

        // A full read has no range: it reads from the watermark.
        $this->assertSame([], DeviceCommand::withoutGlobalScopes()->first()->payload ?? []);
    }

    public function test_paused_jobs_other_companies_and_unnamed_terminals_are_left_alone(): void
    {
        $this->job('set_clocks', '0 5 * * *', ['is_enabled' => false]);
        $this->assertSame([], $this->due('2026-10-12 02:00:00'));

        $other = Tenant::where('slug', '!=', 'acme')->first() ?? Tenant::create(['name' => 'Other', 'slug' => 'other']);
        ScheduledJob::create(['tenant_id' => $other->id, 'name' => 'theirs', 'action' => 'set_clocks', 'schedule' => '0 2 * * *']);
        $this->assertSame([], $this->due('2026-10-12 02:00:00'), 'their 02:00 is in their zone, and their terminals are not ours');

        $this->job('set_clocks', '0 5 * * *', ['device_ids' => [$this->f18->id + 1000]]);
        $this->assertSame([], $this->due('2026-10-12 02:00:00'), 'a job that names other terminals does not touch this one');

        $this->job('set_clocks', '0 5 * * *', ['device_ids' => [$this->f18->id]]);
        $this->assertSame([DeviceCommand::SYNC_TIME => 1], $this->due('2026-10-12 02:00:00'));
    }

    public function test_never_a_second_command_of_a_kind_already_queued_or_one_the_terminal_cannot_do(): void
    {
        $this->job('clock_and_read', '0 5 * * *');
        foreach ([DeviceCommand::SYNC_TIME, DeviceCommand::SYNC_ATTENDANCE] as $kind) {
            DeviceCommand::create(['tenant_id' => $this->tenant->id, 'device_id' => $this->f18->id, 'command' => $kind,
                'status' => DeviceCommand::STATUS_QUEUED, 'available_at' => now(), 'max_attempts' => 3]);
        }
        $this->assertSame([], $this->due('2026-10-12 02:00:00'));

        $this->reset();
        $this->driver->capabilityOverrides = [Capability::SET_TIME => Capability::NOT_IMPLEMENTED];
        $this->assertSame([DeviceCommand::SYNC_ATTENDANCE => 1], $this->due('2026-10-12 02:00:00'));
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
