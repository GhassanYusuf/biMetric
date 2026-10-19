<?php

namespace App\Services\Integration;

use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Services\Devices\DeviceCommandBus;
use Illuminate\Support\Str;

/**
 * Deleting an employee AND taking them off the terminals, for the integration API.
 *
 * THE ORDER PROBLEM. Removing a person from a terminal is queued device work (rule 11)
 * and the command handler looks the employee up by id. Deleting the row first would make
 * every removal fail with "Employee no longer exists", and leave them able to open the
 * door. So the row is deleted LAST: the removals are queued, the employee is made
 * inactive straight away, and `commandFinished()` deletes the row once every removal has
 * succeeded.
 *
 * If a removal FAILS the row is kept, inactive, and the failure is reported
 * (`device_command.finished` webhook, the command row). Deleting the record while the
 * person is still enrolled on a terminal would orphan a working door credential that
 * nothing in this system could then find — rule 10 again: do not claim more than the
 * hardware confirmed.
 *
 * A terminal that cannot be written to (unadopted, read-only) cannot be cleaned up, so
 * it is reported as `skipped` and the row is kept for the same reason.
 */
class EmployeeOffboarding
{
    /** Payload key marking a REMOVE_USER as the first half of a delete; the value is the batch id. */
    public const DELETE_AFTER = 'delete_employee_after';

    public function __construct(private readonly DeviceCommandBus $bus) {}

    /**
     * @return array{deleted:bool, pending:bool, commands:list<DeviceCommand>, skipped:list<array{device_id:int,device_name:string,reason:string}>}
     */
    public function remove(Employee $employee, bool $fromDevices): array
    {
        if (! $fromDevices) {
            $employee->delete();

            return ['deleted' => true, 'pending' => false, 'commands' => [], 'skipped' => []];
        }

        // Ties this delete's removals together, so an older attempt that failed (and was
        // since fixed by hand) cannot hold up — or be mistaken for part of — a new one.
        ['commands' => $commands, 'skipped' => $skipped] = $this->takeOffTerminals($employee, [
            self::DELETE_AFTER => (string) Str::uuid(),
        ]);

        if ($commands === [] && $skipped === []) {
            $employee->delete(); // on no terminal at all — nothing to clean up

            return ['deleted' => true, 'pending' => false, 'commands' => [], 'skipped' => []];
        }

        // Off the door list in the meantime: inactive is the one state this system
        // already treats as "must not clock in".
        $employee->forceFill(['is_active' => false])->save();

        return ['deleted' => false, 'pending' => $commands !== [], 'commands' => $commands, 'skipped' => $skipped];
    }

    /**
     * Queues REMOVE_USER on every terminal the employee is live on, and keeps the row.
     * Used by delete-and-remove above and by a resignation (2026-10-12): a person who
     * has left must not be able to open the door, but their record and attendance stay.
     *
     * Each removal runs DeviceUserSyncService::removeEmployee(), which saves their card
     * and fingerprints into the system first and refuses if it cannot (rule 19), so a
     * rehire gets them back. A terminal that cannot be written to is `skipped`, never
     * silently counted as done.
     *
     * @param  array<string,mixed>  $payload  extra command payload
     * @return array{commands:list<DeviceCommand>, skipped:list<array{device_id:int,device_name:string,reason:string}>}
     */
    public function takeOffTerminals(Employee $employee, array $payload = []): array
    {
        $commands = [];
        $skipped = [];

        $maps = $employee->deviceMaps()->whereNull('valid_to')->with('device')->get();

        foreach ($maps->pluck('device')->filter()->unique('id') as $device) {
            if ($refusal = $device->userWriteRefusal()) {
                $skipped[] = ['device_id' => $device->id, 'device_name' => $device->name, 'reason' => $refusal];

                continue;
            }

            $commands[] = $this->bus->dispatch($device, DeviceCommand::REMOVE_USER, ['employee_id' => $employee->id] + $payload);
        }

        return ['commands' => $commands, 'skipped' => $skipped];
    }

    /** Called by the observer when a command reaches a final state. */
    public function commandFinished(DeviceCommand $command): void
    {
        if ($command->command !== DeviceCommand::REMOVE_USER
            || empty($command->payload[self::DELETE_AFTER])
            || $command->status !== DeviceCommand::STATUS_SUCCEEDED) {
            return;
        }

        $employeeId = $command->payload['employee_id'] ?? null;
        $batch = $command->payload[self::DELETE_AFTER];

        // Every removal that was queued for this delete must have succeeded. One still
        // running, or one that failed, keeps the row.
        $outstanding = DeviceCommand::withoutGlobalScopes()
            ->where('tenant_id', $command->tenant_id)
            ->where('command', DeviceCommand::REMOVE_USER)
            ->where('status', '!=', DeviceCommand::STATUS_SUCCEEDED)
            ->get()
            ->contains(fn (DeviceCommand $c) => ($c->payload[self::DELETE_AFTER] ?? null) === $batch);

        if ($outstanding) {
            return;
        }

        Employee::withoutGlobalScopes()
            ->where('tenant_id', $command->tenant_id)
            ->whereKey($employeeId)
            ->first()
            ?->delete();
    }
}
