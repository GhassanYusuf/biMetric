<?php

namespace App\Services\Zkteco;

use App\Events\PunchRecorded;
use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bridges the device protocol clients with the application's
 * Device/Employee/AttendanceLog models. Dispatches to whichever client
 * matches the device's configured protocol:
 *  - binary:   classic ZK TCP protocol on port 4370 (ZktecoDevice)
 *  - csl_http: reseller-branded web-UI export, e.g. "Secutime"/"CSL"
 *              firmware (CslWebPortalClient) - some real-world ZKTeco
 *              hardware ships with this instead of the binary protocol.
 */
class DeviceSyncService
{
    public function testConnection(Device $device): array
    {
        if ($device->protocol === Device::PROTOCOL_CSL_HTTP) {
            $client = CslWebPortalClient::fromModel($device);
            $client->login();
            $uids = $client->fetchEnrolledUids();

            return [
                'ok' => true,
                'device_time' => null,
                'firmware' => null,
                'note' => count($uids) . ' enrolled user(s) found via web export.',
            ];
        }

        return ZktecoDevice::fromModel($device)->testConnection();
    }

    /** Pulls attendance punches from the device and stores any not already recorded. */
    public function syncAttendance(Device $device): int
    {
        try {
            $records = $device->protocol === Device::PROTOCOL_CSL_HTTP
                ? $this->fetchViaCslHttp($device)
                : $this->fetchViaBinary($device);

            $inserted = $this->storeRecords($device, $records);

            $device->update([
                'last_synced_at' => now(),
                'last_sync_status' => 'success',
                'last_sync_error' => null,
            ]);
        } catch (ZktecoException $e) {
            Log::warning("Device sync failed for device {$device->id}: {$e->getMessage()}");

            $device->update([
                'last_synced_at' => now(),
                'last_sync_status' => 'error',
                'last_sync_error' => $e->getMessage(),
            ]);

            // Alert operators (persisted notification + live MQTT) via a listener.
            event(new \App\Events\DeviceSyncFailed($device, $e->getMessage()));

            throw $e;
        }

        return $inserted;
    }

    /**
     * Pulls the device's enrolled-user directory (ID number + name) and
     * creates a matching Employee record for anyone not already in the
     * system. Read-only against the device - it never touches anything
     * stored on the terminal. Existing employees are left untouched so a
     * re-import can never clobber edits made in the payroll app.
     *
     * @return array{created:int, already_existed:int, total_on_device:int, backfilled:int}
     */
    public function importEmployees(Device $device): array
    {
        $directory = $device->protocol === Device::PROTOCOL_CSL_HTTP
            ? $this->fetchDirectoryViaCslHttp($device)
            : $this->fetchDirectoryViaBinary($device);

        $created = 0;
        $alreadyExisted = 0;

        foreach ($directory as $entry) {
            if ($entry['employee_code'] === '') {
                continue;
            }

            $employee = Employee::query()->where('employee_code', $entry['employee_code'])->first();

            if ($employee === null) {
                Employee::create([
                    'employee_code' => $entry['employee_code'],
                    'name' => $entry['name'],
                ]);
                $created++;
            } else {
                $alreadyExisted++;
            }
        }

        $backfilled = $this->backfillEmployeeMapping();

        // Best-effort: refresh card/fingerprint enrollment status off the same
        // device now that the roster is in sync. A failure here (e.g. this
        // firmware doesn't expose templates the way we read them) must not
        // fail the import that already succeeded above.
        $enrollment = ['updated' => 0, 'fingerprints_known' => false];
        try {
            $enrollment = $this->syncEnrollmentStatus($device);
        } catch (ZktecoException $e) {
            Log::warning("Enrollment-status sync failed for device {$device->id}: {$e->getMessage()}");
        }

        return [
            'created' => $created,
            'already_existed' => $alreadyExisted,
            'total_on_device' => count($directory),
            'backfilled' => $backfilled,
            'enrollment_updated' => $enrollment['updated'],
            'fingerprints_known' => $enrollment['fingerprints_known'],
        ];
    }

    /**
     * Reads each enrolled user's card number and fingerprint count off the
     * device and stores them on the matching Employee. Binary/TCP-IP only -
     * the CSL/web-export firmware doesn't expose card or template data here,
     * so nothing is touched for those devices (existing values are left as-is
     * rather than being wiped to null).
     *
     * Card numbers are read straight from the user record and are reliable.
     * Fingerprint counts come from getFingerprintCounts(), which returns an
     * empty map if the template table can't be read/decoded - in that case
     * fingerprint_count is left untouched (kept "unknown") instead of being
     * set to a possibly-wrong 0.
     *
     * @return array{updated:int, fingerprints_known:bool}
     */
    public function syncEnrollmentStatus(Device $device): array
    {
        if ($device->protocol !== Device::PROTOCOL_BINARY) {
            return ['updated' => 0, 'fingerprints_known' => false];
        }

        $zk = ZktecoDevice::fromModel($device);

        // Slots rather than bare counts: the template table already carries a
        // finger index per record, so one read tells us WHICH fingers each person
        // has. That is what lets the roster draw enrolled fingers without a
        // connection per row.
        [$users, $fingerSlots] = $zk->run(fn (ZktecoDevice $d) => [
            $d->getUsers(),
            $d->getFingerprintSlots(),
        ]);

        $fingerprintsKnown = $fingerSlots !== [];
        $now = now();
        $updated = 0;

        foreach ($users as $user) {
            $code = $user['user_id'] ?? null;
            if ($code === null || $code === '') {
                continue;
            }

            $employee = Employee::query()->where('employee_code', $code)->first();
            if ($employee === null) {
                continue;
            }

            $attributes = [
                'card_number' => ($user['card_number'] ?? 0) > 0 ? $user['card_number'] : null,
                'enrollment_synced_at' => $now,
            ];

            // Only overwrite fingerprint state when we actually read the template
            // table; otherwise leave whatever was there before rather than
            // replacing a known answer with a guess.
            if ($fingerprintsKnown) {
                $slots = $fingerSlots[$code] ?? [];
                $attributes['fingerprint_slots'] = $slots;
                $attributes['fingerprint_count'] = count($slots);
            }

            $employee->update($attributes);
            $updated++;
        }

        return ['updated' => $updated, 'fingerprints_known' => $fingerprintsKnown];
    }

    /**
     * Enrolls an employee directly on the device. Strictly follows the
     * device's configured Protocol - TCP/IP binary by default, only ever
     * using the web UI if the device is explicitly set to CSL/web export.
     * The write is always read back and confirmed before reporting
     * success - never trust a bare acknowledgement.
     */
    public function pushEmployeeToDevice(Device $device, Employee $employee): void
    {
        if ($device->protocol === Device::PROTOCOL_CSL_HTTP) {
            $this->pushEmployeeViaCslHttp($device, $employee);

            return;
        }

        $this->pushEmployeeViaBinary($device, $employee);
    }

    /**
     * Triggers live fingerprint capture on the device for an employee -
     * the device shows their name on its own screen and waits for a
     * finger. Binary/TCP-IP protocol only; the employee must already be
     * enrolled on the device (push them there first if not).
     *
     * @return array{success:bool, finger_index?:int, error_code?:int}
     */
    public function enrollFingerprint(Device $device, Employee $employee, int $fingerIndex, ?\Closure $onProgress = null): array
    {
        if ($device->protocol !== Device::PROTOCOL_BINARY) {
            throw new ZktecoException('Fingerprint enrollment requires the Binary/TCP-IP protocol.');
        }

        $zk = ZktecoDevice::fromModel($device);

        return $zk->run(function (ZktecoDevice $d) use ($employee, $fingerIndex, $onProgress) {
            self::ensureOnDevice($d, $employee);

            return $d->enrollFingerprint($employee->employee_code, $fingerIndex, $onProgress);
        });
    }

    /**
     * Reads ONE employee's enrollment straight off the terminal: which finger
     * slots hold a template, and whether a card is registered.
     *
     * ON DEMAND ONLY. Enrollment lives in the employees table (fingerprint_slots,
     * fingerprint_count, card_number) and every screen reads it from there, so
     * nothing is waiting on hardware to render. This opens a connection and takes
     * seconds; call it when someone explicitly asks for fresh data, never on a
     * page load. Use syncEnrollmentStatus() to refresh a whole roster in one
     * conversation instead of looping this.
     *
     * Whatever it reads is written back to the employee, so an explicit refresh
     * updates what every other screen shows rather than only this response.
     *
     * `fingerprint_slots` is null when the template table could not be trusted,
     * which is distinct from an empty list meaning "none enrolled".
     *
     * @return array{
     *     on_device:bool, fingerprint_slots:list<int>|null, fingerprint_count:int|null,
     *     card_number:int|null, card_count:int, synced_at:string|null
     * }
     */
    public function readEmployeeEnrollment(Device $device, Employee $employee): array
    {
        if ($device->protocol !== Device::PROTOCOL_BINARY) {
            throw new ZktecoException('Reading enrollment requires the Binary/TCP-IP protocol.');
        }

        $zk = ZktecoDevice::fromModel($device);

        $result = $zk->run(function (ZktecoDevice $d) use ($employee) {
            $user = self::findByEmployeeCode($d->getUsers(), $employee->employee_code);

            if ($user === null) {
                return [
                    'on_device' => false,
                    'fingerprint_slots' => null,
                    'fingerprint_count' => null,
                    'card_number' => null,
                    'card_count' => 0,
                ];
            }

            $slotMap = $d->getFingerprintSlots();
            $known = $slotMap !== [];
            $slots = $known ? ($slotMap[$employee->employee_code] ?? []) : null;

            $cardNumber = ($user['card_number'] ?? 0) > 0 ? (int) $user['card_number'] : null;

            return [
                'on_device' => true,
                'fingerprint_slots' => $slots,
                'fingerprint_count' => $slots === null ? null : count($slots),
                'card_number' => $cardNumber,
                // The ZKTeco user record holds exactly one card field, so this is
                // structurally 0 or 1 — not a limit we impose.
                'card_count' => $cardNumber === null ? 0 : 1,
            ];
        });

        // Persist so the roster and every other screen see the refreshed values.
        if ($result['on_device']) {
            $attributes = [
                'card_number' => $result['card_number'],
                'enrollment_synced_at' => now(),
            ];

            // Only overwrite fingerprint state when the template table decoded;
            // otherwise keep the last known answer rather than erasing it.
            if ($result['fingerprint_slots'] !== null) {
                $attributes['fingerprint_slots'] = $result['fingerprint_slots'];
                $attributes['fingerprint_count'] = count($result['fingerprint_slots']);
            }

            $employee->update($attributes);
        }

        $result['synced_at'] = $employee->fresh()->enrollment_synced_at?->toIso8601String();

        return $result;
    }

    /**
     * Clears the employee's card on the terminal, keeping their record, their
     * fingerprints and their privilege.
     *
     * There is no "delete card" command: the card lives in a single field on the
     * user record, so clearing it means rewriting that record with card 0. That
     * is also why this reuses the existing uid — a new uid would orphan their
     * fingerprint templates, which are keyed by uid.
     *
     * Read back and confirmed before reporting success, like every other write.
     *
     * @return array{cleared:bool, reason:string|null, previous_card:int|null}
     */
    public function clearCard(Device $device, Employee $employee): array
    {
        if ($device->protocol !== Device::PROTOCOL_BINARY) {
            throw new ZktecoException('Clearing a card requires the Binary/TCP-IP protocol.');
        }

        $zk = ZktecoDevice::fromModel($device);

        $result = $zk->run(function (ZktecoDevice $d) use ($employee) {
            $user = self::findByEmployeeCode($d->getUsers(), $employee->employee_code);

            if ($user === null) {
                throw new ZktecoException('This employee is not enrolled on the device.');
            }

            $previous = (int) ($user['card_number'] ?? 0);

            if ($previous === 0) {
                return ['cleared' => false, 'reason' => 'no_card', 'previous_card' => null];
            }

            // Same name/code/privilege, card zeroed.
            $d->writeUser(
                $user['uid'],
                $employee->employee_code,
                $user['name'] ?? $employee->name,
                (int) ($user['privilege'] ?? 0),
                0,
            );

            $confirmed = self::findByEmployeeCode($d->getUsers(), $employee->employee_code);

            if ($confirmed === null) {
                throw new ZktecoException(
                    'The card write was accepted but the employee is gone from the device when read back.'
                );
            }

            if ((int) ($confirmed['card_number'] ?? 0) !== 0) {
                throw new ZktecoException(
                    "The device still reports card #{$confirmed['card_number']} after the clear."
                );
            }

            return ['cleared' => true, 'reason' => null, 'previous_card' => $previous];
        });

        if ($result['cleared']) {
            $employee->update([
                'card_number' => null,
                'enrollment_synced_at' => now(),
            ]);
        }

        return $result;
    }

    /**
     * Deletes ONE fingerprint from the terminal, keeping the employee's other
     * fingers and their card. Destructive and not reversible from here — the
     * person has to re-enroll that finger.
     *
     * The template table is read BEFORE and AFTER: before, to refuse a delete we
     * cannot verify and to detect a slot that was never enrolled; after, to
     * confirm the finger is actually gone rather than trusting the device's
     * acknowledgement. The employee's stored slots are then updated from the
     * confirmed read.
     *
     * @return array{deleted:bool, reason:string|null, slots:list<int>}
     */
    public function deleteFingerprint(Device $device, Employee $employee, int $slot): array
    {
        if ($device->protocol !== Device::PROTOCOL_BINARY) {
            throw new ZktecoException('Deleting a fingerprint requires the Binary/TCP-IP protocol.');
        }

        if ($slot < 0 || $slot > 9) {
            throw new ZktecoException("Finger slot must be 0-9, got {$slot}.");
        }

        $zk = ZktecoDevice::fromModel($device);

        $result = $zk->run(function (ZktecoDevice $d) use ($employee, $slot) {
            $user = self::findByEmployeeCode($d->getUsers(), $employee->employee_code);

            if ($user === null) {
                throw new ZktecoException('This employee is not enrolled on the device.');
            }

            $before = $d->getFingerprintSlots();

            // Refusing here is deliberate: without a readable template table we
            // could neither tell what is enrolled nor prove the delete worked,
            // and a silent no-op on biometric data is worse than an error.
            if ($before === []) {
                throw new ZktecoException(
                    "The device's fingerprint table could not be read, so a delete cannot be verified."
                );
            }

            $beforeSlots = $before[$employee->employee_code] ?? [];

            if (! in_array($slot, $beforeSlots, true)) {
                return ['deleted' => false, 'reason' => 'not_enrolled', 'slots' => $beforeSlots];
            }

            $d->deleteFingerprint($user['uid'], $slot);

            $after = $d->getFingerprintSlots();

            if ($after === []) {
                throw new ZktecoException(
                    "The delete was sent but the device's fingerprint table could not be re-read to confirm it."
                );
            }

            $afterSlots = $after[$employee->employee_code] ?? [];

            if (in_array($slot, $afterSlots, true)) {
                throw new ZktecoException("The device still reports slot {$slot} as enrolled after the delete.");
            }

            return ['deleted' => true, 'reason' => null, 'slots' => $afterSlots];
        });

        // Confirmed state wins over whatever was stored.
        $employee->update([
            'fingerprint_slots' => $result['slots'],
            'fingerprint_count' => count($result['slots']),
            'enrollment_synced_at' => now(),
        ]);

        return $result;
    }

    /**
     * Triggers live card-read capture on the device for an employee - waits
     * for the next card swipe and writes it straight onto their existing
     * device record. Binary/TCP-IP protocol only; the employee must already
     * be enrolled on the device (push them there first if not).
     *
     * @return array{success:bool, card_number?:int}
     */
    public function enrollCard(Device $device, Employee $employee, ?\Closure $onProgress = null): array
    {
        if ($device->protocol !== Device::PROTOCOL_BINARY) {
            throw new ZktecoException('Card enrollment requires the Binary/TCP-IP protocol.');
        }

        $zk = ZktecoDevice::fromModel($device);

        return $zk->run(function (ZktecoDevice $d) use ($employee, $onProgress) {
            $existing = self::ensureOnDevice($d, $employee);

            return $d->enrollCard(
                $existing['uid'],
                $employee->employee_code,
                $existing['name'],
                $existing['privilege'],
                $onProgress
            );
        });
    }

    /** Removes an employee's enrollment from the device. Same transport rule as pushEmployeeToDevice. */
    public function removeEmployeeFromDevice(Device $device, Employee $employee): void
    {
        if ($device->protocol === Device::PROTOCOL_CSL_HTTP) {
            $this->removeEmployeeViaCslHttp($device, $employee);
            $this->forgetStoredEnrollment($employee);

            return;
        }

        $this->removeEmployeeViaBinary($device, $employee);
        $this->forgetStoredEnrollment($employee);
    }

    /**
     * Clears the stored enrollment after a confirmed removal. Deleting the user
     * record takes their fingerprints and card with it, so leaving the old counts
     * on the roster would show enrollment that no longer exists anywhere.
     *
     * NOTE: these columns describe one terminal's worth of enrollment — the
     * schema has no per-device breakdown. With a single terminal that is exact.
     * If this workspace ever runs several, removing from one would clear values
     * that another still holds, and the columns would need a device dimension.
     */
    private function forgetStoredEnrollment(Employee $employee): void
    {
        $employee->update([
            'card_number' => null,
            'fingerprint_count' => null,
            'fingerprint_slots' => null,
            'enrollment_synced_at' => null,
        ]);
    }

    private function pushEmployeeViaCslHttp(Device $device, Employee $employee): void
    {
        $client = CslWebPortalClient::fromModel($device);
        $client->login();

        if (self::findByEmployeeCode($client->fetchEmployeeDirectory(), $employee->employee_code) !== null) {
            return; // already enrolled - nothing to do
        }

        $client->addUser($employee->employee_code, $employee->name);

        $confirmed = self::findByEmployeeCode($client->fetchEmployeeDirectory(), $employee->employee_code);
        if ($confirmed === null) {
            throw new ZktecoException('The device web UI accepted the request but the enrollment did not appear when read back.');
        }
    }

    private function removeEmployeeViaCslHttp(Device $device, Employee $employee): void
    {
        $client = CslWebPortalClient::fromModel($device);
        $client->login();

        $existing = self::findByEmployeeCode($client->fetchEmployeeDirectory(), $employee->employee_code);
        if ($existing === null) {
            throw new ZktecoException('This employee is not currently enrolled on the device.');
        }

        $client->deleteUser($existing['uid']);

        $stillThere = self::findByEmployeeCode($client->fetchEmployeeDirectory(), $employee->employee_code);
        if ($stillThere !== null) {
            throw new ZktecoException('The device web UI accepted the delete but the enrollment is still there when read back.');
        }
    }

    private function pushEmployeeViaBinary(Device $device, Employee $employee): void
    {
        $zk = ZktecoDevice::fromModel($device);
        $zk->run(function (ZktecoDevice $d) use ($employee) {
            $users = $d->getUsers();
            $existing = self::findByEmployeeCode($users, $employee->employee_code);
            $uid = $existing['uid'] ?? self::nextFreeUid($users);

            /*
             * Preserve the privilege and card already on the device.
             *
             * writeUser() defaults both to 0, so re-pushing someone who is
             * already enrolled would silently wipe their card number and demote
             * an admin to a plain user. Fingerprint templates are keyed by uid in
             * a separate table and the uid is reused here, so they survive the
             * rewrite — but the card lives in the user record itself and would
             * not have.
             */
            $privilege = (int) ($existing['privilege'] ?? 0);
            $cardNumber = (int) ($existing['card_number'] ?? $employee->card_number ?? 0);

            $d->writeUser($uid, $employee->employee_code, $employee->name, $privilege, $cardNumber);

            // This firmware has been observed to acknowledge a write (ACK_OK) and
            // then silently fail to persist it. Never report success without
            // reading the enrollment back and confirming it's actually there.
            $confirmed = self::findByEmployeeCode($d->getUsers(), $employee->employee_code);
            if ($confirmed === null) {
                throw new ZktecoException(
                    'The device accepted the write but the enrollment did not actually appear when read back. '
                    . 'This firmware intermittently silently drops writes - try again, and if it keeps happening the device may need a power cycle.'
                );
            }
        });
    }

    private function removeEmployeeViaBinary(Device $device, Employee $employee): void
    {
        $zk = ZktecoDevice::fromModel($device);
        $zk->run(function (ZktecoDevice $d) use ($employee) {
            $existing = self::findByEmployeeCode($d->getUsers(), $employee->employee_code);

            if ($existing === null) {
                throw new ZktecoException('This employee is not currently enrolled on the device.');
            }

            $d->deleteUser($existing['uid']);

            $stillThere = self::findByEmployeeCode($d->getUsers(), $employee->employee_code);
            if ($stillThere !== null) {
                throw new ZktecoException(
                    'The device accepted the delete but the enrollment is still there when read back. '
                    . 'This firmware intermittently silently drops writes - try again, and if it keeps happening the device may need a power cycle.'
                );
            }
        });
    }

    /**
     * Guarantees the employee has a record on the terminal, creating one if not,
     * and returns it.
     *
     * A capture attaches a template or card to an EXISTING user record, so this
     * used to just fail with "push them to the device first" — which made adding
     * a new person a two-step dance with an error in the middle. Now the write
     * happens automatically as part of the capture.
     *
     * Runs on the caller's already-open connection rather than calling
     * pushEmployeeToDevice(), which would open a second one.
     *
     * @return array the confirmed device user record
     */
    private static function ensureOnDevice(ZktecoDevice $d, Employee $employee): array
    {
        $existing = self::findByEmployeeCode($d->getUsers(), $employee->employee_code);

        if ($existing !== null) {
            return $existing;
        }

        $users = $d->getUsers();
        $d->writeUser(
            self::nextFreeUid($users),
            $employee->employee_code,
            $employee->name,
            0,
            (int) ($employee->card_number ?? 0),
        );

        // Same rule as every other write on this firmware: confirm by reading back.
        $confirmed = self::findByEmployeeCode($d->getUsers(), $employee->employee_code);

        if ($confirmed === null) {
            throw new ZktecoException(
                "{$employee->name} could not be added to the device automatically. "
                . 'This firmware intermittently drops writes - try again, and if it keeps happening the device may need a power cycle.'
            );
        }

        return $confirmed;
    }

    private static function findByEmployeeCode(array $users, string $employeeCode): ?array
    {
        foreach ($users as $user) {
            if (($user['user_id'] ?? $user['employee_code'] ?? null) === $employeeCode) {
                return $user;
            }
        }

        return null;
    }

    private static function nextFreeUid(array $users): int
    {
        $max = 0;
        foreach ($users as $user) {
            $max = max($max, (int) ($user['uid'] ?? 0));
        }

        return $max + 1;
    }

    /** Links any attendance rows still missing an employee_id, now that more employees may exist. */
    private function backfillEmployeeMapping(): int
    {
        $updated = 0;

        AttendanceLog::query()
            ->whereNull('employee_id')
            ->select('id', 'device_user_id')
            ->chunkById(500, function ($logs) use (&$updated) {
                foreach ($logs as $log) {
                    $employeeId = Employee::query()
                        ->where('employee_code', $log->device_user_id)
                        ->value('id');

                    if ($employeeId !== null) {
                        $log->update(['employee_id' => $employeeId]);
                        $updated++;
                    }
                }
            });

        return $updated;
    }

    private function fetchDirectoryViaCslHttp(Device $device): array
    {
        $client = CslWebPortalClient::fromModel($device);
        $client->login();

        return $client->fetchEmployeeDirectory();
    }

    private function fetchDirectoryViaBinary(Device $device): array
    {
        $zk = ZktecoDevice::fromModel($device);
        $users = $zk->run(fn (ZktecoDevice $d) => $d->getUsers());

        return array_map(fn (array $u) => [
            'employee_code' => (string) ($u['user_id'] ?? ''),
            'name' => $u['name'] ?? '',
        ], $users);
    }

    /** Pulls enrolled users from the device; returns them for the caller to reconcile/display. */
    public function fetchUsers(Device $device): array
    {
        if ($device->protocol === Device::PROTOCOL_CSL_HTTP) {
            $client = CslWebPortalClient::fromModel($device);
            $client->login();

            return array_map(fn (int $uid) => ['user_id' => (string) $uid], $client->fetchEnrolledUids());
        }

        $zk = ZktecoDevice::fromModel($device);

        return $zk->run(fn (ZktecoDevice $d) => $d->getUsers());
    }

    private function fetchViaBinary(Device $device): array
    {
        $zk = ZktecoDevice::fromModel($device);

        return $zk->run(fn (ZktecoDevice $d) => $d->getAttendanceLogs());
    }

    private function fetchViaCslHttp(Device $device): array
    {
        $client = CslWebPortalClient::fromModel($device);
        $client->login();

        // The device only exposes a date-range export (no "since last sync" cursor),
        // so pull from the last successful sync (or 90 days back on first run) through today.
        $from = $device->last_synced_at?->subDay() ?? now()->subDays(90);

        return $client->downloadAttendance($from, now());
    }

    private function storeRecords(Device $device, array $records): int
    {
        $parsed = array_values(array_filter(
            $records,
            fn (array $record) => isset($record['user_id'], $record['timestamp'])
        ));

        if ($parsed === []) {
            return 0;
        }

        // One query for every employee code instead of one query per record -
        // with the device's full history (thousands of records) re-pulled on
        // every sync, per-record lookups were the dominant cost.
        $employeeIds = Employee::query()
            ->whereIn('employee_code', array_unique(array_column($parsed, 'user_id')))
            ->pluck('id', 'employee_code');

        $now = now();

        $rows = array_map(function (array $record) use ($device, $employeeIds, $now) {
            // firstOrCreate's search-condition array bypasses Eloquent's datetime
            // casting entirely, so a DateTimeImmutable carrying a non-UTC offset
            // (e.g. the device's local +03:00) gets its raw wall-clock numbers
            // written as-is instead of being converted - confirmed against real
            // data. Always normalize to UTC ourselves before it ever reaches the DB.
            $punchedAtUtc = $record['timestamp']->setTimezone(new \DateTimeZone('UTC'));

            return [
                'device_id' => $device->id,
                'device_user_id' => $record['user_id'],
                'punched_at' => $punchedAtUtc->format('Y-m-d H:i:s'),
                'verify_method' => $record['verify_method'] ?? 0,
                'employee_id' => $employeeIds[$record['user_id']] ?? null,
                'status' => $record['status'] ?? 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $parsed);

        $inserted = 0;

        // Bulk INSERT OR IGNORE in a transaction: the unique constraint on
        // (device_id, device_user_id, punched_at, verify_method) still does the
        // deduplication, but as one statement per chunk instead of a
        // SELECT-then-INSERT round trip per record.
        DB::transaction(function () use ($rows, $device, &$inserted) {
            foreach (array_chunk($rows, 500) as $chunk) {
                // Figure out which of this chunk's natural keys are genuinely new
                // *before* inserting, so we know which ones to fire PunchRecorded
                // for afterward (insertOrIgnore's return count alone doesn't tell
                // us *which* rows were new) - one query per chunk, not per row.
                $existingKeys = DB::table('attendance_logs')
                    ->where('device_id', $device->id)
                    ->whereIn('device_user_id', array_unique(array_column($chunk, 'device_user_id')))
                    ->whereIn('punched_at', array_unique(array_column($chunk, 'punched_at')))
                    ->get(['device_user_id', 'punched_at', 'verify_method'])
                    ->map(fn ($row) => "{$row->device_user_id}|{$row->punched_at}|{$row->verify_method}")
                    ->flip();

                $newKeys = collect($chunk)
                    ->map(fn (array $r) => "{$r['device_user_id']}|{$r['punched_at']}|{$r['verify_method']}")
                    ->reject(fn (string $key) => $existingKeys->has($key))
                    ->flip();

                $inserted += DB::table('attendance_logs')->insertOrIgnore($chunk);

                if ($newKeys->isEmpty()) {
                    continue;
                }

                AttendanceLog::with(['employee', 'device'])
                    ->where('device_id', $device->id)
                    ->whereIn('device_user_id', array_unique(array_column($chunk, 'device_user_id')))
                    ->whereIn('punched_at', array_unique(array_column($chunk, 'punched_at')))
                    ->get()
                    ->each(function (AttendanceLog $log) use ($newKeys) {
                        $key = "{$log->device_user_id}|{$log->punched_at->format('Y-m-d H:i:s')}|{$log->verify_method}";

                        if ($newKeys->has($key)) {
                            event(new PunchRecorded($log));
                        }
                    });
            }
        });

        return $inserted;
    }

    /**
     * Single-punch counterpart to storeRecords() for the live realtime
     * listener - no chunking/bulk-diffing needed since it's one record at a
     * time. Fires PunchRecorded only when the row is genuinely new.
     */
    public function storeRealtimePunch(Device $device, array $record): ?AttendanceLog
    {
        if (!isset($record['user_id'], $record['timestamp'])) {
            return null;
        }

        $employeeId = Employee::query()
            ->where('employee_code', $record['user_id'])
            ->value('id');

        $punchedAtUtc = $record['timestamp']->setTimezone(new \DateTimeZone('UTC'));

        $log = AttendanceLog::query()->firstOrCreate(
            [
                'device_id' => $device->id,
                'device_user_id' => $record['user_id'],
                'punched_at' => $punchedAtUtc,
                'verify_method' => $record['verify_method'] ?? 0,
            ],
            [
                'employee_id' => $employeeId,
                'status' => $record['status'] ?? 0,
            ]
        );

        if ($log->wasRecentlyCreated) {
            $log->load(['employee', 'device']);
            event(new PunchRecorded($log));
        }

        return $log;
    }
}
