<?php

namespace App\Jobs;

use App\Models\DeviceCommand;
use App\Services\Devices\DeviceCommandHandler;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Zkteco\Protocol\Exception\DeviceException;

/**
 * Executes ONE queued device command.
 *
 * A single job type for every command, rather than one class per operation. The
 * retry accounting, tenancy restoration, raw-response capture and failure recording
 * are identical for all of them; duplicating that across eight jobs would guarantee
 * they drift. The command row says what to do; DeviceCommandHandler knows how.
 *
 * TENANCY: a queued job runs outside the request that created it, so there is no
 * authenticated user and no resolved tenant. The command row carries its tenant_id
 * and this job restores it before touching anything — without that, the global
 * TenantScope would silently query the wrong tenant's data or none at all.
 */
class ProcessDeviceCommand implements ShouldQueue
{
    use Queueable;

    /**
     * Queue attempts, which are NOT the same as domain attempts.
     *
     * CONFIRMED PITFALL: WithoutOverlapping RELEASES the job when another command is
     * already running on the same device, and a release counts as a queue attempt.
     * With tries = 1 the very first release failed the job with
     * MaxAttemptsExceededException before it ever reached a device.
     *
     * So the queue is allowed many attempts (releases are free and expected)…
     */
    public int $tries = 25;

    /**
     * …but a real thrown exception is terminal at the queue level.
     *
     * Domain retries are owned by the DeviceCommand row, which schedules them with
     * device-aware backoff. Letting the queue retry as well would double-count
     * attempts and hammer a terminal that is simply offline.
     */
    public int $maxExceptions = 1;

    /**
     * Long enough for the slowest real operation.
     *
     * A fingerprint capture waits ~30s for a human, a full attendance read of a large
     * table plus retries can take minutes. This is a ceiling, not a target.
     */
    public int $timeout = 300;

    public function __construct(public readonly int $commandId)
    {
    }

    /**
     * One device at a time.
     *
     * CONFIRMED HAZARD: this protocol is stateful per session and the device holds a
     * single conversation. Two concurrent commands to the same terminal interleave
     * their replies — the same class of failure that made template probes return
     * answers shifted by one request. Serialising per device is not an optimisation,
     * it is correctness.
     */
    public function middleware(): array
    {
        $command = DeviceCommand::withoutGlobalScopes()->find($this->commandId);

        if ($command === null) {
            return [];
        }

        return [
            (new WithoutOverlapping("device:{$command->device_id}"))
                ->releaseAfter(15)
                ->expireAfter(600),
        ];
    }

    public function handle(DeviceCommandHandler $handler): void
    {
        // withoutGlobalScopes: tenancy is not established yet, so a scoped query
        // would find nothing. We restore it from the row immediately below.
        $command = DeviceCommand::withoutGlobalScopes()->with('device')->find($this->commandId);

        if ($command === null) {
            Log::warning('Device command vanished before it ran', ['command_id' => $this->commandId]);

            return;
        }

        if ($command->isFinished()) {
            return; // already resolved — a duplicate dispatch, not an error
        }

        if ($command->device === null) {
            $command->markFailed('The device was deleted before this command ran.');

            return;
        }

        // Restore the tenant the command belongs to before any scoped query runs.
        if ($command->tenant_id !== null) {
            Tenancy::set($command->tenant_id);
        }

        $command->markRunning();

        try {
            $result = $handler->handle($command);

            $command->markSucceeded($result['message'], $result['raw'] ?? null);
        } catch (DeviceException $e) {
            // A device/protocol failure: expected, structured, often retryable.
            $command->markFailed($this->operatorMessage($e), [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'retryable' => $e->isRetryable(),
                'context' => $e->context(),
            ]);

            $this->requeueIfRetryable($command, $e->isRetryable());
        } catch (\Throwable $e) {
            // A bug, not a device problem. Record it and do NOT retry — retrying a
            // logic error just burns attempts and hides the cause.
            Log::error('Device command failed unexpectedly', [
                'command_id' => $command->id,
                'command' => $command->command,
                'device_id' => $command->device_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $command->markFailed(
                'Something went wrong running this command. The technical detail has been logged.',
                ['exception' => $e::class, 'message' => $e->getMessage()],
            );
        } finally {
            Tenancy::clear();
        }
    }

    /**
     * Re-queues the row when the failure was transient and attempts remain.
     *
     * markFailed() already set available_at from the backoff table; this only has to
     * put the job back on the queue at that time.
     */
    private function requeueIfRetryable(DeviceCommand $command, bool $retryable): void
    {
        if (! $retryable || ! $command->canRetry()) {
            return;
        }

        $delay = $command->available_at?->diffInSeconds(now(), absolute: true) ?? 60;

        $command->forceFill(['status' => DeviceCommand::STATUS_QUEUED])->save();

        self::dispatch($command->id)->delay(now()->addSeconds(max($delay, 5)));
    }

    /**
     * Turns a protocol exception into something an operator can act on.
     *
     * The technical detail is preserved separately in the error column; this is the
     * sentence that appears on screen.
     */
    private function operatorMessage(DeviceException $e): string
    {
        return match (true) {
            $e instanceof \Zkteco\Protocol\Exception\TimeoutException =>
                'The device did not respond in time. It may be offline, busy, or nobody was at the terminal.',
            $e instanceof \Zkteco\Protocol\Exception\ConnectionException =>
                'Could not reach the device. Check it is powered on and on the network.',
            $e instanceof \Zkteco\Protocol\Exception\UnsupportedCommandException =>
                'This device or firmware does not support that operation.',
            $e instanceof \Zkteco\Protocol\Exception\VerificationException =>
                'The device accepted the change but did not keep it. This firmware occasionally drops writes — try again.',
            default => $e->getMessage(),
        };
    }

    /** Called when the queue itself gives up on the job. */
    public function failed(\Throwable $e): void
    {
        $command = DeviceCommand::withoutGlobalScopes()->find($this->commandId);

        if ($command !== null && ! $command->isFinished()) {
            $command->markFailed(
                'The job processing this command was lost. Retry it, or check the worker is running.',
                ['exception' => $e::class, 'message' => $e->getMessage()],
            );
        }
    }
}
