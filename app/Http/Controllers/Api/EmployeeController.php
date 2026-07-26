<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Employee\StoreEmployeeRequest;
use App\Http\Requests\Api\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Device;
use App\Models\Employee;
use App\Services\Attendance\AttendanceSummaryService;
use App\Services\Zkteco\DeviceSyncService;
use App\Services\Zkteco\ZktecoException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmployeeController extends Controller
{
    public function __construct(private readonly DeviceSyncService $sync)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $employees = Employee::query()
            ->withCount('attendanceLogs')
            ->with('department:id,name')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->string('search')->trim() . '%';
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

        $employee->loadCount('attendanceLogs')->load('department:id,name');

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
            $this->sync->pushEmployeeToDevice($device, $employee);

            return ApiResponse::success(null, "{$employee->name} enrolled on {$device->name}.");
        } catch (ZktecoException $e) {
            return ApiResponse::error("Failed to push to device: {$e->getMessage()}", [], 502);
        }
    }

    public function removeFromDevice(Employee $employee, Device $device): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        try {
            $this->sync->removeEmployeeFromDevice($device, $employee);

            return ApiResponse::success(null, "{$employee->name} removed from {$device->name}.");
        } catch (ZktecoException $e) {
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
                $this->sync->readEmployeeEnrollment($device, $employee),
                "Read {$employee->name}'s enrollment from {$device->name}.",
            );
        } catch (ZktecoException $e) {
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
            $result = $this->sync->enrollFingerprint($device, $employee, (int) $data['finger_index']);

            if (! ($result['success'] ?? false)) {
                // A capture error is the device's answer, not a transport fault:
                // report it as a failed operation rather than a 5xx.
                return ApiResponse::error(
                    "The device reported a capture error (code {$result['error_code']}). Ask them to place the finger again.",
                    [],
                    422,
                );
            }

            return ApiResponse::success(
                $result,
                "Fingerprint captured for {$employee->name} on {$device->name}.",
            );
        } catch (ZktecoException $e) {
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
            $result = $this->sync->clearCard($device, $employee);

            if (! $result['cleared']) {
                return ApiResponse::error(
                    "{$employee->name} has no card on {$device->name}, so there was nothing to remove.",
                    [],
                    422,
                );
            }

            return ApiResponse::success(
                $result,
                "Removed card #{$result['previous_card']} from {$employee->name} on {$device->name}.",
            );
        } catch (ZktecoException $e) {
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
            $result = $this->sync->deleteFingerprint($device, $employee, $slot);

            if (! $result['deleted']) {
                return ApiResponse::error(
                    "Slot {$slot} is not enrolled for {$employee->name} on {$device->name}, so there was nothing to delete.",
                    [],
                    422,
                );
            }

            $remaining = count($result['slots']);

            return ApiResponse::success(
                $result,
                "Deleted slot {$slot} for {$employee->name}. "
                    . ($remaining === 0
                        ? 'They now have no fingerprints on this terminal.'
                        : "{$remaining} fingerprint(s) remain."),
            );
        } catch (ZktecoException $e) {
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
            $result = $this->sync->enrollCard($device, $employee);

            return ApiResponse::success(
                $result,
                "Card enrolled for {$employee->name} on {$device->name} (card #{$result['card_number']}).",
            );
        } catch (ZktecoException $e) {
            return ApiResponse::error("Card enrollment failed: {$e->getMessage()}", [], 502);
        }
    }
}
