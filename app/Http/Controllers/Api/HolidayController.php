<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Holiday\StoreHolidayRequest;
use App\Http\Requests\Api\Holiday\UpdateHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\Holiday;
use App\Services\Settings\TenantSettings;
use App\Support\ApiResponse;
use App\Support\AttendanceRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HolidayController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Holiday::class);

        $holidays = Holiday::query()->orderBy('date')->get();

        return ApiResponse::success(HolidayResource::collection($holidays), 'Holidays retrieved.');
    }

    public function store(StoreHolidayRequest $request): JsonResponse
    {
        $this->authorize('create', Holiday::class);

        $holiday = Holiday::create($request->validated());

        return ApiResponse::created(new HolidayResource($holiday), 'Holiday added.');
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday): JsonResponse
    {
        $this->authorize('update', $holiday);

        $holiday->update($request->validated());

        return ApiResponse::success(new HolidayResource($holiday), 'Holiday updated.');
    }

    /**
     * The company's attendance rules: the weekend, the shift, and the thresholds behind
     * Late, Left early and Left late. They live on the Holidays screen because they
     * answer the same question a holiday does — "when is this person expected at work?"
     * — with the same permissions. See App\Support\AttendanceRules.
     */
    public function rules(): JsonResponse
    {
        $this->authorize('viewAny', Holiday::class);

        return ApiResponse::success(AttendanceRules::current()->toArray(), 'Attendance rules retrieved.');
    }

    /** Any subset of the rules; what is sent is saved, the rest is left as it was. */
    public function updateRules(Request $request, TenantSettings $settings): JsonResponse
    {
        $this->authorize('create', Holiday::class);

        $minutes = ['sometimes', 'integer', 'between:0,240'];
        $data = $request->validate([
            'weekend_days' => ['sometimes', 'array', 'max:6'],
            'weekend_days.*' => ['integer', 'between:0,6', 'distinct'],
            'shift_start' => ['sometimes', 'date_format:H:i'],
            'shift_end' => ['sometimes', 'date_format:H:i'],
            'late_grace_minutes' => $minutes,
            'early_leave_grace_minutes' => $minutes,
            'leave_late_after_minutes' => $minutes,
        ], [
            'weekend_days.max' => 'At least one day of the week has to be a work day.',
            'shift_start.date_format' => 'Use 24-hour HH:MM, e.g. 07:30.',
            'shift_end.date_format' => 'Use 24-hour HH:MM, e.g. 16:30.',
        ]);

        // Checked against what will be stored, not only what was sent: moving just the
        // start past the existing end is as wrong as sending both backwards.
        $current = AttendanceRules::current();
        $start = AttendanceRules::minutes($data['shift_start'] ?? $current->shiftStart);
        $end = AttendanceRules::minutes($data['shift_end'] ?? $current->shiftEnd);
        if ($end <= $start) {
            throw ValidationException::withMessages(['shift_end' => ['The shift has to end after it starts. Overnight shifts are not supported yet.']]);
        }

        $writes = collect($data)->except('weekend_days')->mapWithKeys(fn ($v, $k) => ["attendance.{$k}" => $v])->all();
        if (array_key_exists('weekend_days', $data)) {
            $writes['attendance.work_days'] = array_values(array_diff(range(0, 6), $data['weekend_days']));
        }

        foreach ($writes as $key => $value) {
            try {
                $settings->set($key, $value);
            } catch (\InvalidArgumentException $e) {
                $field = $key === 'attendance.work_days' ? 'weekend_days' : substr($key, strlen('attendance.'));
                throw ValidationException::withMessages([$field => [$e->getMessage()]]);
            }
        }

        return ApiResponse::success(AttendanceRules::current()->toArray(), 'Attendance rules saved. Every report now counts against them.');
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $this->authorize('delete', $holiday);

        $holiday->delete();

        return ApiResponse::success(null, 'Holiday removed.');
    }
}
