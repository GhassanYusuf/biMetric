<?php

namespace App\Services\Devices;

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Models\DeviceRosterEntry;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Value\DeviceUser;

/**
 * Bringing an EXISTING terminal under management without destroying what is on it.
 *
 * THE PROBLEM THIS SOLVES: every terminal here was in service before this system
 * existed. Each holds a roster assigned by whoever installed it — and on a second
 * terminal, the same numbers belong to entirely different people. The old code met
 * that by string-matching the device's user id against employees.employee_code, which
 * is a guess dressed as a match. It produced both failures on 2026-09-15: strangers
 * overwritten on push, and unrelated humans merged across devices.
 *
 * The flow here makes every one of those a human decision:
 *
 *   1. readRoster()  — pull who the device says it has, into evidence. Read-only.
 *   2. claim()       — an operator binds one entry to one employee. Creates the
 *                      mapping at the PIN THE DEVICE ALREADY USES, so nothing is
 *                      written to the hardware and nothing is overwritten.
 *      ignore()      — an operator declares an entry not a person we track. The PIN
 *                      stays reserved so it is never allocated to somebody else.
 *   3. finishAdoption() — permitted only once nothing is left unclaimed. Writes unlock.
 *
 * Nothing here auto-decides, and nothing here writes to the device. That is the point.
 */
class DeviceAdoptionService
{
    public function __construct(
        private readonly BiometricDeviceDriver $driver,
        private readonly DeviceIdentityResolver $identity,
    ) {}

    /**
     * Reads the device's roster into evidence. READ-ONLY, and safe to repeat.
     *
     * Entries already decided are refreshed but keep their decision — re-reading a
     * terminal must never silently un-claim somebody. An entry that has DISAPPEARED
     * from the device is kept too: it still tells us that PIN was in use, and
     * allocating over a number that vanished because a firmware read was short would
     * be the same overwrite by another route.
     *
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function readRoster(Device $device): array
    {
        $capabilities = $this->driver->capabilities($device);
        $capabilities->require(Capability::USER_READ);

        $withFingerprints = $capabilities->supports(Capability::TEMPLATE_TABLE_READ);

        /** @var list<DeviceUser> $deviceUsers */
        $deviceUsers = $this->driver->readUsers($device, $withFingerprints);

        $new = 0;
        $refreshed = 0;
        $now = now();

        foreach ($deviceUsers as $user) {
            if ($user->userId === '') {
                continue;
            }

            $entry = DeviceRosterEntry::query()
                ->where('device_id', $device->id)
                ->where('device_user_id', $user->userId)
                ->first();

            $attributes = [
                'device_uid' => $user->uid,
                'device_name' => $user->name,
                'device_privilege' => $user->privilege,
                'card_number' => $user->cardNumber,
                'last_seen_at' => $now,
            ];

            /*
             * Only overwrite the slots when the table actually decoded. A firmware
             * whose template table would not parse returns null, and storing that over
             * a previously good read turns a known answer into "unknown" (rule 10).
             */
            if ($user->fingerSlots !== null) {
                $attributes['fingerprint_slots'] = $user->fingerSlots;
            }

            if ($entry === null) {
                DeviceRosterEntry::create($attributes + [
                    'tenant_id' => $device->tenant_id,
                    'device_id' => $device->id,
                    'device_user_id' => $user->userId,
                    'claim_status' => DeviceRosterEntry::UNCLAIMED,
                    'first_seen_at' => $now,
                ]);
                $new++;
            } else {
                $entry->update($attributes);
                $refreshed++;
            }
        }

        $device->forceFill([
            'roster_read_at' => $now,
            // Reading a roster never DEMOTES an already-adopted device, and never
            // promotes one either — adoption is finishAdoption()'s decision alone.
            'adoption_status' => $device->adoption_status === Device::ADOPTED
                ? Device::ADOPTED
                : Device::READ_ONLY,
        ])->save();

        $pending = $this->pendingCount($device);

        return [
            'message' => sprintf(
                'Read %d record(s) from %s: %d new, %d already known. %s',
                count($deviceUsers),
                $device->name,
                $new,
                $refreshed,
                $pending === 0
                    ? 'Nothing awaiting a decision.'
                    : "{$pending} awaiting a decision before this device accepts writes.",
            ),
            'raw' => [
                'device_users' => count($deviceUsers),
                'new' => $new,
                'refreshed' => $refreshed,
                'pending' => $pending,
                'fingerprints_known' => $withFingerprints,
            ],
        ];
    }

    /**
     * Binds a roster entry to an employee, at the PIN the device already uses.
     *
     * NO DEVICE WRITE HAPPENS. The person is already enrolled on the hardware; we are
     * recording who they are, not changing anything. Adopting their existing PIN is
     * also what preserves their fingerprints — issuing them a new number would mean
     * re-enrolling every finger.
     */
    public function claim(DeviceRosterEntry $entry, Employee $employee, ?int $actorId = null): EmployeeDeviceMap
    {
        if ($entry->claim_status === DeviceRosterEntry::CLAIMED) {
            throw new \RuntimeException(
                "PIN {$entry->device_user_id} is already claimed on this device. Release it before reassigning."
            );
        }

        $device = $entry->device;

        if ($existing = $this->identity->activeMap($device, $employee)) {
            throw new \RuntimeException(
                "{$employee->name} is already PIN {$existing->device_user_id} on {$device->name}. "
                .'One live identity per person per device — close the existing one first.'
            );
        }

        $map = EmployeeDeviceMap::create([
            'tenant_id' => $device->tenant_id,
            'employee_id' => $employee->id,
            'device_id' => $device->id,
            'device_uid' => $entry->device_uid,
            'device_user_id' => $entry->device_user_id,
            /*
             * NULL, not now(). This person was already enrolled on the terminal when we
             * first read it, and we do not know since when — possibly years. Stamping
             * today would make every punch older than the claim resolve to nobody, which
             * on a terminal holding 87,000 records means discarding the attribution of
             * almost all of them. An unbounded start is the honest statement: they held
             * this PIN for as long as we have any evidence about.
             *
             * A PIN allocated fresh by DeviceIdentityResolver::claim() is the opposite
             * case — it genuinely begins now — and is stamped accordingly.
             */
            'valid_from' => null,
            'device_name' => $entry->device_name,
            'device_privilege' => $entry->device_privilege,
            'card_number' => $entry->card_number,
            'fingerprint_slots' => $entry->fingerprint_slots,
            'synced_at' => $entry->last_seen_at,
            'reconciliation_status' => EmployeeDeviceMap::IN_SYNC,
        ]);

        $entry->update([
            'claim_status' => DeviceRosterEntry::CLAIMED,
            'claimed_employee_id' => $employee->id,
            'claimed_by' => $actorId,
            'claimed_at' => now(),
        ]);

        return $map;
    }

    /**
     * Declares a roster entry not a person we track.
     *
     * The row is KEPT, not deleted. Its PIN stays reserved: the installer's test user
     * still occupies slot 1, and allocating a new hire there would overwrite it.
     * Deleting the evidence would also mean the next roster read presents the same
     * entry again as if it had never been decided.
     */
    public function ignore(DeviceRosterEntry $entry, string $reason, ?int $actorId = null): DeviceRosterEntry
    {
        $entry->update([
            'claim_status' => DeviceRosterEntry::IGNORED,
            'ignore_reason' => $reason,
            'claimed_by' => $actorId,
            'claimed_at' => now(),
        ]);

        return $entry;
    }

    /**
     * Unlocks writes, once nothing on the device is undecided.
     *
     * Refuses rather than warning. "Adopted with 40 unknowns" is the state that let
     * the original overwrite happen, so it is not a state this system can be in.
     */
    public function finishAdoption(Device $device, ?int $actorId = null): array
    {
        if ($device->roster_read_at === null) {
            throw new \RuntimeException(
                "{$device->name} has never had its roster read. Read it before adopting — "
                .'otherwise there is no evidence about who is already enrolled.'
            );
        }

        $pending = $this->pendingCount($device);

        if ($pending > 0) {
            throw new \RuntimeException(
                "{$device->name} still has {$pending} roster entr".($pending === 1 ? 'y' : 'ies')
                .' nobody has claimed or ignored. Each one is a person already on the hardware whose '
                .'PIN a write could overwrite.'
            );
        }

        $device->forceFill([
            'adoption_status' => Device::ADOPTED,
            'adopted_at' => now(),
            'adopted_by' => $actorId,
        ])->save();

        return [
            'message' => "{$device->name} adopted. Device writes are now permitted.",
            'raw' => ['device_id' => $device->id, 'adopted_at' => $device->adopted_at],
        ];
    }

    /** Roster entries nobody has decided about yet. */
    public function pendingCount(Device $device): int
    {
        return DeviceRosterEntry::query()
            ->where('device_id', $device->id)
            ->where('claim_status', DeviceRosterEntry::UNCLAIMED)
            ->count();
    }
}
