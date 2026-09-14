<?php

namespace App\Jobs;

use App\Models\DeviceCommand;
use App\Services\Devices\DeviceCommandHandler;
use App\Support\DeviceErrorReporter;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Promoseven\Biometric\Exception\DeviceException;

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

    public function __construct(public readonly int $commandId) {}

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

    public function handle(DeviceCommandHandler $handler, DeviceErrorReporter $errors): void
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
            //
            // The row carries what the operator sees; the reporter writes the
            // diagnostic entry to the `device` channel. Both, not either: the row
            // is per-command and gets pruned, the log is what you read when the
            // question is "how often does this terminal do this".
            $message = $errors->report($e, "command {$command->command}", $command->device, [
                'command_id' => $command->id,
                'attempt' => $command->attempts,
            ]);

            $command->markFailed($message, [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'retryable' => $e->isRetryable(),
                'context' => $e->context(),
            ]);

            $this->requeueIfRetryable($command, $e->isRetryable());
        } catch (\Throwable $e) {
            // A bug, not a device problem. Record it and do NOT retry — retrying a
            // logic error just burns attempts and hides the cause.
            $message = $errors->report($e, "command {$command->command}", $command->device, [
                'command_id' => $command->id,
                'attempt' => $command->attempts,
            ]);

            $command->markFailed(
                $message,
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
