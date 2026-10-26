<?php

namespace Tests\Feature\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Devices\DeviceRestartService;
use App\Support\DeviceErrorReporter;
use App\Support\DeviceRefusalAdvice;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Exception\ProtocolException;
use Promoseven\Biometric\Exception\UnsupportedCommandException;
use Tests\TestCase;

/**
 * Devices → Restart terminal (2026-10-26, casebook DC-034). A restart is done when the
 * terminal has gone away and answers again, never on the ACK alone (rule 10).
 */
class DeviceRestartTest extends TestCase
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
            'tenant_id' => $this->tenant->id, 'name' => 'Camelot', 'model' => 'F18/ID',
            'ip_address' => '192.0.2.60', 'port' => 4370, 'protocol' => Device::PROTOCOL_BINARY,
            'timezone' => '+03:00', 'is_active' => true,
        ]);

        $this->driver = new FakeBiometricDriver;
        $this->app->instance(BiometricDeviceDriver::class, $this->driver);
        // No real waiting between pings.
        $this->app->instance(DeviceRestartService::class, new DeviceRestartService($this->driver, fn (int $s) => null));
    }

    private function service(): DeviceRestartService
    {
        return app(DeviceRestartService::class);
    }

    public function test_a_restart_is_done_when_the_terminal_has_gone_away_and_answers_again(): void
    {
        $this->driver->downForPings = 12; // Camelot: away about 25 s, polled every 2 s

        $result = $this->service()->restart($this->device);

        $this->assertSame(1, $this->driver->restarts);
        $this->assertSame(26, $result['raw']['seconds_until_back']);
        $this->assertStringContainsString('Camelot restarted and is answering again after about 26 s', $result['message']);
    }

    public function test_a_terminal_that_never_stops_answering_is_not_called_restarted(): void
    {
        $this->driver->downForPings = 0;

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('never stopped answering');
        $this->service()->restart($this->device);
    }

    public function test_a_terminal_that_does_not_come_back_says_so(): void
    {
        $this->driver->downForPings = null;

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('has not answered for 180 s');
        $this->service()->restart($this->device);
    }

    public function test_a_model_that_cannot_restart_is_refused_before_anything_is_sent(): void
    {
        $this->driver->capabilityOverrides = [Capability::RESTART => Capability::UNKNOWN];

        try {
            $this->service()->restart($this->device);
            $this->fail('Expected the restart to be refused.');
        } catch (UnsupportedCommandException) {
            $this->assertSame(0, $this->driver->restarts);
        }
    }

    public function test_the_endpoint_queues_one_attempt_and_refuses_an_unsupported_model(): void
    {
        $admin = User::withoutGlobalScopes()->firstOrFail();
        $this->driver->downForPings = 3;

        $this->actingAs($admin, 'sanctum')->postJson("/api/devices/{$this->device->id}/restart")->assertOk();

        $command = DeviceCommand::where('command', DeviceCommand::RESTART_DEVICE)->sole();
        $this->assertSame(1, (int) $command->max_attempts);
        $this->assertSame(1, $this->driver->restarts);

        $this->driver->capabilityOverrides = [Capability::RESTART => Capability::UNKNOWN];
        $this->actingAs($admin, 'sanctum')->postJson("/api/devices/{$this->device->id}/restart")
            ->assertUnprocessable();
        $this->assertSame(1, DeviceCommand::where('command', DeviceCommand::RESTART_DEVICE)->count());
    }

    /** DC-034: Camelot's 4995 now says what to do, and a queued refusal reads as written. */
    public function test_a_database_refusal_advises_a_restart_and_refusals_read_as_written(): void
    {
        $refused = new ProtocolException('Device rejected the user write (4995 Error inserting database).', ['uid' => 17, 'reply' => 4995]);
        $this->assertStringContainsString('Restart it (Devices → Restart terminal)', DeviceRefusalAdvice::message($refused));
        $this->assertNull(DeviceRefusalAdvice::for(new ProtocolException('Device rejected the user write (ACK_ERROR).', ['reply' => 2001])));

        $reporter = app(DeviceErrorReporter::class);
        $this->assertStringContainsString('Restart it', $reporter->operatorMessage($refused, 'command push_user'));
        $this->assertSame(
            'Command restart_device failed: Camelot acknowledged the restart but has not answered for 180 s.',
            $reporter->operatorMessage(new \DomainException('Camelot acknowledged the restart but has not answered for 180 s.'), 'command restart_device'),
        );
    }
}
