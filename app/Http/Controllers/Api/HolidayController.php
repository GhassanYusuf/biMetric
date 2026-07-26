<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Holiday\StoreHolidayRequest;
use App\Http\Requests\Api\Holiday\UpdateHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\Holiday;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

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

    public function destroy(Holiday $holiday): JsonResponse
    {
        $this->authorize('delete', $holiday);

        $holiday->delete();

        return ApiResponse::success(null, 'Holiday removed.');
    }
}
