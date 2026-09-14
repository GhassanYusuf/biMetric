<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Employee\StoreEmployeeRequest;
use App\Http\Requests\Api\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Device;
use App\Models\Employee;
use App\Services\Attendance\AttendanceSummaryService;
use App\Services\Devices\DeviceUserSyncService;
use App\Services\Devices\EnrollmentOrchestratorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Promoseven\Biometric\Exception\DeviceException;

class EmployeeController extends Controller
{
    /**
     * The device actions below run INLINE rather than through DeviceCommandBus,
     * because each is triggered by an operator standing at the terminal with the
     * employee and watching for the result — a queue receipt is useless to them.
     * The queued equivalents exist for automation and bulk work; see
     * DeviceEnrollmentController.
     */
    public function __construct(
        private readonly DeviceUserSyncService $users,
        private readonly EnrollmentOrchestratorService $enrollment,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $employees = Employee::query()
            ->withCount('attendanceLogs')
            // `deviceMaps` answers "enrolled on which terminal?" — the flat
            // enrollment columns on employees cannot, once there is more than
            // one device.
            ->with(['department:id,name', 'deviceMaps.device:id,name'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('employee_code', 'like', $term)
                        ->orWhere('department', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::success(
            EmployeeResource::collection($employees),
            'Employees retrieved.',
        );
    }

    /**
     * Suggests the lowest unused employee code — the first gap in the numeric
     * codes already registered, or one past the highest when there is no gap.
     *
     * Gap-filling rather than always incrementing: codes get freed when people
     * leave, and the terminal keys attendance by this code, so reusing a free low
     * number keeps them short and typeable at the keypad.
     *
     * Only numeric codes are considered. Non-numeric ones (e.g. "TEMP-4") are
     * ignored rather than guessed at, so they never collide with a suggestion.
     */
    public function nextCode(): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $taken = Employee::query()
            ->pluck('employee_code')
            ->filter(fn ($code) => is_string($code) && ctype_digit($code))
            ->map(fn ($code) => (int) $code)
            ->filter(fn (int $code) => $code > 0)
            ->unique()
            ->sort()
            ->values();

        $suggestion = 1;
        foreach ($taken as $code) {
            if ($code > $suggestion) {
                break; // found the gap
            }
            $suggestion = $code + 1;
        }

        return ApiResponse::success(
            [
                'code' => (string) $suggestion,
                'taken_count' => $taken->count(),
            ],
            "Next free employee code is {$suggestion}.",
        );
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $data = $request->safe()->except('photo');

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('employees', 'public');
        }

        $employee = Employee::create($data);

        return ApiResponse::created(new EmployeeResource($employee), 'Employee added.');
    }

    public function show(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        $employee->loadCount('attendanceLogs')->load(['department:id,name', 'deviceMaps.device:id,name']);

        return ApiResponse::success(new EmployeeResource($employee), 'Employee retrieved.');
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $data = $request->safe()->except('photo');

        if ($request->hasFile('photo')) {
            if ($employee->photo_path) {
                Storage::disk('public')->delete($employee->photo_path);
            }
            $data['photo_path'] = $request->file('photo')->store('employees', 'public');
        }

        $employee->update($data);

        return ApiResponse::success(new EmployeeResource($employee->fresh()), 'Employee updated.');
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->authorize('delete', $employee);

        $employee->delete();

        return ApiResponse::success(null, 'Employee removed.');
    }

    /** Per-day attendance breakdown + payroll hours for one employee. */
    public function attendanceSummary(Request $request, Employee $employee, AttendanceSummaryService $summary): JsonResponse
    {
        $this->authorize('view', $employee);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $data = $summary->summary($employee, $validated['from'] ?? null, $validated['to'] ?? null);

        return ApiResponse::success($data, 'Attendance summary generated.');
    }

    public function pushToDevice(Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        try {
            $result = $this->users->pushEmployee($device, $employee);

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (DeviceException $e) {
            return ApiResponse::error("Failed to push to device: {$e->getMessage()}", [], 502);
        }
    }

    public function removeFromDevice(Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        try {
            $result = $this->users->removeEmployee($device, $employee);

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (DeviceException $e) {
            return ApiResponse::error("Failed to remove from device: {$e->getMessage()}", [], 502);
        }
    }

    /**
     * Live read of this employee's enrollment on a terminal — which finger slots
     * are filled and whether a card is registered. Powers the hand picker, so it
     * runs on opening the enrollment dialog rather than on any list render.
     */
    public function enrollment(Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        set_time_limit(60);

        try {
            return ApiResponse::success(
                $this->users->readEnrollment($device, $employee),
                "Read {$employee->name}'s enrollment from {$device->name}.",
            );
        } catch (DeviceException $e) {
            return ApiResponse::error("Could not read enrollment: {$e->getMessage()}", [], 502);
        }
    }

    /**
     * Puts the terminal into live fingerprint-capture mode for this employee.
     * The device beeps as soon as the trigger lands (so the person standing at
     * it knows to place a finger), then this request blocks until a template is
     * captured or the device times out — hence the raised time limit.
     */
    public function enrollFingerprint(Request $request, Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        $data = $request->validate([
            'finger_index' => ['required', 'integer', 'min:0', 'max:9'],
        ]);

        // The service waits ~30s for a finger; don't let PHP kill it first.
        set_time_limit(90);

        try {
            $result = $this->enrollment->captureFingerprint($device, $employee, (int) $data['finger_index']);

            // A capture that did not produce a template is the DEVICE'S answer —
            // nobody presented a finger, or it could not be read — not a transport
            // fault. 422 with the service's own wording, never a 5xx.
            if (! ($result['raw']['success'] ?? false)) {
                return ApiResponse::error($result['message'], $result['raw'], 422);
            }

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (DeviceException $e) {
            return ApiResponse::error("Fingerprint enrollment failed: {$e->getMessage()}", [], 502);
        }
    }

    /**
     * Clears the employee's card on the terminal, keeping their record and
     * fingerprints. The card lives in one field on the user record, so this
     * rewrites that record with card 0 rather than deleting anything.
     */
    public function deleteCard(Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        set_time_limit(90);

        try {
            $result = $this->enrollment->clearCard($device, $employee);

            // No card to clear is a 422 with the service's wording: the request was
            // understood, there was simply nothing to do.
            if (($result['raw']['previous_card'] ?? null) === null) {
                return ApiResponse::error($result['message'], $result['raw'], 422);
            }

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (DeviceException $e) {
            return ApiResponse::error("Could not remove the card: {$e->getMessage()}", [], 502);
        }
    }

    /**
     * Deletes one fingerprint from the terminal, keeping the employee's other
     * fingers and their card. Destructive: the person must re-enroll that finger.
     */
    public function deleteFingerprint(Employee $employee, Device $device, int $slot): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        // A bad slot is the caller's mistake, not the terminal's — 422, not 502.
        if ($slot < 0 || $slot > 9) {
            return ApiResponse::error('Invalid finger slot.', ['slot' => ['The slot must be between 0 and 9.']], 422);
        }

        set_time_limit(90);

        try {
            $result = $this->enrollment->deleteFingerprint($device, $employee, $slot);

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (DeviceException $e) {
            return ApiResponse::error("Could not delete the fingerprint: {$e->getMessage()}", [], 502);
        }
    }

    /**
     * Puts the terminal into live card-read mode for this employee. Beeps on
     * trigger, then blocks until a card is swiped, writing the card number onto
     * their existing device record.
     */
    public function enrollCard(Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        // The service waits ~20s for a card swipe.
        set_time_limit(90);

        try {
            $result = $this->enrollment->captureCard($device, $employee);

            if (! ($result['raw']['success'] ?? false)) {
                // Nobody tapped a card within the window. The device answered; it
                // just answered "nothing happened".
                return ApiResponse::error($result['message'], $result['raw'], 422);
            }

            return ApiResponse::success($result['raw'], $result['message']);
        } catch (DeviceException $e) {
            return ApiResponse::error("Card enrollment failed: {$e->getMessage()}", [], 502);
        }
    }
}
