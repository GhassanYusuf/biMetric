<?php

namespace App\Services\Devices;

use App\Exceptions\DeviceIdentityConflictException;
use App\Models\Device;
use App\Models\DeviceRosterEntry;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use Promoseven\Biometric\Value\DeviceUser;

/**
 * The single answer to "what is this employee's PIN on THIS device".
 *
 * WHY IT EXISTS: every device call used to address people by $employee->employee_code.
 * That conflated three different things into one number:
 *
 *   1. the payroll identity of a human        (employee_code / national id)
 *   2. their employment at one company        (employees row)
 *   3. their ADDRESS in one device's table    (the PIN)
 *
 * Only (3) is what a terminal understands, and it is local to that terminal. Two
 * devices installed years apart assign PIN 5 to two unrelated people, and neither is
 * wrong — the PIN was never meant to be a global identifier. Treating it as one
 * produced both production failures on 2026-09-15: pushing by payroll code overwrote
 * strangers' enrollments, and adding a second terminal cross-matched two different
 * humans who happened to share a number.
 *
 * So: a PIN is allocated once per (device, employee), recorded in employee_device_maps,
 * and never re-derived from anything. Nothing downstream may reconstruct it.
 *
 * RETIRED PINS ARE NEVER REISSUED. When a mapping is closed, the row stays with a
 * valid_to date. Attendance already collected under that PIN belongs to whoever held
 * it at the time, and handing the number to a new hire would silently re-attribute
 * their history.
 */
class DeviceIdentityResolver
{
    /**
     * The PIN this employee is known by on this device.
     *
     * Throws rather than inventing one. A caller that wants to create the identity
     * asks for it explicitly via claim().
     */
    public function pinFor(Device $device, Employee $employee): string
    {
        $map = $this->activeMap($device, $employee);

        if ($map === null) {
            throw new \RuntimeException(
                "{$employee->name} has no identity on {$device->name}. Allocate one before writing to the device."
            );
        }

        return $map->device_user_id;
    }

    /** The active mapping, or null when the employee has no live identity here. */
    public function activeMap(Device $device, Employee $employee): ?EmployeeDeviceMap
    {
        return EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->whereNull('valid_to')
            ->first();
    }

    /**
     * Allocates this employee an identity on this device, without touching the device.
     *
     * The caller is responsible for calling guardSlot() inside the session before it
     * actually writes — allocation reserves the number in OUR records, and only a live
     * read can prove the slot is free on the hardware.
     *
     * $preferred lets an operator ask for a specific number. It is a request, not a
     * guarantee: a PIN already used on this device — now or at any point in the past —
     * is refused rather than reused.
     */
    public function claim(Device $device, Employee $employee, ?string $preferred = null): EmployeeDeviceMap
    {
        if ($existing = $this->activeMap($device, $employee)) {
            return $existing;
        }

        $pin = $preferred !== null
            ? $this->assertPinAvailable($device, $preferred)
            : $this->nextFreePin($device);

        return EmployeeDeviceMap::create([
            'tenant_id' => $device->tenant_id,
            'employee_id' => $employee->id,
            'device_id' => $device->id,
            'device_user_id' => $pin,
            'valid_from' => now(),
            'reconciliation_status' => EmployeeDeviceMap::MISSING_ON_DEVICE,
        ]);
    }

    /**
     * Refuses a write whose target slot is occupied by somebody else.
     *
     * Takes the occupant the driver just read, NOT a device session — the decision is
     * pure, so it is testable without hardware and this class never speaks protocol
     * (rule 8). The driver calls it between its read and its write, inside one session,
     * which is what makes the check atomic; see BiometricDeviceDriver::saveUser().
     *
     * A null occupant means the slot is free. A read that THREW never reaches here at
     * all — the exception propagates and the write does not happen, which is the safe
     * direction (rule 9: a failed probe is not evidence of absence).
     */
    public function assertSlotFree(Device $device, Employee $employee, string $pin, ?DeviceUser $occupant): void
    {
        if ($occupant === null) {
            return;
        }

        if ($this->slotBelongsTo($device, $employee, $pin)) {
            return;
        }

        throw DeviceIdentityConflictException::occupied($device, $pin, $occupant->name, $employee->name);
    }

    /**
     * The guard closure to hand to BiometricDeviceDriver::saveUser().
     *
     * Exists so no caller has to remember the argument order of the check — a guard
     * that is subtly wrong is worse than none, because it reads as protection.
     */
    public function slotGuard(Device $device, Employee $employee, string $pin): \Closure
    {
        return fn (?DeviceUser $occupant) => $this->assertSlotFree($device, $employee, $pin, $occupant);
    }

    /**
     * Lowest PIN not used on this device by anyone, ever.
     *
     * Starts at 1 because 0 is not addressable on this firmware family.
     */
    public function nextFreePin(Device $device): string
    {
        $taken = $this->pinsEverUsed($device);

        for ($pin = 1; $pin <= 65534; $pin++) {
            if (! isset($taken[(string) $pin])) {
                return (string) $pin;
            }
        }

        throw new \RuntimeException("No free PIN remains on {$device->name}.");
    }

    // ------------------------------------------------------------------ internals

    /**
     * True when an OCCUPIED slot is genuinely this employee's.
     *
     * Two things must agree, and the second is the one that matters:
     *
     *   1. our live mapping says this employee holds this PIN, AND
     *   2. the device's own roster — what was actually read off the hardware — either
     *      has no record of that PIN, or records it as claimed by this same employee.
     *
     * The mapping alone is not enough. It is OUR belief, and it can be stale: a PIN
     * reassigned on the terminal keypad, a mapping written by the broken auto-matching
     * this class replaced, or a roster entry an operator marked ignored. In every one
     * of those the hardware holds somebody our records do not know about, and trusting
     * the mapping would authorise writing straight over them.
     *
     * So when our belief and the hardware disagree, the hardware wins and the write is
     * refused. Rule 3: never claim more than the device told us.
     */
    private function slotBelongsTo(Device $device, Employee $employee, string $pin): bool
    {
        $mapped = EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->where('device_user_id', $pin)
            ->where('employee_id', $employee->id)
            ->whereNull('valid_to')
            ->exists();

        if (! $mapped) {
            return false;
        }

        $entry = DeviceRosterEntry::query()
            ->where('device_id', $device->id)
            ->where('device_user_id', $pin)
            ->first();

        // No roster entry: the slot was empty when we last read, and this employee was
        // allocated it. The occupant the driver just found is therefore expected.
        if ($entry === null) {
            return true;
        }

        return $entry->claim_status === DeviceRosterEntry::CLAIMED
            && $entry->claimed_employee_id === $employee->id;
    }

    /** @return array<string,true> every PIN this device has ever handed out */
    private function pinsEverUsed(Device $device): array
    {
        $used = [];

        foreach (EmployeeDeviceMap::query()->where('device_id', $device->id)->pluck('device_user_id') as $pin) {
            $used[(string) $pin] = true;
        }

        // Unclaimed people already on the hardware hold PINs too. Allocating over one
        // is exactly the overwrite this class exists to prevent.
        foreach (DeviceRosterEntry::query()->where('device_id', $device->id)->pluck('device_user_id') as $pin) {
            $used[(string) $pin] = true;
        }

        return $used;
    }

    private function assertPinAvailable(Device $device, string $pin): string
    {
        if (isset($this->pinsEverUsed($device)[$pin])) {
            throw new \RuntimeException(
                "PIN {$pin} is already in use on {$device->name}, or was in the past. "
                ."PINs are never reissued — attendance already recorded under that number belongs to whoever held it."
            );
        }

        return $pin;
    }
}
