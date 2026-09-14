<?php

namespace App\Services\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\AccessCard;
use App\Models\BiometricTemplate;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
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
    public function __construct(private readonly BiometricDeviceDriver $driver) {}

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

        $employeesByCode = Employee::query()->pluck('id', 'employee_code')->all();

        $matched = 0;
        $unmatched = [];
        $fingerprintsKnown = false;

        foreach ($deviceUsers as $user) {
            $employeeId = $employeesByCode[$user->userId] ?? null;

            if ($employeeId === null) {
                // On the device but not in the system. Recorded for reconciliation
                // rather than ignored — their punches would arrive unattributed.
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
            $unmatched === [] ? '' : ', '.count($unmatched).' unknown to the system',
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

        $match = null;
        foreach ($deviceUsers as $user) {
            if ($user->userId === (string) $employee->employee_code) {
                $match = $user;
                break;
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

        $onlyOnDevice = [];
        $onlyInSystem = [];
        $mismatched = [];

        foreach ($employees as $employee) {
            $user = $deviceByUserId[$employee->employee_code] ?? null;

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

        $systemCodes = $employees->pluck('employee_code')->all();

        foreach ($deviceUsers as $user) {
            if (! in_array($user->userId, $systemCodes, true)) {
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
     * Imports device users the system does not know about, as new employees.
     *
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function importFromDevice(Device $device): array
    {
        $this->driver->capabilities($device)->require(Capability::USER_READ);

        /** @var list<DeviceUser> $deviceUsers */
        $deviceUsers = $this->driver->readUsers($device);

        $created = 0;
        $existed = 0;

        foreach ($deviceUsers as $user) {
            if ($user->userId === '') {
                continue;
            }

            $employee = Employee::query()->where('employee_code', $user->userId)->first();

            if ($employee !== null) {
                $existed++;
            } else {
                $employee = Employee::create([
                    'tenant_id' => $device->tenant_id,
                    'employee_code' => $user->userId,
                    // A device name field is 24 bytes and often abbreviated; better a
                    // placeholder an operator will fix than a fabricated full name.
                    'name' => $user->name !== '' ? $user->name : "Imported {$user->userId}",
                    'is_active' => true,
                ]);
                $created++;
            }

            $this->upsertMap($device, $employee->id, $user);
        }

        return [
            'message' => "Imported from {$device->name}: {$created} added, {$existed} already existed.",
            'raw' => ['created' => $created, 'existed' => $existed, 'device_users' => count($deviceUsers)],
        ];
    }

    /**
     * Writes an employee onto the device, creating or updating their record.
     *
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function pushEmployee(Device $device, Employee $employee): array
    {
        $this->driver->capabilities($device)->require(Capability::USER_WRITE);

        $user = $this->driver->run(
            $device,
            // save() preserves the card and privilege already on the device — writing
            // the defaults would wipe a card and demote an admin, both silently.
            fn (ProtocolDevice $d) => $d->users()->save($employee->employee_code, $employee->name),
        );

        $this->upsertMap($device, $employee->id, $user);

        return [
            'message' => "{$employee->name} written to {$device->name} as uid {$user->uid}.",
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
        $this->driver->capabilities($device)->require(Capability::USER_DELETE);

        $this->driver->run($device, fn (ProtocolDevice $d) => $d->users()->delete($employee->employee_code));

        // The device no longer holds anything for them, so stored credential state
        // would be a lie. Cleared rather than left stale.
        EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->delete();

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

    private function upsertMap(Device $device, int $employeeId, DeviceUser $user): EmployeeDeviceMap
    {
        return EmployeeDeviceMap::updateOrCreate(
            ['device_id' => $device->id, 'employee_id' => $employeeId],
            [
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
            ],
        );
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
