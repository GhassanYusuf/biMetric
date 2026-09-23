<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceRosterEntryResource;
use App\Models\Device;
use App\Models\DeviceRosterEntry;
use App\Models\Employee;
use App\Services\Devices\DeviceAdoptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bringing an existing terminal under management.
 *
 * WHY THIS IS A SCREEN AND NOT A BUTTON: every terminal here was in service before
 * this system existed, and each holds a roster somebody else assigned. Deciding which
 * of those rows is a real employee cannot be automated — the numbers collide across
 * devices, the names are 24-byte abbreviations, and a wrong guess either merges two
 * humans or writes over a third. So the flow is: read, then a human decides each row,
 * then writes unlock.
 *
 * The controller never touches the protocol. It calls the service; the service calls
 * the driver contract (rule 8).
 */
class DeviceAdoptionController extends Controller
{
    public function __construct(private readonly DeviceAdoptionService $adoption) {}

    /** The stored roster for a device. No device contact — safe to poll. */
    public function index(Request $request, Device $device): JsonResponse
    {
        $this->authorize('view', $device);

        $request->validate([
            'claim_status' => ['nullable', 'string', 'in:unclaimed,claimed,ignored'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $entries = DeviceRosterEntry::query()
            ->with(['claimedEmployee:id,name,employee_code'])
            ->where('device_id', $device->id)
            ->when($request->filled('claim_status'), fn ($q) => $q->where('claim_status', $request->string('claim_status')))
            ->orderByDeviceUserId()
            ->paginate($request->integer('per_page', 100));

        return ApiResponse::success(
            DeviceRosterEntryResource::collection($entries),
            "Roster for {$device->name}.",
            [
                'adoption_status' => $device->adoption_status,
                'roster_read_at' => $device->roster_read_at?->toIso8601String(),
                'pending' => $this->adoption->pendingCount($device),
                'writable' => $device->isWritable(),
            ],
        );
    }

    /**
     * Reads the roster off the hardware. READ-ONLY, and repeatable.
     *
     * Deliberately synchronous rather than queued: it is the first thing an operator
     * does on a new device and they are watching. It cannot change anything on the
     * terminal, so there is nothing for the command bus to make durable.
     */
    public function read(Device $device): JsonResponse
    {
        $this->authorize('sync', $device);

        try {
            $result = $this->adoption->readRoster($device);

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (\Throwable $e) {
            // A device that will not answer says nothing about its roster (rule 9).
            // It stays unadopted, which is the safe state.
            return ApiResponse::error(
                "Could not read the roster from {$device->name}: {$e->getMessage()}",
                status: 502,
            );
        }
    }

    /** Binds one roster entry to one employee, at the PIN the device already uses. */
    public function claim(Request $request, Device $device, DeviceRosterEntry $entry): JsonResponse
    {
        $this->authorize('update', $device);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
        ]);

        abort_unless($entry->device_id === $device->id, 404);

        $employee = Employee::findOrFail($data['employee_id']);

        try {
            $this->adoption->claim($entry, $employee, $request->user()?->id);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 422);
        }

        return ApiResponse::success(
            new DeviceRosterEntryResource($entry->fresh()->load('claimedEmployee')),
            "PIN {$entry->device_user_id} on {$device->name} is now {$employee->name}.",
        );
    }

    /** Declares a roster entry not a person we track. The PIN stays reserved. */
    public function ignore(Request $request, Device $device, DeviceRosterEntry $entry): JsonResponse
    {
        $this->authorize('update', $device);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
        ]);

        abort_unless($entry->device_id === $device->id, 404);

        $this->adoption->ignore($entry, $data['reason'], $request->user()?->id);

        return ApiResponse::success(
            new DeviceRosterEntryResource($entry->fresh()),
            "PIN {$entry->device_user_id} on {$device->name} will be left alone.",
        );
    }

    /** Unlocks writes, once nothing on the device is undecided. */
    public function adopt(Request $request, Device $device): JsonResponse
    {
        $this->authorize('update', $device);

        try {
            $result = $this->adoption->finishAdoption($device, $request->user()?->id);

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), status: 422);
        }
    }
}
