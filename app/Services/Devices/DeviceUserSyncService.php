<?php

namespace App\Services\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AccessCard;
use App\Models\BiometricTemplate;
use App\Models\Device;
use App\Models\DeviceRosterEntry;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Value\DeviceUser;

/**
 * Keeps employees and device user records in step, and reports where they disagree.
 *
 * The mapping is stored in employee_device_maps rather than inferred by comparing
 * employees.employee_code to the device's user id. String matching worked until a
 * code was renumbered or reused, at which point punches silently attached to the
 * wrong person — a payroll error nobody would notice.
 */
class DeviceUserSyncService
{
    public function __construct(
        private readonly BiometricDeviceDriver $driver,
        private readonly DeviceIdentityResolver $identity,
        private readonly DeviceAdoptionService $adoption,
    ) {}

    /**
     * Reads the device and refreshes the stored mapping + credential state.
     *
     * ONE device conversation for the whole roster. Reading per employee would open a
     * connection per row, which the hardware does not survive on a 100-person page.
     *
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function syncEnrollmentState(Device $device): array
    {
        $capabilities = $this->driver->capabilities($device);
        $capabilities->require(Capability::USER_READ);

        $withFingerprints = $capabilities->supports(Capability::TEMPLATE_TABLE_READ);

        /** @var list<DeviceUser> $deviceUsers */
        $deviceUsers = $this->driver->readUsers($device, $withFingerprints);

        /*
         * Resolution is by the STORED MAPPING for this device, never by string-matching
         * the device's user id against employees.employee_code.
         *
         * The old match was a guess: a PIN is a slot number local to one terminal, and
         * on a second device the same number belongs to an unrelated person. Matching on
         * it merged two different humans and attached one's punches to the other —
         * a payroll error with no visible symptom. See DeviceIdentityResolver.
         *
         * @var array<string,int> PIN on this device => employee id
         */
        $employeeByPin = EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->live()
            ->pluck('employee_id', 'device_user_id')
            ->all();

        $matched = 0;
        $unmatched = [];
        $fingerprintsKnown = false;

        foreach ($deviceUsers as $user) {
            $employeeId = $employeeByPin[$user->userId] ?? null;

            if ($employeeId === null) {
                // On the device, claimed by nobody. Recorded as evidence rather than
                // guessed at — their punches arrive unattributed until a human decides
                // who they are, and the PIN stays reserved so nothing is written over
                // them in the meantime.
                $this->recordRosterEntry($device, $user);
                $unmatched[] = ['device_user_id' => $user->userId, 'name' => $user->name];

                continue;
            }

            if ($user->fingerSlots !== null) {
                $fingerprintsKnown = true;
            }

            $this->upsertMap($device, $employeeId, $user);
            $this->recordCard($device, $employeeId, $user);
            $this->recordTemplates($device, $employeeId, $user);

            $matched++;
        }

        $this->markMissingOnDevice($device, $deviceUsers);

        $message = sprintf(
            'Read %d record(s) from %s: %d mapped to employees%s%s.',
            count($deviceUsers),
            $device->name,
            $matched,
            $unmatched === [] ? '' : ', '.count($unmatched).' awaiting a claim',
            $withFingerprints && ! $fingerprintsKnown
                ? ", but this firmware's fingerprint table could not be decoded so counts are left unknown"
                : '',
        );

        return [
            'message' => $message,
            'raw' => [
                'device_users' => count($deviceUsers),
                'matched' => $matched,
                'unmatched' => $unmatched,
                'fingerprints_known' => $fingerprintsKnown,
            ],
        ];
    }

    /**
     * Compares system and device, producing a report rather than changing anything.
     *
     * Reconciliation is deliberately READ-ONLY. Auto-resolving would mean deciding
     * whose version wins, and getting that wrong either deletes a real enrollment or
     * resurrects a departed employee's access. An operator decides; this only shows
     * them what to decide about.
     *
     * @return array{message:string, raw:array<string,mixed>}
     */
    /**
     * Live read of ONE employee's enrollment on a terminal.
     *
     * Powers the enrollment dialog's hand picker, so it runs when that dialog opens
     * — never on a list render, which would mean one device conversation per row.
     *
     * THE THREE STATES MATTER HERE MORE THAN ANYWHERE (rule 10):
     *   on_device false              → they are not on this terminal at all
     *   fingerprint_slots null       → the template table would not decode: UNKNOWN
     *   fingerprint_slots []         → the table decoded and they have no fingers
     *
     * A null must never be rendered as 0. The stored columns are only overwritten
     * when the table decoded, so an unreadable read leaves the last known answer
     * standing rather than erasing it.
     *
     * @return array<string,mixed>
     */
    public function readEnrollment(Device $device, Employee $employee): array
    {
        $capabilities = $this->driver->capabilities($device);
        $capabilities->require(Capability::USER_READ);

        $canReadTemplates = $capabilities->supports(Capability::TEMPLATE_TABLE_READ);

        /** @var list<DeviceUser> $deviceUsers */
        $deviceUsers = $this->driver->readUsers($device, $canReadTemplates);

        /*
         * The employee's PIN ON THIS DEVICE, from the stored mapping. employee_code is
         * a payroll number and says nothing about which slot a terminal filed them in.
         */
        $map = $this->identity->activeMap($device, $employee);

        $match = null;
        if ($map !== null) {
            foreach ($deviceUsers as $user) {
                if ($user->userId === $map->device_user_id) {
                    $match = $user;
                    break;
                }
            }
        }

        $stored = [
            'fingerprint_slots' => $employee->fingerprint_slots,
            'fingerprint_count' => $employee->fingerprint_count,
            'card_number' => $employee->card_number,
            'synced_at' => $employee->enrollment_synced_at?->toIso8601String(),
        ];

        if ($match === null) {
            return [
                'on_device' => false,
                'fingerprint_slots' => null,
                'fingerprint_count' => null,
                'card_number' => null,
                'card_count' => 0,
                'stored' => $stored,
                'synced_at' => $stored['synced_at'],
            ];
        }

        // null here means the table did not decode, NOT that there are no fingers.
        $slots = $canReadTemplates ? $match->fingerSlots : null;
        $cardNumber = $match->hasCard() ? $match->cardNumber : null;

        $attributes = [
            'card_number' => $cardNumber,
            'enrollment_synced_at' => now(),
        ];

        if ($slots !== null) {
            $attributes['fingerprint_slots'] = $slots;
            $attributes['fingerprint_count'] = count($slots);
        }

        $employee->update($attributes);

        return [
            'on_device' => true,
            'fingerprint_slots' => $slots,
            'fingerprint_count' => $slots === null ? null : count($slots),
            'card_number' => $cardNumber,
            // The device's user record holds exactly one card field, so this is
            // structurally 0 or 1 — not a limit this application imposes.
            'card_count' => $cardNumber === null ? 0 : 1,
            'stored' => $stored,
            'synced_at' => $employee->fresh()->enrollment_synced_at?->toIso8601String(),
        ];
    }

    public function reconcile(Device $device): array
    {
        $this->driver->capabilities($device)->require(Capability::USER_READ);

        /** @var list<DeviceUser> $deviceUsers */
        $deviceUsers = $this->driver->readUsers($device);

        $deviceByUserId = [];
        foreach ($deviceUsers as $user) {
            $deviceByUserId[$user->userId] = $user;
        }

        $employees = Employee::query()->where('is_active', true)->get();

        // PIN per employee ON THIS DEVICE. Absent from this list means "has no identity
        // here", which is a different statement from "is missing from the device".
        $pinByEmployee = EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->live()
            ->pluck('device_user_id', 'employee_id')
            ->all();

        $onlyOnDevice = [];
        $onlyInSystem = [];
        $mismatched = [];

        foreach ($employees as $employee) {
            $pin = $pinByEmployee[$employee->id] ?? null;
            $user = $pin === null ? null : ($deviceByUserId[$pin] ?? null);

            if ($user === null) {
                $onlyInSystem[] = [
                    'employee_id' => $employee->id,
                    'employee_code' => $employee->employee_code,
                    'name' => $employee->name,
                    'consequence' => 'Cannot clock in at this terminal.',
                ];

                continue;
            }

            // Names drifting apart is not cosmetic: the device screen is what the
            // person sees when they badge, and mismatches make audits ambiguous.
            if (trim($user->name) !== trim($employee->name)) {
                $mismatched[] = [
                    'employee_id' => $employee->id,
                    'employee_code' => $employee->employee_code,
                    'system_name' => $employee->name,
                    'device_name' => $user->name,
                    'field' => 'name',
                ];
            }
        }

        $claimedPins = array_map('strval', array_values($pinByEmployee));

        foreach ($deviceUsers as $user) {
            if (! in_array((string) $user->userId, $claimedPins, true)) {
                $onlyOnDevice[] = [
                    'device_user_id' => $user->userId,
                    'device_uid' => $user->uid,
                    'name' => $user->name,
                    'card_number' => $user->cardNumber,
                    'consequence' => 'Punches from this person arrive with no employee attached.',
                ];
            }
        }

        $this->applyReconciliationStatuses($device, $onlyInSystem, $mismatched);

        $total = count($onlyOnDevice) + count($onlyInSystem) + count($mismatched);

        return [
            'message' => $total === 0
                ? "{$device->name} is fully in sync with the employee roster."
                : sprintf(
                    '%s has %d discrepancy(ies): %d only on the device, %d only in the system, %d mismatched.',
                    $device->name,
                    $total,
                    count($onlyOnDevice),
                    count($onlyInSystem),
                    count($mismatched),
                ),
            'raw' => [
                'only_on_device' => $onlyOnDevice,
                'only_in_system' => $onlyInSystem,
                'mismatched' => $mismatched,
            ],
        ];
    }

    /**
     * Creates an employee for every UNCLAIMED person already on this device.
     *
     * WHAT CHANGED AND WHY. This used to look each device user up by
     * employees.employee_code and either reuse the match or create a new employee. Both
     * halves were wrong on a terminal that predates this system:
     *
     *   - Reusing a match MERGED two unrelated humans. A PIN is local to one terminal;
     *     device B's PIN 5 has nothing to do with device A's PIN 5, and nothing to do
     *     with payroll number 5. Two people became one employee, and one person's
     *     punches were credited to the other.
     *   - Creating silently meant a leaver, a contractor and the installer's test
     *     account all arrived in payroll looking like staff.
     *
     * Now: one new employment per unclaimed roster entry on THIS device, never matched
     * against anything that already exists, each claimed at the PIN the device already
     * uses so no fingerprint is disturbed. Duplicates across sister terminals are
     * expected here and are resolved afterwards at the Person layer, by national id —
     * which is the only identifier that can honestly say two records are one human.
     *
     * Read the roster first (DeviceAdoptionService::readRoster). This writes nothing to
     * the device.
     *
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function importFromDevice(Device $device): array
    {
        $entries = DeviceRosterEntry::query()
            ->where('device_id', $device->id)
            ->where('claim_status', DeviceRosterEntry::UNCLAIMED)
            ->orderBy('device_user_id')
            ->get();

        if ($entries->isEmpty()) {
            return [
                'message' => $device->roster_read_at === null
                    ? "{$device->name} has not had its roster read yet, so there is nothing to import."
                    : "{$device->name} has no unclaimed device users left to import.",
                'raw' => ['created' => 0, 'pending_before' => 0],
            ];
        }

        $created = 0;

        foreach ($entries as $entry) {
            $employee = Employee::create([
                'tenant_id' => $device->tenant_id,
                // The PIN is used as the starting payroll code only because it is the
                // one identifier we have. It carries no meaning and an operator is
                // expected to replace it — employee_code is unique per tenant, so a
                // collision with an existing code would otherwise reject the import.
                'employee_code' => $this->availableEmployeeCode($device, $entry->device_user_id),
                // A device name field is 24 bytes and often abbreviated; better a
                // placeholder an operator will fix than a fabricated full name.
                'name' => $entry->device_name !== null && $entry->device_name !== ''
                    ? $entry->device_name
                    : "Imported {$entry->device_user_id}",
                'is_active' => true,
            ]);

            $this->adoption->claim($entry, $employee);
            $created++;
        }

        return [
            'message' => "Imported {$created} device user(s) from {$device->name} as new employees. "
                .'Each needs a national id before payroll can export their attendance.',
            'raw' => ['created' => $created, 'device_id' => $device->id],
        ];
    }

    /**
     * A payroll code not yet taken in this tenant.
     *
     * employee_code is unique per tenant. Two terminals in the same company both
     * holding PIN 5 is normal, so the second import would otherwise fail outright.
     */
    private function availableEmployeeCode(Device $device, string $pin): string
    {
        $candidate = $pin;
        $suffix = 1;

        while (Employee::withoutGlobalScopes()
            ->where('tenant_id', $device->tenant_id)
            ->where('employee_code', $candidate)
            ->exists()
        ) {
            $candidate = $pin.'-'.$device->id.($suffix > 1 ? "-{$suffix}" : '');
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Writes an employee onto the device, creating or updating their record.
     *
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function pushEmployee(Device $device, Employee $employee): array
    {
        /*
         * THREE GUARDS, IN THIS ORDER. Each one caught a real failure on 2026-09-15,
         * when employees were pushed to a terminal that had been in service for years:
         *
         *   1. assertWritable() — the device's existing roster must have been read and
         *      every entry decided. Without that we do not know which slots are taken.
         *   2. pinFor()         — address the person by the PIN MAPPED to them on this
         *      device. The old code used employee_code, which is a payroll number that
         *      happens to collide with a stranger's slot on any other terminal.
         *   3. guardSlot()      — read the slot before writing it. save() is an upsert:
         *      writing to an occupied slot does not fail, it REPLACES the record and
         *      that person's fingerprint templates with it. Templates cannot be
         *      restored from a backup; the employee has to re-enrol in person.
         */
        $device->assertWritable();

        $this->driver->capabilities($device)->require(Capability::USER_WRITE);

        $pin = $this->identity->pinFor($device, $employee);

        // The driver reads the slot, runs this guard, and only then writes — all in one
        // session, so nothing can claim the slot in between (rule 11).
        $user = $this->driver->saveUser(
            $device,
            $pin,
            $employee->name,
            $this->identity->slotGuard($device, $employee, $pin),
        );

        $this->upsertMap($device, $employee->id, $user);

        return [
            'message' => "{$employee->name} written to {$device->name} as PIN {$pin} (uid {$user->uid}).",
            'raw' => $user->toArray(),
        ];
    }

    /**
     * Removes an employee's record from the device — templates and card with it.
     *
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function removeEmployee(Device $device, Employee $employee): array
    {
        $device->assertWritable();

        $this->driver->capabilities($device)->require(Capability::USER_DELETE);

        $pin = $this->identity->pinFor($device, $employee);

        $this->driver->deleteUser($device, $pin);

        /*
         * The mapping is CLOSED, not deleted.
         *
         * Attendance already collected under this PIN belongs to this employee, and it
         * resolves through the mapping that was in force at the punch time. Deleting the
         * row orphans that history; worse, the PIN would then look free and could be
         * reissued, quietly re-attributing those punches to whoever got it next.
         */
        $this->identity->activeMap($device, $employee)?->close('Removed from device');

        // Their roster entry, if one exists, goes back to being an unclaimed slot that
        // is still reserved — the number stays spent even though the record is gone.
        DeviceRosterEntry::query()
            ->where('device_id', $device->id)
            ->where('device_user_id', $pin)
            ->update([
                'claim_status' => DeviceRosterEntry::IGNORED,
                'claimed_employee_id' => null,
                'ignore_reason' => 'Deleted from the device; PIN kept reserved so it is never reissued',
            ]);

        BiometricTemplate::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->delete();

        AccessCard::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->where('status', AccessCard::ACTIVE)
            ->update(['status' => AccessCard::REVOKED, 'revoked_at' => now(), 'revoke_reason' => 'Removed from device']);

        return [
            'message' => "{$employee->name} removed from {$device->name}.",
            'raw' => ['employee_id' => $employee->id, 'device_id' => $device->id],
        ];
    }

    // ------------------------------------------------------------------ internals

    /**
     * Records a person the device holds that nobody has claimed.
     *
     * Evidence, not an identity. It also RESERVES the PIN: an unclaimed occupant still
     * holds their slot, and allocating a new employee there is exactly the overwrite
     * this whole change exists to prevent.
     *
     * An entry an operator has already decided keeps its decision — a re-read must
     * never silently un-claim somebody.
     */
    private function recordRosterEntry(Device $device, DeviceUser $user): void
    {
        if ($user->userId === '') {
            return;
        }

        $attributes = [
            'device_uid' => $user->uid,
            'device_name' => $user->name,
            'device_privilege' => $user->privilege,
            'card_number' => $user->cardNumber,
            'last_seen_at' => now(),
        ];

        // Only overwrite slots when the table decoded. Storing null over a good read
        // turns a known answer into "unknown" (rule 10).
        if ($user->fingerSlots !== null) {
            $attributes['fingerprint_slots'] = $user->fingerSlots;
        }

        $entry = DeviceRosterEntry::query()
            ->where('device_id', $device->id)
            ->where('device_user_id', $user->userId)
            ->first();

        if ($entry === null) {
            DeviceRosterEntry::create($attributes + [
                'tenant_id' => $device->tenant_id,
                'device_id' => $device->id,
                'device_user_id' => $user->userId,
                'claim_status' => DeviceRosterEntry::UNCLAIMED,
                'first_seen_at' => now(),
            ]);

            return;
        }

        $entry->update($attributes);
    }

    /**
     * Refreshes what the device holds for an ALREADY MAPPED employee.
     *
     * Keyed on the live mapping, so it updates an identity rather than creating one.
     * Creating one belongs to DeviceIdentityResolver::claim() or the adoption flow,
     * both of which check the slot is free first.
     */
    private function upsertMap(Device $device, int $employeeId, DeviceUser $user): EmployeeDeviceMap
    {
        $attributes = [
            'tenant_id' => $device->tenant_id,
            'device_uid' => $user->uid,
            'device_user_id' => $user->userId,
            'device_name' => $user->name,
            'device_privilege' => $user->privilege,
            'card_number' => $user->cardNumber,
            'fingerprint_slots' => $user->fingerSlots,
            'synced_at' => now(),
            'reconciliation_status' => EmployeeDeviceMap::IN_SYNC,
            'reconciliation_detail' => null,
        ];

        $map = EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employeeId)
            ->live()
            ->first();

        if ($map !== null) {
            // valid_from is deliberately NOT in the update. It is when this identity
            // began, and refreshing it on every sync would collapse the window that
            // lets a punch resolve to whoever held the PIN at the time.
            $map->update($attributes);

            return $map;
        }

        return EmployeeDeviceMap::create($attributes + [
            'employee_id' => $employeeId,
            'device_id' => $device->id,
            'valid_from' => now(),
        ]);
    }

    /** Records the card the device holds, as history rather than overwriting. */
    private function recordCard(Device $device, int $employeeId, DeviceUser $user): void
    {
        if (! $user->hasCard()) {
            return;
        }

        $existing = AccessCard::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employeeId)
            ->where('card_number', $user->cardNumber)
            ->first();

        if ($existing !== null) {
            return;
        }

        // A different card means the previous one is no longer theirs.
        AccessCard::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employeeId)
            ->where('status', AccessCard::ACTIVE)
            ->update(['status' => AccessCard::REPLACED, 'revoked_at' => now(), 'revoke_reason' => 'Replaced by a newer card read from the device']);

        AccessCard::create([
            'tenant_id' => $device->tenant_id,
            'employee_id' => $employeeId,
            'device_id' => $device->id,
            'card_number' => $user->cardNumber,
            'status' => AccessCard::ACTIVE,
            'assigned_at' => now(),
            // 0xFFFFFFFF is very likely a "no card" marker, not a card. Flagged so an
            // operator can judge rather than the system pretending to know.
            'is_suspect' => $user->cardLooksLikeSentinel(),
        ]);
    }

    /** Records which slots are filled. Template BYTES are not downloadable. */
    private function recordTemplates(Device $device, int $employeeId, DeviceUser $user): void
    {
        if ($user->fingerSlots === null) {
            return; // unknown — do not delete what we cannot verify
        }

        BiometricTemplate::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employeeId)
            ->whereNotIn('finger_slot', $user->fingerSlots ?: [-1])
            ->delete();

        foreach ($user->fingerSlots as $slot) {
            BiometricTemplate::updateOrCreate(
                ['device_id' => $device->id, 'employee_id' => $employeeId, 'finger_slot' => $slot],
                ['tenant_id' => $device->tenant_id, 'verified_at' => now()],
            );
        }
    }

    /**
     * Flags mappings whose employee is no longer on the device.
     *
     * @param  list<DeviceUser>  $deviceUsers
     */
    private function markMissingOnDevice(Device $device, array $deviceUsers): void
    {
        $present = array_map(static fn (DeviceUser $u) => $u->userId, $deviceUsers);

        EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->live()
            ->whereNotIn('device_user_id', $present ?: [''])
            ->update([
                'reconciliation_status' => EmployeeDeviceMap::MISSING_ON_DEVICE,
                'reconciliation_detail' => ['observed_at' => now()->toIso8601String()],
            ]);
    }

    /**
     * @param  list<array<string,mixed>>  $onlyInSystem
     * @param  list<array<string,mixed>>  $mismatched
     */
    private function applyReconciliationStatuses(Device $device, array $onlyInSystem, array $mismatched): void
    {
        foreach ($onlyInSystem as $row) {
            EmployeeDeviceMap::query()
                ->where('device_id', $device->id)
                ->where('employee_id', $row['employee_id'])
                ->update([
                    'reconciliation_status' => EmployeeDeviceMap::MISSING_ON_DEVICE,
                    'reconciliation_detail' => $row,
                ]);
        }

        foreach ($mismatched as $row) {
            EmployeeDeviceMap::query()
                ->where('device_id', $device->id)
                ->where('employee_id', $row['employee_id'])
                ->update([
                    'reconciliation_status' => EmployeeDeviceMap::MISMATCHED,
                    'reconciliation_detail' => $row,
                ]);
        }
    }
}
