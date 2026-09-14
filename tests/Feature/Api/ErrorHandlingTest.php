<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DeviceErrorReporter;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Promoseven\Biometric\Exception\ConnectionException;
use Promoseven\Biometric\Exception\ProtocolException;
use Promoseven\Biometric\Exception\UnsupportedCommandException;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Covers the error catcher: what an operator sees, and what gets recorded.
 *
 * The two halves are tested separately on purpose. A device failure that returns a
 * tidy 502 but logs nothing is the bug this work existed to fix — a green
 * status-code assertion alone would have passed against the old code.
 */
class ErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-password';

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();
    }

    /*
    |---------------------------------------------------------------------------
    | The handler: protocol failures become 502, not 500 'Server error.'
    |---------------------------------------------------------------------------
    */

    public function test_a_device_exception_renders_as_502_with_the_real_message(): void
    {
        Route::middleware('api')->get('/api/_test/boom', function () {
            throw new ConnectionException('Device refused the handshake.', ['ip' => '10.0.0.9']);
        });

        $this->getJson('/api/_test/boom')
            ->assertStatus(502)
            ->assertJson([
                'success' => false,
                'message' => 'Device refused the handshake.',
                'errors' => ['retryable' => true],
            ]);
    }

    public function test_a_non_retryable_protocol_error_says_so(): void
    {
        Route::middleware('api')->get('/api/_test/malformed', function () {
            throw new ProtocolException('Template table would not decode.');
        });

        $this->getJson('/api/_test/malformed')
            ->assertStatus(502)
            ->assertJson(['errors' => ['retryable' => false]]);
    }

    public function test_an_unsupported_command_is_501_not_502(): void
    {
        Route::middleware('api')->get('/api/_test/unsupported', function () {
            throw new UnsupportedCommandException('This firmware cannot delete a single template.');
        });

        // 501 separates "the firmware cannot do this, ever" from "the device is
        // having a bad day". Rule 9 says the UI should have hidden it entirely, so
        // reaching here is a mis-declared capability, not a transient fault.
        $this->getJson('/api/_test/unsupported')
            ->assertStatus(501)
            ->assertJsonPath('message', 'This firmware cannot delete a single template.');
    }

    public function test_an_ordinary_bug_is_still_a_generic_500(): void
    {
        Route::middleware('api')->get('/api/_test/bug', fn () => throw new \RuntimeException('SQLSTATE secrets'));

        // Not a device problem, so it must NOT be dressed up as one, and the raw
        // message must not leak with debug off.
        config(['app.debug' => false]);

        $this->getJson('/api/_test/bug')
            ->assertStatus(500)
            ->assertJsonPath('message', 'Server error.');
    }

    /*
    |---------------------------------------------------------------------------
    | The reporter: the failure is actually recorded, with its context
    |---------------------------------------------------------------------------
    */

    public function test_the_reporter_logs_the_protocol_context_to_the_device_channel(): void
    {
        $device = $this->device();

        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($device) {
                return $message === 'Device operation failed: attendance sync'
                    && $context['device_id'] === $device->id
                    && $context['exception'] === ConnectionException::class
                    && $context['retryable'] === true
                    // context() is the whole point: without it a protocol bug is
                    // unreproducible, and it used to be discarded at every catch.
                    && $context['context'] === ['ip' => '10.0.0.9', 'attempt' => 2];
            });

        Log::shouldReceive('channel')->with('device')->once()->andReturn($logger);

        $message = app(DeviceErrorReporter::class)->report(
            new ConnectionException('Could not connect.', ['ip' => '10.0.0.9', 'attempt' => 2]),
            'attendance sync',
            $device,
        );

        $this->assertStringContainsString('could not reach the device', strtolower($message));
    }

    public function test_an_unexpected_throwable_is_logged_with_a_trace_and_a_safe_message(): void
    {
        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                // A plain Throwable has no context() and no isRetryable(), so both
                // are absent — and the stack replaces them, because for a bug in
                // our own code the frames are the informative part.
                return $context['exception'] === \TypeError::class
                    && ! array_key_exists('retryable', $context)
                    && ! array_key_exists('context', $context)
                    && ! empty($context['trace']);
            });

        Log::shouldReceive('channel')->with('device')->once()->andReturn($logger);

        $message = app(DeviceErrorReporter::class)->report(
            new \TypeError('Argument #1 must be int, string given'),
            'enrollment read',
        );

        // The internal message must not reach the operator.
        $this->assertStringNotContainsString('Argument #1', $message);
        $this->assertStringContainsString('internal error', $message);
    }

    /*
    |---------------------------------------------------------------------------
    | Client error reporting
    |---------------------------------------------------------------------------
    */

    public function test_the_spa_can_report_a_crash_and_it_reaches_the_client_channel(): void
    {
        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'Employees is not a function')
                && $context['kind'] === 'render'
                && str_contains((string) $context['component_stack'], 'at Employees'));

        Log::shouldReceive('channel')->with('client')->once()->andReturn($logger);

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/client-errors', [
                'message' => 'Employees is not a function',
                'kind' => 'render',
                'component_stack' => 'at Employees (Employees.jsx:31)',
                'url' => 'http://localhost/app/employees',
            ])
            ->assertStatus(202);
    }

    public function test_client_error_reporting_requires_authentication(): void
    {
        $this->postJson('/api/client-errors', ['message' => 'anonymous noise'])
            ->assertStatus(401);
    }

    public function test_an_over_long_stack_is_truncated_rather_than_stored_whole(): void
    {
        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                // A crash loop must not be able to fill the disk one report at a time.
                return mb_strlen($context['stack']) < 4200
                    && str_ends_with($context['stack'], '…[truncated]');
            });

        Log::shouldReceive('channel')->with('client')->once()->andReturn($logger);

        $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/client-errors', [
                'message' => 'boom',
                'stack' => str_repeat('x', 19000),
            ])
            ->assertStatus(202);
    }

    private function owner(): User
    {
        return User::withoutGlobalScopes()->firstOrFail();
    }

    private function device(): Device
    {
        $tenant = Tenant::query()->firstOrFail();
        Tenancy::set($tenant->id);

        $device = Device::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Terminal',
            'ip_address' => '10.0.0.9',
            'port' => 4370,
            'is_active' => true,
        ]);

        Tenancy::clear();

        return $device;
    }
}
