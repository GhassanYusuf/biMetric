<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceCommandResource;
use App\Http\Resources\EmployeeDeviceMapResource;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\EmployeeDeviceMap;
use App\Services\Devices\DeviceCommandBus;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Employee-to-device mappings and the reconciliation report.
 *
 * Reconciliation is READ-ONLY by design. Auto-resolving would mean deciding whose
 * version wins, and getting that wrong either deletes a real enrollment or
 * resurrects a departed employee's access. This surfaces the discrepancies; an
 * operator decides what to do about each one.
 */
class DeviceReconciliationController extends Controller
{
    public function __construct(private readonly DeviceCommandBus $bus)
    {
    }

    /** Mappings, optionally filtered to those needing attention. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        $request->validate([
            'device_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'needs_attention' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $maps = EmployeeDeviceMap::query()
            ->with(['employee:id,name,employee_code', 'device:id,name'])
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->integer('device_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('reconciliation_status', $request->string('status')))
            ->when(
                $request->boolean('needs_attention'),
                fn ($q) => $q->where('reconciliation_status', '!=', EmployeeDeviceMap::IN_SYNC),
            )
            ->orderBy('device_id')
            ->paginate($request->integer('per_page', 50));

        return ApiResponse::success(EmployeeDeviceMapResource::collection($maps), 'Device mappings retrieved.');
    }

    /**
     * A summary of what disagrees, per device.
     *
     * Reads STORED reconciliation state — no device contact — so this is safe to poll
     * and instant to render. Run the reconcile command to refresh it.
     */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        $devices = Device::query()->directlyAddressable()->get();
        $summary = [];

        foreach ($devices as $device) {
            $counts = EmployeeDeviceMap::query()
                ->where('device_id', $device->id)
                ->selectRaw('reconciliation_status, count(*) as total')
                ->groupBy('reconciliation_status')
                ->pluck('total', 'reconciliation_status')
                ->all();

            $unattributed = \App\Models\AttendanceLog::query()
                ->where('device_id', $device->id)
                ->whereNull('employee_id')
                ->count();

            $summary[] = [
                'device_id' => $device->id,
                'device_name' => $device->name,
                'in_sync' => $counts[EmployeeDeviceMap::IN_SYNC] ?? 0,
                'missing_on_device' => $counts[EmployeeDeviceMap::MISSING_ON_DEVICE] ?? 0,
                'missing_in_system' => $counts[EmployeeDeviceMap::MISSING_IN_SYSTEM] ?? 0,
                'mismatched' => $counts[EmployeeDeviceMap::MISMATCHED] ?? 0,
                /*
                 * Punches whose device user id maps to nobody — typically former staff
                 * whose record was removed from the terminal. The punches are KEPT with
                 * a null employee rather than discarded, so payroll history stays
                 * complete and the gap is visible instead of silent.
                 */
                'unattributed_punches' => $unattributed,
                'last_reconciled_at' => EmployeeDeviceMap::query()
                    ->where('device_id', $device->id)
                    ->max('synced_at'),
            ];
        }

        return ApiResponse::success($summary, 'Reconciliation summary generated.');
    }

    /** Queues a fresh reconciliation read against the device. */
    public function reconcile(Device $device): JsonResponse
    {
        $this->authorize('sync', $device);

        $command = $this->bus->dispatch($device, DeviceCommand::RECONCILE_USERS);

        return ApiResponse::success(
            new DeviceCommandResource($command),
            "Queued a reconciliation read of {$device->name}.",
        );
    }

    /** Queues pushing one employee onto the device — resolves missing_on_device. */
    public function pushEmployee(Device $device, \App\Models\Employee $employee): JsonResponse
    {
        $this->authorize('pushToDevice', $employee);

        $command = $this->bus->dispatch($device, DeviceCommand::PUSH_USER, [
            'employee_id' => $employee->id,
        ]);

        return ApiResponse::success(
            new DeviceCommandResource($command),
            "Queued writing {$employee->name} to {$device->name}.",
        );
    }

    /** Queues importing device users the system does not know — resolves missing_in_system. */
    public function importUnknown(Device $device): JsonResponse
    {
        $this->authorize('sync', $device);

        $command = $this->bus->dispatch($device, DeviceCommand::IMPORT_EMPLOYEES);

        return ApiResponse::success(
            new DeviceCommandResource($command),
            "Queued importing unknown users from {$device->name} as employees.",
        );
    }
}
