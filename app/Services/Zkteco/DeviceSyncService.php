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

        return [
            'created' => $created,
            'already_existed' => $alreadyExisted,
            'total_on_device' => count($directory),
            'backfilled' => $this->backfillEmployeeMapping(),
        ];
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
            if (self::findByEmployeeCode($d->getUsers(), $employee->employee_code) === null) {
                throw new ZktecoException('This employee is not enrolled on the device yet - push them to the device first.');
            }

            return $d->enrollFingerprint($employee->employee_code, $fingerIndex, $onProgress);
        });
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
            $existing = self::findByEmployeeCode($d->getUsers(), $employee->employee_code);

            if ($existing === null) {
                throw new ZktecoException('This employee is not enrolled on the device yet - push them to the device first.');
            }

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

            return;
        }

        $this->removeEmployeeViaBinary($device, $employee);
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

            $d->writeUser($uid, $employee->employee_code, $employee->name);

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
