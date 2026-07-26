<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceSyncRunResource;
use App\Http\Resources\DeviceCommandResource;
use App\Http\Resources\DeviceStatusLogResource;
use App\Models\AttendanceSyncRun;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\Devices\DeviceCommandBus;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sync history, the command queue, and health history.
 *
 * Every device action here is QUEUED, never performed in the request. The response
 * carries a command id; the outcome arrives over MQTT or by polling the command.
 * That is the whole point of the queue — a fingerprint capture blocks for 30 seconds
 * and must not hold a web worker.
 */
class DeviceSyncController extends Controller
{
    public function __construct(private readonly DeviceCommandBus $bus)
    {
    }

    /** Sync run history, newest first. Optionally filtered to one device. */
    public function runs(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        $request->validate([
            'device_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $runs = AttendanceSyncRun::query()
            ->with('device:id,name')
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->integer('device_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('started_at')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::success(AttendanceSyncRunResource::collection($runs), 'Sync runs retrieved.');
    }

    public function run(AttendanceSyncRun $run): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        return ApiResponse::success(
            new AttendanceSyncRunResource($run->load('device:id,name')),
            'Sync run retrieved.',
        );
    }

    /** Command queue history. */
    public function commands(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        $request->validate([
            'device_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'command' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $commands = DeviceCommand::query()
            ->with(['device:id,name', 'requester:id,name'])
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->integer('device_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('command'), fn ($q) => $q->where('command', $request->string('command')))
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::success(DeviceCommandResource::collection($commands), 'Device commands retrieved.');
    }

    public function command(DeviceCommand $command): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        return ApiResponse::success(
            new DeviceCommandResource($command->load(['device:id,name', 'requester:id,name'])),
            'Command retrieved.',
        );
    }

    /**
     * Queues an operation on one device.
     *
     * Capability-gated: a command the firmware cannot do is refused with 422 before
     * anything is queued, so the queue never fills with work that must fail.
     */
    public function dispatchCommand(Request $request, Device $device): JsonResponse
    {
        $this->authorize('sync', $device);

        $data = $request->validate([
            'command' => ['required', 'string', Rule::in([
                DeviceCommand::SYNC_ATTENDANCE,
                DeviceCommand::SYNC_ENROLLMENT,
                DeviceCommand::IMPORT_EMPLOYEES,
                DeviceCommand::RECONCILE_USERS,
                DeviceCommand::HEALTH_CHECK,
                DeviceCommand::READ_INFO,
                DeviceCommand::PROBE_CAPABILITIES,
                DeviceCommand::SYNC_TIME,
            ])],
            'full' => ['boolean'],
        ]);

        if ($error = $this->capabilityRefusal($device, $data['command'])) {
            return ApiResponse::error($error, ['command' => [$error]], 422);
        }

        $command = $this->bus->dispatch($device, $data['command'], [
            'full' => (bool) ($data['full'] ?? false),
        ]);

        return ApiResponse::success(
            new DeviceCommandResource($command),
            "Queued {$data['command']} on {$device->name}. Watch the command for its outcome.",
        );
    }

    /** Re-queues a failed command, reusing the same row so history stays in one place. */
    public function retry(DeviceCommand $command): JsonResponse
    {
        $this->authorize('sync', $command->device);

        try {
            $this->bus->retry($command);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        }

        return ApiResponse::success(new DeviceCommandResource($command->fresh()), 'Command re-queued.');
    }

    public function cancel(DeviceCommand $command): JsonResponse
    {
        $this->authorize('sync', $command->device);

        try {
            $this->bus->cancel($command);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        }

        return ApiResponse::success(new DeviceCommandResource($command->fresh()), 'Command cancelled.');
    }

    /** Health transition history for one device. */
    public function statusHistory(Request $request, Device $device): JsonResponse
    {
        $this->authorize('view', $device);

        $logs = $device->statusLogs()
            ->latest('observed_at')
            ->paginate($request->integer('per_page', 50));

        return ApiResponse::success(DeviceStatusLogResource::collection($logs), 'Status history retrieved.');
    }

    /**
     * Maps a command to the capability it needs.
     *
     * Returns an operator-facing refusal, or null when the device can do it.
     */
    private function capabilityRefusal(Device $device, string $command): ?string
    {
        $required = match ($command) {
            DeviceCommand::SYNC_ATTENDANCE => \Zkteco\Protocol\Capability\Capability::ATTENDANCE_READ,
            DeviceCommand::SYNC_ENROLLMENT,
            DeviceCommand::IMPORT_EMPLOYEES,
            DeviceCommand::RECONCILE_USERS => \Zkteco\Protocol\Capability\Capability::USER_READ,
            DeviceCommand::SYNC_TIME => \Zkteco\Protocol\Capability\Capability::SET_TIME,
            default => null, // health/info/probe are always available
        };

        if ($required === null) {
            return null;
        }

        $capabilities = $device->capabilitySet();

        if ($capabilities->supports($required)) {
            return null;
        }

        return sprintf(
            '%s does not support %s: %s',
            $device->name,
            $required,
            \Zkteco\Protocol\Capability\Capability::describe($capabilities->level($required)),
        );
    }
}
