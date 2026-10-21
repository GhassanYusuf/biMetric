<?php

namespace App\Services\Devices;

use App\Exceptions\DeviceIdentityConflictException;
use App\Exceptions\PinInUseException;
use App\Models\AttendanceLog;
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
 * RETIRED PINS ARE NEVER REISSUED AUTOMATICALLY. When a mapping is closed, the row
 * stays with a valid_to date. Attendance already collected under that PIN belongs to
 * whoever held it at the time, so nextFreePin() never hands a used number out.
 *
 * A number ASKED FOR (the Personnel ID) may take over a retired one, once its history
 * is closed off (owner, 2026-10-21: a CPR typed onto the wrong employee, corrected, and
 * then refused to the right one for good — see pinReusableFor()). Punches resolve by
 * the mapping in force at each punch's own time (EmployeeDeviceMap::inForceAt), so a
 * new holder's window starting now cannot reach the previous holder's punches.
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

        // Someone added back with no number asked for gets the one they last had here,
        // when it was only ever theirs — not a new number for the same person.
        if ($preferred === null) {
            $last = EmployeeDeviceMap::query()
                ->where('device_id', $device->id)
                ->where('employee_id', $employee->id)
                ->whereNotNull('valid_to')
                ->orderByDesc('valid_to')
                ->value('device_user_id');

            if ($last !== null && $this->pinWasOnlyEverTheirs($device, $employee, (string) $last)) {
                $preferred = (string) $last;
            }
        }

        $returning = $preferred !== null && $this->pinWasOnlyEverTheirs($device, $employee, $preferred);
        // Taking over a retired number: its roster entry is theirs from now, like a
        // returning holder's, or the slot guard would refuse their own record later.
        $reissued = $preferred !== null && ! $returning && $this->pinReusableFor($device, $employee, $preferred);

        if ($preferred !== null && ! $returning) {
            $this->assertPinFreeFor($device, $employee, $preferred);
        }
        $pin = $preferred ?? $this->nextFreePin($device);

        if ($returning || $reissued) {
            // Their reservation becomes their claim again, so the slot guard and the
            // next roster read recognise the record as theirs, not a stranger's.
            DeviceRosterEntry::query()
                ->where('device_id', $device->id)
                ->where('device_user_id', $pin)
                ->update([
                    'claim_status' => DeviceRosterEntry::CLAIMED,
                    'claimed_employee_id' => $employee->id,
                    'ignore_reason' => null,
                ]);
        }

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

    /**
     * Refuses a PIN this employee may not move to on this device.
     *
     * Looser than "never used here" in two ways: a PIN this SAME employee held here
     * before is allowed back, so correcting a code typo (100 → 1000 → 100) works; and
     * a PIN others held whose history is closed off (pinReusableFor) may be taken over,
     * so a code given to the wrong person can still go to the right one. Either way no
     * punch is re-attributed. A PIN someone holds now, one with punches after its last
     * holder left, or one sitting unclaimed or claimed by someone else in the device's
     * roster, is refused.
     */
    public function assertPinFreeFor(Device $device, Employee $employee, string $pin): void
    {
        /*
         * THE ONE CHECK for "may this employee take this PIN here", used both before a
         * move deletes anything (moveByReadding, the propagator) and by claim() itself.
         * Until 2026-10-11 claim() used a stricter check of its own, so a move could pass
         * this one, delete the person, and then fail to claim — leaving them off the
         * terminal. Two checks for one question is how that happens; there is one now.
         *
         * Free when: nobody has ever held it here; or it was only ever theirs
         * (pinWasOnlyEverTheirs); or the terminal's roster entry for it is claimed by
         * them and no other employee was ever mapped to it.
         */
        $heldByOther = EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->where('device_user_id', $pin)
            ->where('employee_id', '!=', $employee->id)
            ->exists();

        $entry = DeviceRosterEntry::query()
            ->where('device_id', $device->id)
            ->where('device_user_id', $pin)
            ->first();

        $rosterOther = $entry !== null
            && ! ($entry->claim_status === DeviceRosterEntry::CLAIMED && (int) $entry->claimed_employee_id === (int) $employee->id)
            && ! $this->pinWasOnlyEverTheirs($device, $employee, $pin);

        if (($heldByOther || $rosterOther) && $this->pinReusableFor($device, $employee, $pin)) {
            return;
        }

        if ($heldByOther || $rosterOther) {
            // Releasable: the holder is a terminal record no employee was ever mapped to.
            $releasable = ! $heldByOther && $entry !== null
                && ($entry->claimed_employee_id === null
                    || Employee::query()->whereKey($entry->claimed_employee_id)->doesntExist());

            throw new PinInUseException(
                "User ID {$pin} is already in use on {$device->name}"
                .($entry !== null && $entry->device_name ? " (by \"{$entry->device_name}\")" : '')
                .($releasable
                    ? ', a record not linked to any employee.'
                    : ', or was in the past and its history is not closed off — it is still held, or a punch under it is dated after the last holder left, so giving it to someone else could move that punch to them.'),
                $pin,
                $entry?->device_name,
                $releasable,
            );
        }
    }

    /**
     * True when $pin on this terminal was only ever this employee's: we mapped it to
     * them before, never to anyone else, and the terminal's roster entry for it (if
     * any) is the reservation a removal leaves behind or their own claim.
     *
     * "Never reissued" exists so a number's punches cannot move to a DIFFERENT person.
     * Giving it back to the same person is the opposite of that — their old punches
     * are already theirs. Without this, removing someone and adding them back put them
     * on a new number (Akram on IT Room, 2026-10-11: 440105943 refused as "taken",
     * given PIN 2). A stranger enrolled at the keypad under the number since is still
     * caught: the hardware read inside saveUser() finds them and the slot guard refuses.
     */
    private function pinWasOnlyEverTheirs(Device $device, Employee $employee, string $pin): bool
    {
        $holders = EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->where('device_user_id', $pin)
            ->distinct()
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($holders !== [(int) $employee->id]) {
            return false;
        }

        $entry = DeviceRosterEntry::query()
            ->where('device_id', $device->id)
            ->where('device_user_id', $pin)
            ->first();

        return $entry === null
            || ($entry->claim_status === DeviceRosterEntry::CLAIMED && (int) $entry->claimed_employee_id === (int) $employee->id)
            || ($entry->claim_status === DeviceRosterEntry::IGNORED && $entry->claimed_employee_id === null);
    }

    /**
     * True when a number other employees held here may be given to $employee now,
     * without any punch changing hands (owner, 2026-10-21; casebook DC-026).
     *
     * Attendance resolves by the mapping in force at each punch's own time, so a new
     * mapping starting now reaches only punches from now on. That is safe when:
     *
     *   1. another employee did hold it, and every one of those mappings is CLOSED —
     *      nobody holds it now (a live holder is a person, not history);
     *   2. no stored punch under it is dated at or after the last of them ended. Such a
     *      punch is either unattributed in the gap or wrongly dated (a terminal clock
     *      far ahead — Camelot has punches stamped 2030), and either could land in the
     *      new holder's window on the next attendance:reattribute;
     *   3. the terminal's roster entry for it, if any, is a removal's reservation or
     *      already this employee's — never someone else's claim.
     *
     * The hardware is still checked at write time: saveUser()'s slot guard refuses if
     * anybody is actually on the terminal under the number.
     */
    private function pinReusableFor(Device $device, Employee $employee, string $pin): bool
    {
        $others = EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->where('device_user_id', $pin)
            ->where('employee_id', '!=', $employee->id)
            ->get(['valid_to']);

        if ($others->isEmpty() || $others->contains(fn (EmployeeDeviceMap $m) => $m->valid_to === null)) {
            return false;
        }

        $lastEnded = $others->max('valid_to');

        $laterPunch = AttendanceLog::query()
            ->where('device_id', $device->id)
            ->where('device_user_id', $pin)
            ->where('punched_at', '>=', $lastEnded)
            ->exists();

        if ($laterPunch) {
            return false;
        }

        $entry = DeviceRosterEntry::query()
            ->where('device_id', $device->id)
            ->where('device_user_id', $pin)
            ->first();

        return $entry === null
            || ($entry->claim_status === DeviceRosterEntry::IGNORED && $entry->claimed_employee_id === null)
            || ($entry->claim_status === DeviceRosterEntry::CLAIMED && (int) $entry->claimed_employee_id === (int) $employee->id);
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
}
