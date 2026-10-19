<?php

namespace App\Services\Integration;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Services\Devices\DeviceCommandBus;
use Illuminate\Support\Collection;

/**
 * Queues an employee onto terminals for the integration API.
 *
 * The SPA's `propagate` pushes inline because a person is watching the screen. An
 * integration is not, and rule 11 says device writes are queued, so this returns command
 * ids to follow (poll them, or take the `device_command.finished` webhook).
 *
 * A terminal that refuses writes is reported under `skipped` rather than failing the
 * whole call: saving the employee succeeded, and a partial result is the normal case
 * on an estate that mixes writable terminals with read-only ones.
 */
class EmployeeDevicePusher
{
    public function __construct(private readonly DeviceCommandBus $bus) {}

    /**
     * @param  iterable<Device>  $devices
     * @return array{commands:list<DeviceCommand>, skipped:list<array{device_id:int,device_name:string,reason:string}>}
     */
    public function push(Employee $employee, iterable $devices): array
    {
        $commands = [];
        $skipped = [];

        foreach ($devices as $device) {
            // A resigned employee is kept OFF the terminals (2026-10-12).
            if ($employee->isResigned()) {
                $skipped[] = ['device_id' => $device->id, 'device_name' => $device->name, 'reason' => "{$employee->name} has resigned, so is not put on terminals."];

                continue;
            }

            if ($refusal = $device->userWriteRefusal()) {
                $skipped[] = ['device_id' => $device->id, 'device_name' => $device->name, 'reason' => $refusal];

                continue;
            }

            $commands[] = $this->bus->dispatch($device, DeviceCommand::PUSH_USER, ['employee_id' => $employee->id]);
        }

        return ['commands' => $commands, 'skipped' => $skipped];
    }

    /** Terminals this employee is on right now. */
    public function mappedDevices(Employee $employee): Collection
    {
        return $employee->deviceMaps()->whereNull('valid_to')->with('device')->get()
            ->pluck('device')->filter()->unique('id')->values();
    }
}
