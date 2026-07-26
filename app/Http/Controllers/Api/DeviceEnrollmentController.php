<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceCommandResource;
use App\Http\Resources\EnrollmentSessionResource;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\EnrollmentSession;
use App\Services\Devices\DeviceCommandBus;
use App\Services\Devices\EnrollmentOrchestratorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Zkteco\Protocol\Capability\Capability;

/**
 * Enrollment sessions.
 *
 * Captures are QUEUED, not run in the request: they block on a human walking to a
 * terminal for up to 30 seconds. The response returns a session id, and progress
 * arrives over MQTT on tenant/{id}/module/enrollment/{sessionId}.
 *
 * This replaces the old synchronous flow where closing the browser lost all record
 * that an enrollment had been attempted.
 */
class DeviceEnrollmentController extends Controller
{
    public function __construct(
        private readonly DeviceCommandBus $bus,
        private readonly EnrollmentOrchestratorService $enrollment,
    ) {
    }

    /** Session history, filterable by employee, device or state. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'device_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $sessions = EnrollmentSession::query()
            ->with(['employee:id,name,employee_code', 'device:id,name'])
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->integer('device_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('active'), fn ($q) => $q->whereNotIn('status', EnrollmentSession::TERMINAL))
            ->latest('requested_at')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::success(EnrollmentSessionResource::collection($sessions), 'Enrollment sessions retrieved.');
    }

    public function show(EnrollmentSession $session): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        return ApiResponse::success(
            new EnrollmentSessionResource($session->load(['employee:id,name,employee_code', 'device:id,name'])),
            'Enrollment session retrieved.',
        );
    }

    /**
     * Queues a fingerprint capture.
     *
     * The employee does NOT need to be on the device first — the capture creates the
     * record if it is missing, which is what turned a two-step dance with an error in
     * the middle into one action.
     */
    public function fingerprint(Request $request, Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        $data = $request->validate([
            'finger_slot' => ['required', 'integer', 'min:0', 'max:9'],
        ]);

        if ($refusal = $this->refuse($device, Capability::FINGERPRINT_ENROLL)) {
            return ApiResponse::error($refusal, [], 422);
        }

        if ($active = $this->activeSessionFor($employee, $device)) {
            return ApiResponse::error(
                "An enrollment is already waiting at {$device->name} for {$employee->name}. "
                . 'Cancel it before starting another, or wait for it to time out.',
                ['session_id' => [$active->id]],
                409,
            );
        }

        $command = $this->bus->dispatch($device, DeviceCommand::ENROLL_FINGERPRINT, [
            'employee_id' => $employee->id,
            'finger_slot' => $data['finger_slot'],
        ]);

        return ApiResponse::success(
            new DeviceCommandResource($command),
            "{$device->name} will beep and wait for a finger in slot {$data['finger_slot']}. "
            . 'Ask them to present it now.',
        );
    }

    /** Queues a card capture. */
    public function card(Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        if ($refusal = $this->refuse($device, Capability::CARD_ENROLL)) {
            return ApiResponse::error($refusal, [], 422);
        }

        if ($active = $this->activeSessionFor($employee, $device)) {
            return ApiResponse::error(
                "An enrollment is already waiting at {$device->name} for {$employee->name}.",
                ['session_id' => [$active->id]],
                409,
            );
        }

        $command = $this->bus->dispatch($device, DeviceCommand::ENROLL_CARD, [
            'employee_id' => $employee->id,
        ]);

        return ApiResponse::success(
            new DeviceCommandResource($command),
            "{$device->name} will beep and wait for a card. Ask them to tap it now.",
        );
    }

    /**
     * Abandons an active session and releases the terminal.
     *
     * Runs synchronously: it is a fast cancel, and leaving a device in capture mode
     * while a cancel sat in a queue would keep refusing real punches.
     */
    public function cancel(EnrollmentSession $session): JsonResponse
    {
        $this->authorize('pushToDevice', $session->employee);

        if ($session->isFinished()) {
            return ApiResponse::error(
                "That enrollment already finished with status '{$session->status}'.",
                [],
                422,
            );
        }

        $this->enrollment->cancel($session);

        return ApiResponse::success(
            new EnrollmentSessionResource($session->fresh()),
            'Enrollment cancelled and the terminal released.',
        );
    }

    /** Queues deletion of one fingerprint slot. */
    public function deleteFingerprint(Employee $employee, Device $device, int $slot): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        if ($slot < 0 || $slot > 9) {
            return ApiResponse::error('Invalid finger slot.', ['slot' => ['The slot must be between 0 and 9.']], 422);
        }

        if ($refusal = $this->refuse($device, Capability::TEMPLATE_DELETE)) {
            return ApiResponse::error($refusal, [], 422);
        }

        $command = $this->bus->dispatch($device, DeviceCommand::DELETE_FINGERPRINT, [
            'employee_id' => $employee->id,
            'finger_slot' => $slot,
        ]);

        return ApiResponse::success(new DeviceCommandResource($command), "Queued deletion of slot {$slot}.");
    }

    /** Queues clearing the card. */
    public function deleteCard(Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        if ($refusal = $this->refuse($device, Capability::CARD_WRITE)) {
            return ApiResponse::error($refusal, [], 422);
        }

        $command = $this->bus->dispatch($device, DeviceCommand::CLEAR_CARD, [
            'employee_id' => $employee->id,
        ]);

        return ApiResponse::success(new DeviceCommandResource($command), 'Queued card removal.');
    }

    /**
     * One active session per employee per device.
     *
     * Two concurrent captures on one terminal would interleave their device events
     * and neither would be trustworthy.
     */
    private function activeSessionFor(Employee $employee, Device $device): ?EnrollmentSession
    {
        return EnrollmentSession::query()
            ->where('employee_id', $employee->id)
            ->where('device_id', $device->id)
            ->whereNotIn('status', EnrollmentSession::TERMINAL)
            ->get()
            // Reap anything already past its grace window rather than blocking on it.
            ->first(fn (EnrollmentSession $s) => ! $s->hasExpired());
    }

    private function refuse(Device $device, string $capability): ?string
    {
        $capabilities = $device->capabilitySet();

        if ($capabilities->supports($capability)) {
            return null;
        }

        return sprintf(
            '%s does not support %s: %s',
            $device->name,
            $capability,
            Capability::describe($capabilities->level($capability)),
        );
    }
}
