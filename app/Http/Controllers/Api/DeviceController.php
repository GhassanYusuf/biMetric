<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Device\StoreDeviceRequest;
use App\Http\Requests\Api\Device\UpdateDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Services\Zkteco\DeviceSyncService;
use App\Services\Zkteco\ZktecoException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class DeviceController extends Controller
{
    public function __construct(private readonly DeviceSyncService $sync)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        $devices = Device::query()->withCount('attendanceLogs')->latest()->get();

        return ApiResponse::success(DeviceResource::collection($devices), 'Devices retrieved.');
    }

    public function store(StoreDeviceRequest $request): JsonResponse
    {
        $this->authorize('create', Device::class);

        $data = $request->validated();
        $device = Device::create($data + ['port' => $data['port'] ?? 4370]);

        return ApiResponse::created(new DeviceResource($device), 'Device added.');
    }

    public function show(Device $device): JsonResponse
    {
        $this->authorize('view', $device);

        $device->loadCount('attendanceLogs');

        return ApiResponse::success(new DeviceResource($device), 'Device retrieved.');
    }

    public function update(UpdateDeviceRequest $request, Device $device): JsonResponse
    {
        $this->authorize('update', $device);

        $data = $request->validated();

        // Secrets are never sent back to the client, so an empty submission
        // means "leave unchanged", not "clear it".
        foreach (['comm_key', 'web_password'] as $secret) {
            if (($data[$secret] ?? '') === '') {
                unset($data[$secret]);
            }
        }

        $device->update($data);

        return ApiResponse::success(new DeviceResource($device->fresh()), 'Device updated.');
    }

    public function destroy(Device $device): JsonResponse
    {
        $this->authorize('delete', $device);

        $device->delete();

        return ApiResponse::success(null, 'Device removed.');
    }

    public function testConnection(Device $device): JsonResponse
    {
        $this->authorize('sync', $device);

        try {
            $result = $this->sync->testConnection($device);

            return ApiResponse::success($result, $result['device_time']
                ? "Connected. Device time: {$result['device_time']}"
                : 'Connected. ' . ($result['note'] ?? ''));
        } catch (ZktecoException $e) {
            return ApiResponse::error("Connection failed: {$e->getMessage()}", [], 502);
        }
    }

    public function syncAttendance(Device $device): JsonResponse
    {
        $this->authorize('sync', $device);

        try {
            $count = $this->sync->syncAttendance($device);

            return ApiResponse::success(['imported' => $count], "Sync complete: {$count} new punch(es) imported.");
        } catch (ZktecoException $e) {
            return ApiResponse::error("Sync failed: {$e->getMessage()}", [], 502);
        }
    }

    /**
     * Refreshes fingerprint counts and card numbers for the WHOLE roster from
     * this terminal in a single connection. This is what makes the employee list
     * device-sourced without opening one connection per row.
     */
    public function syncEnrollment(Device $device): JsonResponse
    {
        $this->authorize('sync', $device);

        set_time_limit(120);

        try {
            $result = $this->sync->syncEnrollmentStatus($device);

            // Be explicit when the template table was unreadable: the card
            // numbers are still fresh, but the fingerprint counts are not, and
            // saying "done" would imply otherwise.
            $message = $result['fingerprints_known']
                ? "Read {$result['updated']} employee record(s) from {$device->name}."
                : "Read {$result['updated']} employee record(s) from {$device->name}, but this firmware's fingerprint table could not be decoded — counts are left as unknown.";

            return ApiResponse::success($result, $message);
        } catch (ZktecoException $e) {
            return ApiResponse::error("Enrollment read failed: {$e->getMessage()}", [], 502);
        }
    }

    public function importEmployees(Device $device): JsonResponse
    {
        $this->authorize('sync', $device);

        try {
            $result = $this->sync->importEmployees($device);

            return ApiResponse::success($result, "Imported employees: {$result['created']} added, {$result['already_existed']} existed.");
        } catch (ZktecoException $e) {
            return ApiResponse::error("Employee import failed: {$e->getMessage()}", [], 502);
        }
    }
}
