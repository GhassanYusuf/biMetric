<?php

namespace App\Support;

use App\Models\Device;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Promoseven\Biometric\Exception\ConnectionException;
use Promoseven\Biometric\Exception\DeviceException;
use Promoseven\Biometric\Exception\TimeoutException;
use Promoseven\Biometric\Exception\UnsupportedCommandException;
use Promoseven\Biometric\Exception\VerificationException;

/**
 * The single place a device/protocol failure is recorded.
 *
 * WHY THIS EXISTS: the typed exceptions in Promoseven\Biometric already carry
 * everything needed to diagnose a protocol bug — context(), isRetryable(), the
 * concrete subclass. Before this class, every catch site threw all of that away
 * and returned a one-line string, so "the device errored again" left no trace
 * anyone could act on. The library is framework-agnostic and must stay that way
 * (CLAUDE.md rule 8), so it cannot log for itself. This is the app-side half.
 *
 * It does two things and deliberately no more:
 *
 *   1. writes ONE structured entry to the `device` log channel, and
 *   2. returns the operator-facing sentence the caller should surface.
 *
 * Keeping those two together is the point. Rule 11 requires the operator message
 * and the technical detail to be stored separately; splitting them across call
 * sites is how they drifted apart in the first place.
 *
 * It never rethrows and never swallows the decision of what to do next — the
 * caller still chooses between retry, fail and 502.
 */
class DeviceErrorReporter
{
    /**
     * Records a failure and returns the message to show an operator.
     *
     * $operation is a short verb phrase naming what was attempted
     * ('sync attendance', 'read enrollment') and is used for both the log entry
     * and the human sentence, so the two always agree.
     *
     * @param  array<string,mixed>  $extra  Call-site detail worth keeping (command id, employee id).
     */
    public function report(
        \Throwable $e,
        string $operation,
        ?Device $device = null,
        array $extra = [],
    ): string {
        $isDeviceFailure = $e instanceof DeviceException;

        Log::channel('device')->error("Device operation failed: {$operation}", array_filter([
            'operation' => $operation,
            'device_id' => $device?->id,
            'device_name' => $device?->name,
            'device_ip' => $device?->ip_address,
            'tenant_id' => $device?->tenant_id,
            'user_id' => Auth::id(),

            'exception' => $e::class,
            'message' => $e->getMessage(),

            // The two fields that make a protocol bug reproducible. A plain
            // \Throwable has neither, which is itself the useful signal: it means
            // this was a bug in our code, not the terminal misbehaving.
            'retryable' => $isDeviceFailure ? $e->isRetryable() : null,
            'context' => $isDeviceFailure ? $e->context() : null,

            // Only for non-device throwables. A DeviceException's stack is noise —
            // its context() is the informative part — but an unexpected TypeError
            // in a codec is worth the frames.
            'trace' => $isDeviceFailure ? null : $this->trace($e),

            'extra' => $extra ?: null,
        ], fn ($v) => $v !== null));

        return $this->operatorMessage($e, $operation);
    }

    /**
     * The sentence an operator reads.
     *
     * Wording lives here and only here. It used to be duplicated between
     * ProcessDeviceCommand and three controller catch blocks, which is why the
     * same timeout read as "Sync failed: read timed out" in one place and
     * "The device did not respond in time." in another.
     *
     * Each subclass gets a sentence naming the thing the operator can actually
     * check. The raw exception message is a fallback, not the default: it is
     * written for whoever reads the log, and often mentions offsets and opcodes.
     *
     * Anything that is NOT a DeviceException is a bug in our code. Its message
     * would be meaningless (or leak internals) on screen, so it is replaced —
     * the detail went to the `device` channel in report() above.
     */
    public function operatorMessage(\Throwable $e, string $operation): string
    {
        $what = ucfirst($operation).' failed';

        return match (true) {
            $e instanceof TimeoutException => "{$what}: the device did not respond in time. It may be offline, busy, or nobody was at the terminal.",
            $e instanceof ConnectionException => "{$what}: could not reach the device. Check it is powered on and on the network.",
            $e instanceof UnsupportedCommandException => "{$what}: this device or firmware does not support that operation.",
            $e instanceof VerificationException => "{$what}: the device accepted the change but did not keep it. This firmware occasionally drops writes — try again.",
            $e instanceof DeviceException => "{$what}: {$e->getMessage()}",
            default => "{$what} because of an internal error. It has been logged for the developers.",
        };
    }

    /** First few frames only; a full trace buries the entry it belongs to. */
    private function trace(\Throwable $e): array
    {
        return array_map(
            fn (array $f) => ($f['file'] ?? '?').':'.($f['line'] ?? '?').' '.($f['function'] ?? ''),
            array_slice($e->getTrace(), 0, 8),
        );
    }
}
