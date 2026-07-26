<?php

namespace App\Services\Devices;

use App\Jobs\ProcessDeviceCommand;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceGroup;
use Illuminate\Support\Facades\Auth;

/**
 * The only way to ask a device to do something.
 *
 * WHY: device operations previously ran inline in HTTP requests behind
 * set_time_limit(90). A 30-second fingerprint capture held a PHP-FPM worker, a
 * failure vanished with the request, and nothing could be retried or audited.
 *
 * Queueing changes the shape of every device action: the caller gets a command id
 * immediately, and the outcome arrives later over MQTT or by polling the command.
 * The DeviceCommand row is the durable record; the job is just the worker.
 *
 * Callers that genuinely need a synchronous answer (a UI probe the operator is
 * watching) can still use the driver directly — but anything that writes to a
 * device, or blocks on a human, belongs here.
 */
class DeviceCommandBus
{
    /**
     * Queues one command for one device.
     *
     * @param array<string,mixed> $payload
     */
    public function dispatch(
        Device $device,
        string $command,
        array $payload = [],
        ?int $maxAttempts = null,
    ): DeviceCommand {
        $record = DeviceCommand::create([
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'command' => $command,
            'payload' => $payload,
            'status' => DeviceCommand::STATUS_QUEUED,
            'max_attempts' => $maxAttempts ?? (int) config('attendance.command_max_attempts', 3),
            'available_at' => now(),
            'requested_by' => Auth::id(),
        ]);

        ProcessDeviceCommand::dispatch($record->id);

        return $record;
    }

    /**
     * Queues the same command for every addressable device in a group.
     *
     * Returns one command per device rather than one for the group: a group
     * operation that half-succeeds must be legible per terminal, and retrying should
     * not re-hit the devices that already worked.
     *
     * @param array<string,mixed> $payload
     * @return list<DeviceCommand>
     */
    public function dispatchToGroup(DeviceGroup $group, string $command, array $payload = []): array
    {
        $commands = [];

        foreach ($group->addressableDevices()->get() as $device) {
            $commands[] = $this->dispatch($device, $command, $payload);
        }

        return $commands;
    }

    /**
     * Queues the same command for every addressable device in the current tenant.
     *
     * @param array<string,mixed> $payload
     * @return list<DeviceCommand>
     */
    public function dispatchToAll(string $command, array $payload = []): array
    {
        $commands = [];

        foreach (Device::query()->directlyAddressable()->get() as $device) {
            $commands[] = $this->dispatch($device, $command, $payload);
        }

        return $commands;
    }

    /**
     * Re-queues a failed command.
     *
     * Reuses the SAME row so the attempt history stays in one place — a new row per
     * retry would make "this device fails every time" invisible.
     */
    public function retry(DeviceCommand $command): DeviceCommand
    {
        if (! $command->canRetry()) {
            throw new \RuntimeException(
                "Command {$command->id} cannot be retried (status {$command->status}, "
                . "attempt {$command->attempts} of {$command->max_attempts}).",
            );
        }

        $command->forceFill([
            'status' => DeviceCommand::STATUS_QUEUED,
            'available_at' => now(),
            'error' => null,
        ])->save();

        ProcessDeviceCommand::dispatch($command->id);

        return $command;
    }

    /**
     * Cancels a command that has not started.
     *
     * A RUNNING command is deliberately not cancellable: the device is mid-operation
     * and abandoning the row would leave the terminal in capture mode with nothing
     * tracking it.
     */
    public function cancel(DeviceCommand $command): void
    {
        if ($command->status === DeviceCommand::STATUS_RUNNING) {
            throw new \RuntimeException(
                "Command {$command->id} is already running on the device and cannot be cancelled.",
            );
        }

        if ($command->isFinished()) {
            return;
        }

        $command->forceFill([
            'status' => DeviceCommand::STATUS_CANCELLED,
            'finished_at' => now(),
            'message' => 'Cancelled before it reached the device.',
        ])->save();
    }
}
