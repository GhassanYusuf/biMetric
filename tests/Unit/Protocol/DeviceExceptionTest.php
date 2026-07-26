<?php

namespace Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Zkteco\Protocol\Exception\ConnectionException;
use Zkteco\Protocol\Exception\DeviceException;
use Zkteco\Protocol\Exception\ProtocolException;
use Zkteco\Protocol\Exception\TimeoutException;
use Zkteco\Protocol\Exception\UnsupportedCommandException;
use Zkteco\Protocol\Exception\VerificationException;

/**
 * The retry classification.
 *
 * A host's retry policy branches on `isRetryable()`, never on message text. These
 * tests pin which failures are worth retrying, because getting it wrong is costly
 * in both directions: retrying a ProtocolException hammers a device with a request
 * that can never work, and NOT retrying a TimeoutException throws away a sync over
 * one dropped datagram.
 */
class DeviceExceptionTest extends TestCase
{
    public function test_every_device_exception_shares_one_catchable_base(): void
    {
        $exceptions = [
            new ConnectionException('x'),
            new TimeoutException('x'),
            new VerificationException('x'),
            new ProtocolException('x'),
            new UnsupportedCommandException('x'),
        ];

        foreach ($exceptions as $e) {
            $this->assertInstanceOf(DeviceException::class, $e);
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    public function test_transient_failures_are_retryable(): void
    {
        $this->assertTrue((new ConnectionException('unreachable'))->isRetryable());
        $this->assertTrue((new TimeoutException('no answer'))->isRetryable());
    }

    /**
     * A verification failure means the device ACKED a write that did not stick.
     * That is this firmware misbehaving, not the request being wrong, so it is
     * worth another attempt before giving up.
     */
    public function test_a_dropped_write_is_retryable(): void
    {
        $this->assertTrue((new VerificationException('ack without commit'))->isRetryable());
    }

    /**
     * These describe a request that will never succeed. Retrying is pure noise —
     * malformed bytes stay malformed, and firmware does not grow a capability
     * between attempts.
     */
    public function test_permanent_failures_are_not_retryable(): void
    {
        $this->assertFalse((new ProtocolException('malformed'))->isRetryable());
        $this->assertFalse((new UnsupportedCommandException('no such capability'))->isRetryable());
        $this->assertFalse((new DeviceException('generic'))->isRetryable());
    }

    public function test_context_is_carried_for_whoever_debugs_the_failure(): void
    {
        $e = new ProtocolException('User 261 is not on the device.', ['user_id' => '261', 'uid' => 7]);

        $this->assertSame(['user_id' => '261', 'uid' => 7], $e->context());
        // The message stays the sentence a human reads; context holds the detail.
        $this->assertSame('User 261 is not on the device.', $e->getMessage());
    }

    public function test_context_defaults_to_an_empty_array_rather_than_null(): void
    {
        $this->assertSame([], (new TimeoutException('timed out'))->context());
    }

    /**
     * The classification must be readable without inspecting the class name, so a
     * host can write one branch for every current and future exception type.
     */
    public function test_a_host_can_classify_any_failure_through_the_base_type(): void
    {
        $retried = 0;
        $failed = 0;

        foreach ([new TimeoutException('a'), new ConnectionException('b'), new VerificationException('c'),
                  new ProtocolException('d'), new UnsupportedCommandException('e')] as $e) {
            /** @var DeviceException $e */
            $e->isRetryable() ? $retried++ : $failed++;
        }

        $this->assertSame(3, $retried);
        $this->assertSame(2, $failed);
    }
}
