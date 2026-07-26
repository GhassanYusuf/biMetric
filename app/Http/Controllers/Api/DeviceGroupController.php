<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceGroupResource;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceGroup;
use App\Services\Devices\DeviceCommandBus;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Device groups, so an operation can target a set of terminals at once.
 *
 * Authorised by the devices.* PERMISSIONS rather than DevicePolicy: a group is not
 * a Device, so the policy methods that require an instance cannot judge it.
 */
class DeviceGroupController extends Controller
{
    public function __construct(private readonly DeviceCommandBus $bus)
    {
    }

    /**
     * Authorises a group action by PERMISSION, not by DevicePolicy.
     *
     * A DeviceGroup is not a Device, so DevicePolicy::update()/delete() cannot judge
     * it — they require a Device instance and threw "Too few arguments" when handed
     * the class name. Managing groups is governed by the same permissions as managing
     * devices, so the permission is checked directly.
     */
    private function authorizeDevicePermission(string $permission): void
    {
        abort_unless(
            request()->user()?->can($permission) ?? false,
            403,
            'This action is unauthorized.',
        );
    }

    public function index(): JsonResponse
    {
        $this->authorizeDevicePermission('devices.view');

        $groups = DeviceGroup::query()->withCount('devices')->orderBy('name')->get();

        return ApiResponse::success(DeviceGroupResource::collection($groups), 'Device groups retrieved.');
    }

    public function show(DeviceGroup $deviceGroup): JsonResponse
    {
        $this->authorizeDevicePermission('devices.view');

        $deviceGroup->load('devices')->loadCount('devices');

        return ApiResponse::success(new DeviceGroupResource($deviceGroup), 'Device group retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeDevicePermission('devices.create');

        $data = $request->validate([
            // Unique per TENANT, not globally — two companies may both have a "Main Gate".
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('device_groups', 'name')->where('tenant_id', \App\Support\Tenancy::id()),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'device_ids' => ['array'],
            'device_ids.*' => ['integer', 'exists:devices,id'],
        ]);

        $group = DeviceGroup::create([
            'tenant_id' => \App\Support\Tenancy::id(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        if (! empty($data['device_ids'])) {
            $group->devices()->sync($this->tenantScopedDeviceIds($data['device_ids']));
        }

        return ApiResponse::created(
            new DeviceGroupResource($group->load('devices')->loadCount('devices')),
            "Group {$group->name} created.",
        );
    }

    public function update(Request $request, DeviceGroup $deviceGroup): JsonResponse
    {
        $this->authorizeDevicePermission('devices.update');

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('device_groups', 'name')
                    ->where('tenant_id', \App\Support\Tenancy::id())
                    ->ignore($deviceGroup->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $deviceGroup->update($data);

        return ApiResponse::success(
            new DeviceGroupResource($deviceGroup->load('devices')->loadCount('devices')),
            'Group updated.',
        );
    }

    /** Deleting a group never deletes its terminals — only the grouping. */
    public function destroy(DeviceGroup $deviceGroup): JsonResponse
    {
        $this->authorizeDevicePermission('devices.delete');

        $name = $deviceGroup->name;
        $deviceGroup->delete();

        return ApiResponse::success(null, "Group {$name} removed. Its devices were not affected.");
    }

    /** Replaces the group's membership wholesale. */
    public function syncDevices(Request $request, DeviceGroup $deviceGroup): JsonResponse
    {
        $this->authorizeDevicePermission('devices.update');

        $data = $request->validate([
            'device_ids' => ['present', 'array'],
            'device_ids.*' => ['integer', 'exists:devices,id'],
        ]);

        $deviceGroup->devices()->sync($this->tenantScopedDeviceIds($data['device_ids']));

        return ApiResponse::success(
            new DeviceGroupResource($deviceGroup->load('devices')->loadCount('devices')),
            'Group membership updated.',
        );
    }

    public function addDevice(DeviceGroup $deviceGroup, Device $device): JsonResponse
    {
        $this->authorizeDevicePermission('devices.update');

        $deviceGroup->devices()->syncWithoutDetaching([$device->id]);

        return ApiResponse::success(null, "{$device->name} added to {$deviceGroup->name}.");
    }

    public function removeDevice(DeviceGroup $deviceGroup, Device $device): JsonResponse
    {
        $this->authorizeDevicePermission('devices.update');

        $deviceGroup->devices()->detach($device->id);

        return ApiResponse::success(null, "{$device->name} removed from {$deviceGroup->name}.");
    }

    /**
     * Queues one command for every addressable member.
     *
     * One command PER DEVICE rather than one for the group: a group operation that
     * half-succeeds has to be legible per terminal, and retrying must not re-hit the
     * devices that already worked.
     */
    public function dispatchCommand(Request $request, DeviceGroup $deviceGroup): JsonResponse
    {
        $this->authorizeDevicePermission('devices.update');

        $data = $request->validate([
            'command' => ['required', 'string', Rule::in([
                DeviceCommand::SYNC_ATTENDANCE,
                DeviceCommand::SYNC_ENROLLMENT,
                DeviceCommand::HEALTH_CHECK,
                DeviceCommand::READ_INFO,
                DeviceCommand::RECONCILE_USERS,
                DeviceCommand::PROBE_CAPABILITIES,
                DeviceCommand::SYNC_TIME,
            ])],
            'payload' => ['array'],
        ]);

        $commands = $this->bus->dispatchToGroup($deviceGroup, $data['command'], $data['payload'] ?? []);

        if ($commands === []) {
            return ApiResponse::error(
                "{$deviceGroup->name} has no active Binary/TCP-IP terminals to run that on.",
                [],
                422,
            );
        }

        return ApiResponse::success(
            ['command_ids' => array_map(fn ($c) => $c->id, $commands)],
            sprintf('Queued %s on %d device(s) in %s.', $data['command'], count($commands), $deviceGroup->name),
        );
    }

    /**
     * Filters submitted ids through the tenant scope.
     *
     * `exists:devices,id` does NOT respect the global tenant scope, so without this a
     * caller could attach another tenant's device by guessing its id.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function tenantScopedDeviceIds(array $ids): array
    {
        return Device::query()->whereIn('id', $ids)->pluck('id')->all();
    }
}
