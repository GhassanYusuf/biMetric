<?php

namespace App\Services\Devices;

use Illuminate\Support\Facades\Log;
use App\Services\Employees\FingerprintPusher;
use App\Services\Employees\FingerprintVault;
use App\Contracts\BiometricDeviceDriver;
use App\Exceptions\FingerprintWriteUnprovenException;
use App\Exceptions\PinInUseException;
use App\Models\AccessCard;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use App\Models\BiometricTemplate;
use App\Models\Device;
use App\Models\DeviceRosterEntry;
use App\Models\Employee;
use App\Models\EmployeeDeviceMap;
use Illuminate\Support\Facades\DB;
use Promoseven\Biometric\Capability\Capability;
use Promoseven\Biometric\Exception\VerificationException;
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

            // Rule 19: a card only the terminal knew about goes on the employee record
            // too. Never overwrites a card the record already has — that is the
            // system's own record, and per-terminal cards stay in access_cards.
            if ($user->hasCard() && ! $user->cardLooksLikeSentinel()) {
                // Through the model so the change is audited.
                Employee::query()->whereKey($employeeId)->whereNull('card_number')->first()
                    ?->update(['card_number' => $user->cardNumber]);
            }

            $this->capturePassword($device, $employeeId, $user);

            $matched++;
        }

        $this->markMissingOnDevice($device, $deviceUsers);

        /*
         * Everyone's fingers into the system on every roster read (2026-10-11), not
         * only when somebody opens a person and presses "read": the copy has to exist
         * BEFORE the day a terminal loses the finger. Same best-effort rule as
         * readEnrollment() — only when the table decoded, and a failure here never
         * fails the roster read.
         */
        $copies = null;
        if ($fingerprintsKnown && $capabilities->supports(Capability::TEMPLATE_BLOB_READ)) {
            try {
                $copies = app(FingerprintVault::class)->capture($device, $this->driver->readTemplates($device));
            } catch (\Throwable $e) {
                Log::channel('device')->warning('Fingerprint copies not refreshed after the roster read.', [
                    'device_id' => $device->id, 'error' => $e->getMessage(),
                ]);
            }
        }

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
                'fingerprint_copies' => $copies,
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

        /*
         * The employee's card is the SYSTEM's record of it, so a terminal that holds no
         * card does not erase it — only a card the terminal does hold replaces it. On
         * 2026-10-11 Akram was re-added to IT Room without his card, and this read
         * wiped card 7553651 from his record as well. The per-terminal row below still
         * says exactly what this terminal holds.
         */
        $attributes = ['enrollment_synced_at' => now()];
        if ($cardNumber !== null) {
            $attributes['card_number'] = $cardNumber;
        }

        if ($slots !== null) {
            $attributes['fingerprint_slots'] = $slots;
            $attributes['fingerprint_count'] = count($slots);
        }

        $employee->update($attributes);

        /*
         * The per-terminal row is what the dialogs show (docs/enrollment-flow.md,
         * "Enrollment belongs to ONE terminal"), so a read must land there too, or
         * "Read from device" changes nothing on screen. Same rule as above: slots only
         * when they decoded.
         */
        // The privilege as the terminal holds it (2026-10-18) — what the admin / normal
        // user control on the dialog shows.
        $mapAttributes = ['card_number' => $cardNumber, 'device_privilege' => $match->privilege, 'synced_at' => now()];
        if ($slots !== null) {
            $mapAttributes['fingerprint_slots'] = $slots;
        }
        // Through the model, not the query builder, so the array cast encodes the slots.
        $map->update($mapAttributes);

        /*
         * Keep the system's copy of their fingerprints current (2026-09-24). A second
         * read of the template table, WITH bytes; only when the table decoded above, so
         * an unreadable table never becomes "their copies were dropped". Best effort: a
         * failure here must not fail the enrollment read the operator asked for.
         */
        if ($slots !== null) {
            try {
                app(FingerprintVault::class)->capture($device, $this->driver->readTemplates($device), $employee->id);
            } catch (\Throwable $e) {
                Log::channel('device')->warning('Fingerprint copy not refreshed after the enrollment read.', [
                    'device_id' => $device->id, 'employee_id' => $employee->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'on_device' => true,
            'fingerprint_slots' => $slots,
            'fingerprint_count' => $slots === null ? null : count($slots),
            'card_number' => $cardNumber,
            // The device's user record holds exactly one card field, so this is
            // structurally 0 or 1 — not a limit this application imposes.
            'card_count' => $cardNumber === null ? 0 : 1,
            'device_privilege' => $match->privilege,
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
        $employeeIds = [];

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
            $employeeIds[] = $employee->id;
            $created++;
        }

        // claim() re-linked each PIN's stored punches (DC-031); say how many.
        $punches = $employeeIds === [] ? 0 : AttendanceLog::query()
            ->where('device_id', $device->id)
            ->whereIn('employee_id', $employeeIds)
            ->count();

        return [
            'message' => "Imported {$created} device user(s) from {$device->name} as new employees"
                .($punches > 0 ? "; {$punches} punch(es) already stored from it are now theirs." : '.'),
            'raw' => ['created' => $created, 'device_id' => $device->id, 'punches_linked' => $punches],
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
            privilege: $this->privilegeToRestore($device, $employee),
            password: $this->passwordToWrite($device, $employee),
        );

        $this->upsertMap($device, $employee->id, $user);

        $notes = $this->restoreCredentials($device, $employee, $user);

        return [
            'message' => trim("{$employee->name} written to {$device->name} as PIN {$pin} (uid {$user->uid}). ".implode(' ', $notes)),
            'raw' => $user->toArray(),
            // What was put back from the system's copy, sentence per credential.
            'restored' => $notes,
        ];
    }

    /**
     * The keypad password a push writes (2026-10-12, owner: "push the passwords to the
     * terminals too"). Null means "keep what the terminal has" — never "clear it".
     *
     * HELD BACK until the model's `password.write` is CONFIRMED, i.e. until a write has
     * been read back on that model with a test user (rule 16, rule 21). Until then a
     * push keeps the terminal's password instead of erasing it, which is what library
     * releases before 2.13.0 did on every standalone write.
     *
     * A password cleared in this system is not cleared on the terminal: the column
     * cannot tell "never set" from "removed". Recorded as a known gap.
     */
    private function passwordToWrite(Device $device, Employee $employee): ?string
    {
        $password = (string) ($employee->device_password ?? '');

        if ($password === '' || $this->driver->capabilities($device)->level(Capability::PASSWORD_WRITE) !== Capability::CONFIRMED) {
            return null;
        }

        return $password;
    }

    /**
     * Rule 19 for the keypad password: a password only the terminal knew goes on the
     * employee record, so it can be put back after a removal. Never overwrites one the
     * record already has — that is the system's own, and a push will write it.
     * Only what the record can hold is taken (1 to 8 digits, the form's rule); the read
     * must be one the model's `password.read` vouches for.
     */
    private function capturePassword(Device $device, int $employeeId, DeviceUser $user): void
    {
        $password = $user->password;

        if ($password === null || ! preg_match('/^\d{1,8}$/', $password)
            || ! $this->driver->capabilities($device)->supports(Capability::PASSWORD_READ)) {
            return;
        }

        $employee = Employee::query()->whereKey($employeeId)->first();
        if ($employee !== null && (string) ($employee->device_password ?? '') === '') {
            // Through the model so it is audited (the value itself is redacted).
            $employee->update(['device_password' => $password]);
        }
    }

    /**
     * The terminal privilege (admin = 14 on the ZKTeco family) to put back when this
     * person's record on $device is being CREATED again — their live mapping has never
     * been seen on the terminal, and an earlier mapping there recorded one. Null keeps
     * whatever the terminal has. Rule 19: Ali is an admin on IT ROOM and On The Road
     * (2026-10-11); re-adding him as a normal user would demote him silently.
     */
    private function privilegeToRestore(Device $device, Employee $employee): ?int
    {
        $live = $this->identity->activeMap($device, $employee);
        if ($live === null || $live->device_uid !== null) {
            return null;
        }

        $previous = EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->whereNotNull('valid_to')
            // Their own previous record decides; a merged duplicate's privilege counts
            // only when it is the only record they had here (Good Wheels' "ali-it").
            ->orderByRaw("case when closed_reason like 'Duplicate record%' then 1 else 0 end")
            ->orderByDesc('valid_to')
            ->orderByDesc('id')
            ->value('device_privilege');

        return $previous !== null && (int) $previous > 0 ? (int) $previous : null;
    }

    /**
     * Puts the system's copy of their card and fingerprints onto the record just
     * written, where the terminal lacks them (owner's rule, 2026-10-11: "read the
     * fingerprint, store it in the webapp, and whenever needed write it to a device
     * using each device's own method"). Akram was removed from IT Room and added
     * back with no finger and no card — the copy existed and nothing wrote it.
     *
     * Every path that adds a person to a terminal — the push dialog, the single-device
     * push and the queued PUSH_USER — comes through pushEmployee(), so this is here
     * rather than in one of them.
     *
     * BEST EFFORT, AND SAID SO. The person is on the terminal already; a card or a
     * finger that could not be written is a sentence in the result, not a failed push.
     * Both writes are add-only and verified on read-back by the services they call
     * (rule 10): an existing card or finger on the terminal is never replaced.
     *
     * @return list<string>
     */
    private function restoreCredentials(Device $device, Employee $employee, DeviceUser $user): array
    {
        $notes = [];

        $card = (int) ($this->cardToRestore($device, $employee) ?? 0);
        if ($card > 0 && ! $user->hasCard() && $this->driver->capabilities($device)->supports(Capability::CARD_WRITE)) {
            try {
                app(EnrollmentOrchestratorService::class)->assignCard($device, $employee, $card);
                $notes[] = "Card #{$card} written.";
            } catch (\Throwable $e) {
                $notes[] = "Card #{$card} not written: {$e->getMessage()}";
            }
        }

        $fingers = app(FingerprintPusher::class)->restoreOnto($employee, $device);
        if ($fingers !== null) {
            $notes[] = $fingers;
        }

        return $notes;
    }

    /**
     * Moves an employee to a new user ID ("PIN") on this device, keeping their record,
     * card and fingerprints. Added 2026-09-23 so an employee code change can follow
     * them onto the terminal, at the operator's explicit choice.
     *
     * EXPERIMENTAL (capability user.renumber is `implemented`, never confirmed). The
     * driver verifies the move and the fingerprints on the hardware; if it throws,
     * nothing here is changed, so our records never claim a move the device did not
     * make.
     *
     * HISTORY IS KEPT. The old mapping is CLOSED, not edited: punches already recorded
     * under the old PIN resolve through it by punch time and stay this employee's, and
     * the old PIN stays reserved so it is never handed to anyone else.
     *
     * @return array{message:string, from:string, to:string}
     */
    public function renumberEmployee(Device $device, Employee $employee, string $newPin): array
    {
        $device->assertWritable();

        $this->driver->capabilities($device)->require(Capability::USER_RENUMBER);

        $oldPin = $this->identity->pinFor($device, $employee);

        if ($oldPin === $newPin) {
            return ['message' => "{$employee->name} is already user ID {$newPin} on {$device->name}.", 'from' => $oldPin, 'to' => $newPin];
        }

        $this->identity->assertPinFreeFor($device, $employee, $newPin);

        $user = $this->driver->renumberUser($device, $oldPin, $newPin);

        /*
         * The driver can find the person already on the requested ID — a C3 stores
         * 041112750 as 41112750, so the two name one Pin there (2026-10-11). Nothing
         * moved, so nothing is re-recorded: closing and reopening the same mapping on
         * every save would only bury the real history.
         */
        if ($user->userId === $oldPin) {
            return [
                'message' => "{$employee->name} is already user ID {$oldPin} on {$device->name}.",
                'from' => $oldPin,
                'to' => $oldPin,
            ];
        }

        DB::transaction(function () use ($device, $employee, $oldPin, $user) {
            $previous = $this->identity->activeMap($device, $employee);
            $previous?->close("Renumbered to {$user->userId}");

            /*
             * A leftover entry already at the new number, claimed by this same person
             * (assertPinFreeFor() refused anybody else's), is an older sighting of them
             * there. It makes way: the unique (device, PIN) index otherwise fails this
             * transaction AFTER the hardware has moved — which is what happened to
             * Ghassan on Entrance and Glass Door, 2026-10-11, leaving our records
             * behind the panels.
             */
            DeviceRosterEntry::query()
                ->where('device_id', $device->id)
                ->where('device_user_id', $user->userId)
                ->where('device_user_id', '!=', $oldPin)
                ->where('claimed_employee_id', $employee->id)
                ->delete();

            // The roster mirrors the device, and on the device it is the same record
            // (same uid) under a new number — so the entry moves with it.
            DeviceRosterEntry::query()
                ->where('device_id', $device->id)
                ->where('device_user_id', $oldPin)
                ->update(['device_user_id' => $user->userId, 'last_seen_at' => now()]);

            $map = $this->upsertMap($device, $employee->id, $user);

            // Same record, same fingers. When the driver could not read them this time,
            // carry the last known answer across rather than resetting it to unknown.
            if ($user->fingerSlots === null && $previous?->fingerprint_slots !== null) {
                $map->update([
                    'fingerprint_slots' => $previous->fingerprint_slots,
                    'template_digests' => $previous->template_digests,
                    'templates_read_at' => $previous->templates_read_at,
                ]);
            }
        });

        return [
            'message' => "{$employee->name} moved from user ID {$oldPin} to {$user->userId} on {$device->name}.",
            'from' => $oldPin,
            'to' => $user->userId,
        ];
    }

    /**
     * Moves a person to a new user ID on a model that cannot change one in place, by
     * re-adding them (2026-10-11, owner: "every save must put the employee code on
     * every terminal"; casebook DC-014, CLAUDE.md rule 21). The F18's method: it
     * answers ACK_ERROR to an in-place move (DC-006).
     *
     *   1. Refuse BEFORE anything is deleted if the new ID is someone else's, or if
     *      this model cannot put back what the person has here — fingers without
     *      template.upload, a card without card.write.
     *   2. removeEmployee(): card and fingers saved to the system first (rule 19),
     *      or nothing is deleted.
     *   3. Claim the new ID, pushEmployee(): name written, card and fingers restored.
     *   4. Read the terminal back: the person must be at the new ID with the same card
     *      and at least the fingers they had. Anything missing is an exception naming
     *      it; the system copies are kept, so "Push fingerprints" / Retry can finish it.
     *
     * @return array{message:string, restored:list<string>}
     */
    public function moveByReadding(Device $device, Employee $employee, string $newPin): array
    {
        $device->assertWritable();
        $capabilities = $this->driver->capabilities($device);
        $capabilities->require(Capability::USER_DELETE);
        $capabilities->require(Capability::USER_WRITE);

        $map = $this->identity->activeMap($device, $employee);
        if ($map === null) {
            throw new \DomainException("{$employee->name} is not on {$device->name}.");
        }
        $oldPin = (string) $map->device_user_id;
        if ($oldPin === $newPin) {
            return ['message' => "{$employee->name} is already user ID {$newPin} on {$device->name}.", 'restored' => []];
        }

        $this->identity->assertPinFreeFor($device, $employee, $newPin);

        // What they hold on THIS terminal now, read from the terminal itself.
        $before = null;
        foreach ($this->driver->readUsers($device, $capabilities->supports(Capability::TEMPLATE_TABLE_READ)) as $u) {
            if ($u->userId === $oldPin) {
                $before = $u;
            }
        }
        if ($before === null) {
            /*
             * NOT ON THE TERMINAL AT ALL (2026-10-22: Gretchen on Camelot after its wipe —
             * every push failed "not found", and so did every removal). There is nothing to
             * save or delete, so the move is an ADD under the new ID: the old link is closed
             * (punches already under $oldPin stay theirs) and the normal push writes their
             * stored card and fingers and reads them back (rule 19, step 4).
             *
             * Only when TWO reads agree it is gone: this one, and the roster read that
             * already marked the link missing_on_device. A single glitchy read (Camelot's
             * link) must never add the same person twice under two numbers.
             */
            if ($map->reconciliation_status !== EmployeeDeviceMap::MISSING_ON_DEVICE) {
                throw new \DomainException("{$employee->name} (user ID {$oldPin}) was not found on {$device->name} just now, but its last roster read still had them. Read the terminal's users, then push again.");
            }
            $map->close("Not on the terminal; added again as {$newPin}");
            $this->identity->claim($device, $employee->fresh(), $newPin);
            $pushed = $this->pushEmployee($device, $employee->fresh());

            return [
                'message' => "{$employee->name} was no longer on {$device->name} (user ID {$oldPin}), so they were added as {$newPin}. {$pushed['message']}",
                'restored' => $pushed['restored'] ?? [],
            ];
        }
        if ($before->fingerSlots === null) {
            throw new \DomainException("{$device->name}'s fingerprint table would not decode, so {$employee->name}'s fingers could not be checked after a move. Nothing was changed.");
        }
        if ($before->fingerSlots !== [] && ! $capabilities->supports(Capability::TEMPLATE_UPLOAD)) {
            $message = "{$device->name} ({$device->model}) cannot take fingerprints from this app yet, so moving {$employee->name} would lose their fingerprints there. Nothing was changed.";
            // Never tried on this model: the terminal's fingerprint-write test can prove it.
            throw $capabilities->level(Capability::TEMPLATE_UPLOAD) === Capability::UNKNOWN
                ? new FingerprintWriteUnprovenException($message)
                : new \DomainException($message);
        }
        $card = $before->hasCard() && ! $before->cardLooksLikeSentinel() ? $before->cardNumber : null;
        if ($card !== null && ! $capabilities->supports(Capability::CARD_WRITE)) {
            throw new \DomainException("{$device->name} ({$device->model}) cannot take a card number from this app, so moving {$employee->name} would lose card #{$card} there. Nothing was changed.");
        }

        $this->removeEmployee($device, $employee);
        $this->identity->claim($device, $employee->fresh(), $newPin);
        $pushed = $this->pushEmployee($device, $employee->fresh());

        $after = null;
        foreach ($this->driver->readUsers($device, true) as $u) {
            if ($u->userId === $newPin) {
                $after = $u;
            }
        }
        $missing = [];
        if ($after === null) {
            $missing[] = "no record under {$newPin}";
        } else {
            if ($card !== null && $after->cardNumber !== $card) {
                $missing[] = "card #{$card}";
            }
            $lost = array_diff($before->fingerSlots, $after->fingerSlots ?? []);
            if ($lost !== []) {
                $missing[] = 'finger(s) '.implode(', ', $lost);
            }
        }
        if ($missing !== []) {
            throw new \RuntimeException("{$employee->name} was moved from {$oldPin} to {$newPin} on {$device->name}, but the read-back is missing: ".implode('; ', $missing).'. Their copies are kept in the system — press Retry or "Push fingerprints".');
        }

        return [
            'message' => "{$employee->name} moved from user ID {$oldPin} to {$newPin} on {$device->name} by re-adding them; card and ".count($before->fingerSlots).' finger(s) read back.',
            'restored' => $pushed['restored'] ?? [],
        ];
    }

    /**
     * Frees a user ID held on a terminal by a record NO employee is linked to — a
     * leftover test record, say — and gives the number to $giveTo. The operator's
     * button on a red "already in use" push row (2026-10-11; casebook DC-014: "ali-it"
     * held Ali's code on IT ROOM).
     *
     * Rule 19 applies to records nobody claimed too: the record and every fingerprint
     * on it are read off the terminal and kept, encrypted, in
     * storage/app/private/removed-records/ BEFORE the delete — or nothing is deleted.
     * Then the delete, a read-back, and the roster entry becomes $giveTo's claim.
     * Punches already recorded under the number are left exactly as they are.
     *
     * Refused when any employee was ever mapped to the number on this terminal: a real
     * person's number is never handed to another (PinInUseException::$releasable).
     *
     * $samePerson (owner, 2026-10-11: "ali-it and Ali Mohamed are the same person"):
     * the record is a DUPLICATE of $giveTo, not a stranger. Then, before the delete,
     * its fingerprints become $giveTo's stored copies (FingerprintVault::adoptCopies),
     * the punches under the number are credited to them through a closed mapping that
     * also keeps the record's privilege (so a re-add restores an admin), and only then
     * is it removed. The next push gives them one record with everything on it.
     *
     * @return array{message:string, snapshot:string}
     */
    public function releaseUnlinkedNumber(Device $device, string $pin, ?Employee $giveTo, bool $samePerson = false): array
    {
        $device->assertWritable();
        $capabilities = $this->driver->capabilities($device);
        $capabilities->require(Capability::USER_DELETE);

        if (EmployeeDeviceMap::query()->where('device_id', $device->id)->where('device_user_id', $pin)->live()->exists()) {
            throw new \DomainException("User ID {$pin} on {$device->name} is linked to an employee; remove them from the terminal through their own record instead.");
        }
        if ($samePerson && $giveTo === null) {
            throw new \InvalidArgumentException('A same-person merge needs the employee.');
        }

        if ($giveTo !== null) {
            try {
                $this->identity->assertPinFreeFor($device, $giveTo, $pin);

                if (! $samePerson) {
                    return ['message' => "User ID {$pin} is already free for {$giveTo->name} on {$device->name}.", 'snapshot' => ''];
                }
            } catch (PinInUseException $e) {
                if (! $e->releasable) {
                    throw new \DomainException($e->getMessage());
                }
            }
        } elseif (EmployeeDeviceMap::query()->where('device_id', $device->id)->where('device_user_id', $pin)->exists()) {
            // Removing with nobody to give the number to (the device rebuild): never a
            // number an employee ever held here — their history is recorded under it.
            throw new \DomainException("User ID {$pin} on {$device->name} belonged to an employee; it is not removed as an unlinked record.");
        }

        $record = null;
        foreach ($this->driver->readUsers($device, false) as $u) {
            if ($u->userId === $pin) {
                $record = $u;
            }
        }

        $templates = [];
        if ($record !== null && $capabilities->supports(Capability::TEMPLATE_BLOB_READ)) {
            try {
                $templates = array_values(array_filter(
                    $this->driver->readTemplates($device),
                    fn (array $t) => (string) ($t['user_id'] ?? '') === $pin,
                ));
            } catch (\Throwable $e) {
                throw new \DomainException("Nothing was removed from {$device->name}: the fingerprints on user ID {$pin} could not be saved first ({$e->getMessage()}).");
            }
        }

        $snapshot = sprintf('removed-records/device-%d-%s-%s.json.enc', $device->id, $pin, now()->format('Ymd-His'));
        Storage::disk('local')->put($snapshot, Crypt::encryptString(json_encode([
            'device_id' => $device->id,
            'device_user_id' => $pin,
            'record' => $record?->toArray(),
            'templates' => array_map(fn (array $t) => [
                'slot' => (int) $t['slot'], 'sha1' => (string) $t['sha1'], 'template' => base64_encode((string) $t['template']),
            ], $templates),
            'released_to_employee_id' => $giveTo?->id,
            'same_person' => $samePerson,
            'taken_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR)));

        $adopted = 0;
        $credited = 0;
        if ($samePerson) {
            // Their fingers on the duplicate are theirs: into the system's copies.
            $adopted = app(FingerprintVault::class)->adoptCopies($device, $giveTo->id, $templates);

            // Their punches under the duplicate's number are theirs too. A CLOSED
            // mapping records it (so history resolves and the number is "only ever
            // theirs" for the move that follows) and keeps the record's privilege.
            if (! EmployeeDeviceMap::query()->where('device_id', $device->id)->where('device_user_id', $pin)->exists()) {
                EmployeeDeviceMap::create([
                    'tenant_id' => $device->tenant_id,
                    'employee_id' => $giveTo->id,
                    'device_id' => $device->id,
                    'device_user_id' => $pin,
                    'device_uid' => $record?->uid,
                    'device_name' => $record?->name,
                    // NOT NULL column; a record already gone from the terminal (deleted
                    // at the keypad, as IT ROOM's "ali-it" was on 2026-10-11) has none.
                    'device_privilege' => $record?->privilege ?? 0,
                    'card_number' => $record?->cardNumber,
                    'valid_from' => null,
                    'valid_to' => now(),
                    'closed_reason' => "Duplicate record of {$giveTo->name}, merged into them",
                ]);
            }
            $credited = AttendanceLog::query()
                ->where('device_id', $device->id)
                ->where('device_user_id', $pin)
                ->whereNull('employee_id')
                ->update(['employee_id' => $giveTo->id]);
        }

        if ($record !== null) {
            $this->driver->deleteUser($device, $pin);

            foreach ($this->driver->readUsers($device, false) as $u) {
                if ($u->userId === $pin) {
                    throw new \RuntimeException("{$device->name} still holds user ID {$pin} after the delete. Nothing else was changed; the saved copy is {$snapshot}.");
                }
            }
        }

        DeviceRosterEntry::query()
            ->where('device_id', $device->id)
            ->where('device_user_id', $pin)
            ->update($giveTo !== null ? [
                'claim_status' => DeviceRosterEntry::CLAIMED,
                'claimed_employee_id' => $giveTo->id,
                'ignore_reason' => null,
            ] : [
                // Nobody gets the number; it stays reserved so it is never reissued.
                'claim_status' => DeviceRosterEntry::IGNORED,
                'claimed_employee_id' => null,
                'ignore_reason' => "Removed by a device rebuild; record saved in {$snapshot}",
            ]);

        AuditLog::create([
            'tenant_id' => $device->tenant_id,
            'user_id' => Auth::id(),
            'action' => $samePerson ? 'device.duplicate_merged' : ($giveTo !== null ? 'device.number_released' : 'device.unlinked_record_removed'),
            'auditable_type' => $giveTo !== null ? Employee::class : Device::class,
            'auditable_id' => $giveTo?->id ?? $device->id,
            // Never template bytes: counts and where the saved copy is.
            'changes' => [
                'device_id' => $device->id, 'device' => $device->name, 'device_user_id' => $pin,
                'removed_record' => $record?->name, 'card' => $record?->cardNumber,
                'fingerprints_saved' => count($templates), 'snapshot' => $snapshot,
                'same_person' => $samePerson, 'fingerprints_adopted' => $adopted, 'punches_credited' => $credited,
            ],
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : substr((string) request()->userAgent(), 0, 255),
        ]);

        $message = $samePerson
            ? ($record !== null
                ? "\"{$record->name}\" (user ID {$pin}) on {$device->name} merged into {$giveTo->name}: {$adopted} fingerprint(s) now stored as theirs, {$credited} punch(es) credited to them, then the duplicate was removed."
                : "User ID {$pin} was no longer on {$device->name}; {$credited} punch(es) under it credited to {$giveTo->name}.")
            : ($record !== null
                ? "Removed \"{$record->name}\" (user ID {$pin}) from {$device->name} after saving its record and ".count($templates).' fingerprint(s). '
                : "User ID {$pin} was no longer on {$device->name}. ")
                .($giveTo !== null ? "The number is now {$giveTo->name}'s there." : 'The number stays reserved.');

        return ['message' => $message, 'snapshot' => $snapshot];
    }

    /**
     * Runs $use with a TEMPORARY user on the terminal, then deletes it and checks it is
     * gone (2026-10-11: the fingerprint-write test on a terminal where no real person
     * could safely receive a test finger). The user is created at a number free both on
     * the terminal and in this system's records, named "zkt-test", and is never mapped
     * to anyone. Nothing to save before its delete (rule 19): this system made it.
     *
     * @template T
     * @param  \Closure(string $pin): T  $use
     * @return T
     */
    public function withTemporaryUser(Device $device, \Closure $use): mixed
    {
        $device->assertWritable();
        $capabilities = $this->driver->capabilities($device);
        $capabilities->require(Capability::USER_WRITE);
        $capabilities->require(Capability::USER_DELETE);

        $onTerminal = [];
        foreach ($this->driver->readUsers($device, false) as $u) {
            $onTerminal[$u->userId] = true;
        }
        $pin = $this->identity->nextFreePin($device);
        while (isset($onTerminal[$pin])) {
            $pin = (string) ((int) $pin + 1);
        }

        // The guard refuses any occupant: the slot must be empty on the hardware too.
        $this->driver->saveUser($device, $pin, 'zkt-test', function (?DeviceUser $occupant) use ($pin) {
            if ($occupant !== null) {
                throw new \RuntimeException("User ID {$pin} turned out to be taken on the terminal; the test was not run.");
            }
        });

        try {
            return $use($pin);
        } finally {
            $this->driver->deleteUser($device, $pin);
            foreach ($this->driver->readUsers($device, false) as $u) {
                if ($u->userId === $pin) {
                    Log::channel('device')->critical('Temporary test user still on the terminal after delete.', ['device_id' => $device->id, 'pin' => $pin]);
                }
            }
        }
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

        /*
         * Rule 19: their card and fingers go into the system BEFORE the record goes.
         * The delete takes both with it, and the terminal was often the only place
         * they existed — Akram's finger 6 and card on IT Room were lost exactly that
         * way (2026-10-11). If either cannot be read, nothing is deleted.
         */
        $this->saveCredentialsBeforeRemoval($device, $employee, $pin);

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

        // Slot markers only. Rows that carry a template are the system's copy
        // (FingerprintVault) and outlive the terminal's record — that is their purpose.
        BiometricTemplate::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->whereNull('template_sha1')
            ->delete();

        AccessCard::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->where('status', AccessCard::ACTIVE)
            ->update(['status' => AccessCard::REVOKED, 'revoked_at' => now(), 'revoke_reason' => AccessCard::REASON_REMOVED_WITH_USER]);

        return [
            'message' => "{$employee->name} removed from {$device->name}.",
            'raw' => ['employee_id' => $employee->id, 'device_id' => $device->id],
        ];
    }

    /**
     * Empties the terminal of EVERY user — the owner's wipe (2026-10-21, CLAUDE.md
     * rule 22, the one exception to rule 19's "save everything first").
     *
     *   1. Users linked to an employee: their card, keypad password and fingers go into
     *      the system first (the same steps as removeEmployee()'s copy, for everyone in
     *      one read), and are CHECKED there — every finger slot the terminal holds for
     *      them has a stored template, every card a card-history row. Anything missing,
     *      or fingers the terminal would not report, and nothing is deleted.
     *   2. Users linked to nobody: deleted with nothing kept (owner's choice for this
     *      feature only).
     *   3. $beforeDelete runs once the copy is proven — DeviceWipeService's punch step,
     *      so an irreversible punch erase never happens ahead of a refusal here.
     *   4. Every user deleted (deleteAllUsers: read back empty), then our records follow
     *      the terminal: live mappings closed, slot markers gone, cards revoked as removed
     *      with the user, roster entries kept as reservations.
     *
     * Not gated on isWritable(): the wipe deletes unlinked users by design, so an
     * unadopted terminal holding strangers is a normal starting point here.
     *
     * @param  callable|null  $beforeDelete  runs after the copy is checked, before the delete
     * @return array{removed:int, linked:int, unlinked:int}
     */
    public function wipeUsers(Device $device, ?callable $beforeDelete = null): array
    {
        $capabilities = $this->driver->capabilities($device);
        $capabilities->require(Capability::DEVICE_WIPE);

        $users = $this->driver->readUsers($device, true);
        $employeeByPin = EmployeeDeviceMap::query()
            ->where('device_id', $device->id)
            ->live()
            ->pluck('employee_id', 'device_user_id')
            ->all();

        /** @var array<string,int> $linked pin => employee id, for users on the terminal */
        $linked = [];
        foreach ($users as $user) {
            if (isset($employeeByPin[$user->userId])) {
                $linked[$user->userId] = (int) $employeeByPin[$user->userId];
            }
        }

        try {
            foreach ($users as $user) {
                $employeeId = $linked[$user->userId] ?? null;
                if ($employeeId === null) {
                    continue;
                }
                // Kept on the mapping so a re-add can put an admin back as an admin.
                EmployeeDeviceMap::query()->where('device_id', $device->id)->where('device_user_id', $user->userId)
                    ->live()->update(['device_privilege' => $user->privilege]);
                $this->capturePassword($device, $employeeId, $user);
                if ($user->hasCard() && ! $user->cardLooksLikeSentinel()) {
                    $this->recordCard($device, $employeeId, $user);
                    Employee::query()->whereKey($employeeId)->where(fn ($q) => $q->whereNull('card_number')->orWhere('card_number', ''))
                        ->first()?->update(['card_number' => $user->cardNumber]);
                }
            }

            if ($linked !== [] && $capabilities->supports(Capability::TEMPLATE_BLOB_READ)) {
                $vault = app(FingerprintVault::class);
                $vault->assertReady();
                $vault->capture($device, $this->driver->readTemplates($device));
            }
        } catch (\Throwable $e) {
            throw new \DomainException("Nothing was wiped from {$device->name}: the linked employees' cards and fingerprints could not be saved to the system first ({$e->getMessage()}).");
        }

        $missing = [];
        foreach ($users as $user) {
            $employeeId = $linked[$user->userId] ?? null;
            if ($employeeId === null) {
                continue;
            }
            if ($user->fingerSlots === null) {
                // Rule 10: "unknown" is not "none" — their fingers cannot be shown saved.
                $missing[] = "{$user->userId} (fingers not readable)";

                continue;
            }
            foreach ($user->fingerSlots as $slot) {
                $stored = BiometricTemplate::query()->where('employee_id', $employeeId)->where('finger_slot', $slot)
                    ->whereNotNull('template_sha1')->exists();
                if (! $stored) {
                    $missing[] = "{$user->userId} finger {$slot}";
                }
            }
            if ($user->hasCard() && ! $user->cardLooksLikeSentinel()
                && ! AccessCard::query()->where('employee_id', $employeeId)->where('card_number', $user->cardNumber)->exists()) {
                $missing[] = "{$user->userId} card";
            }
        }
        if ($missing !== []) {
            throw new \DomainException(sprintf(
                'Nothing was wiped from %s: %d credential(s) of linked employees are not in the system yet (%s).',
                $device->name,
                count($missing),
                implode(', ', array_slice($missing, 0, 10)),
            ));
        }

        if ($beforeDelete !== null) {
            $beforeDelete();
        }

        $removed = $this->driver->deleteAllUsers($device);

        foreach (EmployeeDeviceMap::query()->where('device_id', $device->id)->live()->get() as $map) {
            $map->close('Wiped from device');
        }
        // Slot markers only; stored copies outlive the terminal (rule 19, step 3).
        BiometricTemplate::query()
            ->where('device_id', $device->id)
            ->whereNull('template_sha1')
            ->delete();
        AccessCard::query()
            ->where('device_id', $device->id)
            ->where('status', AccessCard::ACTIVE)
            ->update(['status' => AccessCard::REVOKED, 'revoked_at' => now(), 'revoke_reason' => AccessCard::REASON_REMOVED_WITH_USER]);
        DeviceRosterEntry::query()
            ->where('device_id', $device->id)
            ->update([
                'claim_status' => DeviceRosterEntry::IGNORED,
                'claimed_employee_id' => null,
                'ignore_reason' => 'Wiped from the terminal '.now()->toDateString().'; PIN kept reserved',
            ]);

        return ['removed' => $removed, 'linked' => count($linked), 'unlinked' => count($users) - count($linked)];
    }

    /**
     * Rule 19, step 1: copies the person's card and fingers off this terminal into
     * the system, or refuses the removal. A credential the terminal cannot read at all
     * (a capability, not a failure) is skipped — there was never anything to copy.
     *
     * Card: read off the terminal's own record (the stored mapping may be stale), put
     * in the card history (access_cards — the system's copy of cards per terminal) and
     * onto the employee record if that has none. Fingers: FingerprintVault.
     */
    private function saveCredentialsBeforeRemoval(Device $device, Employee $employee, string $pin): void
    {
        $capabilities = $this->driver->capabilities($device);

        try {
            if ($capabilities->supports(Capability::USER_READ)) {
                foreach ($this->driver->readUsers($device, false) as $user) {
                    if ($user->userId === $pin) {
                        // Their privilege goes with the record too; kept on the mapping so
                        // a re-add can put an admin back as an admin (privilegeToRestore).
                        $this->identity->activeMap($device, $employee)?->update(['device_privilege' => $user->privilege]);
                    }
                    if ($user->userId === $pin) {
                        $this->capturePassword($device, $employee->id, $user);
                    }
                    if ($user->userId === $pin && $user->hasCard() && ! $user->cardLooksLikeSentinel()) {
                        $this->recordCard($device, $employee->id, $user);
                        if (empty($employee->card_number)) {
                            $employee->update(['card_number' => $user->cardNumber]);
                        }
                    }
                }
            }

            if ($capabilities->supports(Capability::TEMPLATE_BLOB_READ)) {
                $vault = app(FingerprintVault::class);
                $vault->assertReady();
                $vault->capture($device, $this->driver->readTemplates($device), $employee->id);
            }
        } catch (\Throwable $e) {
            // DomainException: a refusal with an operator-readable reason, which the
            // controller returns as a 409 rather than letting it become "Server error.".
            throw new \DomainException(
                "{$employee->name} was not removed from {$device->name}: their card and fingerprints could not be saved to the system first ({$e->getMessage()}). Try again when the terminal answers."
            );
        }
    }

    /**
     * Makes this employee an administrator of the terminal (may open its menu) or a
     * normal user (owner, 2026-10-18: "be able to change the access on the device").
     * Name, card, keypad password and fingerprints are kept by the driver; the
     * privilege is read back and only a confirmed value is stored (rule 10).
     *
     * Gated by user.privilege: IMPLEMENTED on the F18, UNKNOWN on the F22 and generic
     * terminals, NOT_IMPLEMENTED on C3 and CSL (docs/device-models.md).
     *
     * @return array{message:string, raw:array<string,mixed>}
     */
    public function setPrivilege(Device $device, Employee $employee, bool $admin): array
    {
        $this->driver->capabilities($device)->require(Capability::USER_PRIVILEGE);

        $map = $this->identity->activeMap($device, $employee);

        if ($map === null || $map->device_user_id === null) {
            throw new \DomainException("{$employee->name} is not on {$device->name}. Add them to the terminal first.");
        }

        $before = $map->device_privilege;
        $user = $this->driver->setUserPrivilege($device, $map->device_user_id, $admin);

        if (($user->privilege > 0) !== $admin) {
            throw new VerificationException(
                "{$device->name} accepted the change but reads {$employee->name} back as "
                .($user->privilege > 0 ? 'an administrator' : 'a normal user').'.'
            );
        }

        $map->update(['device_privilege' => $user->privilege, 'synced_at' => now()]);
        $this->recordRosterEntry($device, $user);

        AuditLog::create([
            'tenant_id' => $device->tenant_id,
            'user_id' => Auth::id(),
            'action' => 'device.privilege_changed',
            'auditable_type' => Employee::class,
            'auditable_id' => $employee->id,
            'changes' => [
                'device_id' => $device->id, 'device' => $device->name, 'device_user_id' => $map->device_user_id,
                'privilege_before' => $before, 'privilege_after' => $user->privilege,
            ],
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : substr((string) request()->userAgent(), 0, 255),
        ]);

        return [
            'message' => $admin
                ? "{$employee->name} is now an administrator on {$device->name}."
                : "{$employee->name} is now a normal user on {$device->name}.",
            'raw' => ['device_privilege' => $user->privilege, 'is_admin' => $user->privilege > 0],
        ];
    }

    /**
     * Rule 19, step 3: which card goes back on this terminal for this person, or null.
     *
     *   1. The card this terminal last held for them, if it left WITH their record
     *      (a removal) or is still recorded active — what they badge with at THIS
     *      terminal, which can differ from other terminals (Ghassan's Camelot card).
     *   2. If this terminal has no card history for them: the employee record's card.
     *   Never: a card an operator cleared on purpose, one reported lost, or a value
     *   that looks like the firmware's "no card" marker.
     */
    public function cardToRestore(Device $device, Employee $employee): ?int
    {
        $last = AccessCard::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->orderByDesc('id')
            ->first();

        if ($last !== null) {
            $keepable = $last->status === AccessCard::ACTIVE
                || ($last->status === AccessCard::REVOKED && $last->revoke_reason === AccessCard::REASON_REMOVED_WITH_USER);
            $card = $keepable && ! $last->is_suspect ? (int) $last->card_number : 0;
        } else {
            $card = (int) ($employee->card_number ?? 0);
        }

        if ($card < 1 || $card >= AccessCard::SENTINEL) {
            return null;
        }

        $lost = AccessCard::query()
            ->where('employee_id', $employee->id)
            ->where('card_number', $card)
            ->where('status', AccessCard::LOST)
            ->exists();

        return $lost ? null : $card;
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
            'synced_at' => now(),
            'reconciliation_status' => EmployeeDeviceMap::IN_SYNC,
            'reconciliation_detail' => null,
        ];

        /*
         * Slots only when this read DECODED them. null means "the table would not
         * decode" or "templates were not asked for" — neither is evidence about the
         * fingers, so it must not erase a known answer (rule 10; readEnrollment()
         * already works this way). On 2026-09-23 a re-read after a card scan on IT Room
         * wiped ali-test2's known slot [5] to unknown through exactly this line.
         */
        if ($user->fingerSlots !== null) {
            $attributes['fingerprint_slots'] = $user->fingerSlots;
        }

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

    /**
     * Records the card the device holds, as history rather than overwriting.
     *
     * Public so a card written by number (EnrollmentOrchestratorService::assignCard)
     * lands in exactly the same history as one read off the device.
     */
    public function recordCard(Device $device, int $employeeId, DeviceUser $user): void
    {
        if (! $user->hasCard()) {
            return;
        }

        // Only an ACTIVE row for this number means "already recorded". A card that was
        // replaced and has now come back (ali-test2, 2026-09-23: 2862247 was replaced
        // by a bad scan, then written again) must become active again, or the history
        // keeps naming the wrong card as current.
        $existing = AccessCard::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employeeId)
            ->where('card_number', $user->cardNumber)
            ->where('status', AccessCard::ACTIVE)
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

        // Markers only: a row carrying a template is the system's copy of that finger
        // (FingerprintVault), kept after the terminal loses it so it can be put back.
        BiometricTemplate::query()
            ->where('device_id', $device->id)
            ->where('employee_id', $employeeId)
            ->whereNotIn('finger_slot', $user->fingerSlots ?: [-1])
            ->whereNull('template_sha1')
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
