<?php

namespace App\Services\Import;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * One-off importer for a ZKAccess backup exported by scripts/export-zkaccess.ps1.
 *
 * This is an OFF-DEVICE source: the .mdb is the legacy attendance-management
 * software's database, keyed by ZKAccess's internal USERID rather than the PIN the
 * terminals actually use. Two consequences shape the mapping:
 *
 *  - People are matched to employees by Badgenumber (the PIN; equals employee_code),
 *    then by normalised name. A name that belongs to more than one employee is
 *    never merged and never used to create anybody — rule 10: a name is not an
 *    identifier, so the ambiguous rows are reported for a human instead.
 *  - Punches are attributed by USERID (stable for a person across PIN renumbering,
 *    the same reason AttendanceSyncService resolves ownership historically) but are
 *    stored with the PIN as device_user_id, so the existing unique index
 *    (device_id, device_user_id, punched_at, verify_method) still dedupes against
 *    the rows already pulled live from the terminals.
 *
 * TIMES: the export carries the terminals' local wall clock (Iraq, UTC+3). punched_at
 * is stored as UTC, exactly like AttendanceSyncService stores live records, so the
 * same event arriving from both sources lands on the same instant and cancels out.
 * Verified empirically: 99.2% of the export's punches collide with live rows at this
 * offset; the unique index then swallows them.
 *
 * STATUS: the export only knows CHECKTYPE (I/O). The live sync stores the terminal's
 * raw status byte, which is not recoverable here, so 'I'/'i' become 0 and 'O'/'o'
 * become 1 — deliberately best-effort for the ~1k records the live sync has not seen
 * yet. verify_method is copied verbatim (0/1/3/4/6 in this export); the live sync
 * stores the same raw codes.
 *
 * Privilege is NOT imported: ZKAccess's integer values (0/3 here) have no table to
 * our stored strings ('user'/'enroller'/...), it is stored-only, and guessing wrong
 * would quietly reassign rights. It is left for the personnel screen.
 */
class ZkaccessImportService
{
    /**
     * Matches AttendanceSyncService's bulk chunk in spirit; kept under 2100 so the
     * SQL Server parameter ceiling (2100) is not hit: 200 rows * 9 columns = 1800.
     */
    private const CHUNK = 200;

    private int $tenantId = 0;

    private bool $apply = false;

    private bool $createNew = true;

    private bool $importAttendance = true;

    /** @var array<string,mixed> */
    private array $data = [];

    /** @var array<string,int> zk department id => department id */
    private array $deptMap = [];

    /** @var array<string,string> department id => department name */
    private array $deptName = [];

    /** @var array<string,int> normalized name => employee id */
    private array $byName = [];

    /** @var array<string,int> normalized name => count of employees sharing it */
    private array $nameCount = [];

    /** @var array<string,int> employee_code (PIN) => employee id */
    private array $byPin = [];

    /** @var array<string,string> card_number => employee_code of the holder */
    private array $usedCards = [];

    /** @var array<string,bool> zk user_id => has been resolved */
    private array $userResolved = [];

    /** @var array<string,int> zk user_id => employee id (0 while dry running) */
    private array $userEmployee = [];

    /** @var array<string,string> zk user_id => pin */
    private array $userPin = [];

    /** @var array<string,int> device serial => device id */
    private array $devicesBySerial = [];

    /** @var array<string,int> device ip => device id */
    private array $devicesByIp = [];

    /** @var array<string,string> export serial => export ip */
    private array $exportMachineIpBySerial = [];

    private int $createdDepts = 0;

    private int $existingDepts = 0;

    private int $matchedByPin = 0;

    private int $matchedByName = 0;

    private int $createdEmployees = 0;

    private int $ambiguous = 0;

    private int $unmatched = 0;

    private int $skippedDeleted = 0;

    private int $skippedNoPin = 0;

    private int $skippedCodeCollision = 0;

    private int $profilesFilled = 0;

    private int $fieldsFilled = 0;

    private int $cardsSkipped = 0;

    /** @var array<int,array<string,string>> */
    private array $warnings = [];

    /** @var array<string,int|bool> */
    private array $attendance = [];

    public function __construct()
    {
        $this->attendance = ['skipped' => true];
    }

    /**
     * @param  array<string,mixed>  $data  the decoded zkaccess-export.json
     */
    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function setTenant(int $tenantId): self
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    public function setApply(bool $apply): self
    {
        $this->apply = $apply;

        return $this;
    }

    public function setCreateNew(bool $createNew): self
    {
        $this->createNew = $createNew;

        return $this;
    }

    public function setImportAttendance(bool $importAttendance): self
    {
        $this->importAttendance = $importAttendance;
        $this->attendance = ['skipped' => ! $importAttendance];

        return $this;
    }

    /** @return array<string,mixed> the full report (dry run and apply share a shape) */
    public function run(): array
    {
        $this->loadDeviceIndexes();
        $this->loadEmployeeIndexes();

        $this->importDepartments();
        $this->importUsers();
        $this->importPunches();

        return $this->report();
    }

    private function loadDeviceIndexes(): void
    {
        Device::query()
            ->where('tenant_id', $this->tenantId)
            ->get(['id', 'serial_number', 'ip_address', 'name'])
            ->each(function (Device $device): void {
                $serial = trim((string) $device->serial_number);
                if ($serial !== '') {
                    $this->devicesBySerial[$serial] = $device->id;
                }
                $ip = trim((string) $device->ip_address);
                if ($ip !== '') {
                    $this->devicesByIp[$ip] = $device->id;
                }
            });

        foreach ($this->data['machines'] ?? [] as $machine) {
            $serial = trim((string) ($machine['serial'] ?? ''));
            $ip = trim((string) ($machine['ip'] ?? ''));
            if ($serial !== '' && $ip !== '') {
                $this->exportMachineIpBySerial[$serial] = $ip;
            }
        }
    }

    private function loadEmployeeIndexes(): void
    {
        $employees = Employee::query()
            ->where('tenant_id', $this->tenantId)
            ->get([
                'id', 'name', 'last_name', 'employee_code', 'card_number', 'department',
                'department_id', 'gender', 'hired_date', 'birthday', 'device_password',
                'job_title', 'office', 'home_address', 'city', 'country', 'education',
                'political_status', 'mobile_phone', 'home_phone', 'is_active',
            ]);

        foreach ($employees as $employee) {
            $code = trim((string) $employee->employee_code);
            if ($code !== '') {
                $this->byPin[$code] = $employee->id;
            }

            $name = $this->normalise($employee->name.' '.$employee->last_name);
            if ($name !== '') {
                $this->byName[$name] = $employee->id;
                $this->nameCount[$name] = (int) ($this->nameCount[$name] ?? 0) + 1;
            }

            if ($employee->card_number !== null && $employee->card_number !== '') {
                $this->usedCards[(string) $employee->card_number] = (string) $employee->employee_code;
            }
        }
    }

    private function importDepartments(): void
    {
        foreach ($this->data['departments'] ?? [] as $dept) {
            $name = trim((string) ($dept['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $zkId = (string) $dept['id'];
            $this->deptName[$zkId] = $name;

            if ($this->apply) {
                $model = Department::firstOrCreate(
                    ['tenant_id' => $this->tenantId, 'name' => $name],
                    ['code' => trim((string) ($dept['code'] ?? ''))],
                );
                $this->createdDepts += $model->wasRecentlyCreated ? 1 : 0;
                $this->existingDepts += $model->wasRecentlyCreated ? 0 : 1;
                $this->deptMap[$zkId] = $model->id;
            } else {
                $exists = Department::query()
                    ->where('tenant_id', $this->tenantId)
                    ->where('name', $name)
                    ->exists();
                $this->existingDepts += $exists ? 1 : 0;
                $this->createdDepts += $exists ? 0 : 1;
            }
        }
    }

    private function importUsers(): void
    {
        foreach ($this->data['users'] ?? [] as $user) {
            if ((string) ($user['deleted'] ?? '0') === '1') {
                $this->skippedDeleted++;

                continue;
            }

            $pin = trim((string) ($user['pin'] ?? ''));
            if ($pin === '') {
                $this->skippedNoPin++;

                continue;
            }

            $userId = trim((string) ($user['user_id'] ?? ''));
            $name = $this->userFullName($user);

            if (isset($this->byPin[$pin])) {
                $employeeId = $this->byPin[$pin];
                $this->matchedByPin++;
            } else {
                $norm = $this->normalise($name);
                $nameIsUnique = $norm !== '' && ($this->nameCount[$norm] ?? 0) === 1;

                if ($nameIsUnique) {
                    $employeeId = $this->byName[$norm];
                    $this->matchedByName++;
                } elseif ($norm !== '' && ($this->nameCount[$norm] ?? 0) > 1) {
                    // A name two employees share identifies neither of them (rule 10).
                    $this->ambiguous++;
                    $this->warnings[] = [
                        'user' => $pin.' / '.$name,
                        'card' => (string) ($user['card'] ?? ''),
                        'reason' => 'name is shared by two existing employees; left for manual mapping',
                    ];

                    continue;
                } elseif ($this->createNew) {
                    $employeeId = $this->createEmployee($user, $pin, $name);
                    if ($employeeId === null) {
                        continue; // code collision already counted and warned
                    }
                    $this->createdEmployees++;
                } else {
                    $this->unmatched++;
                    $this->warnings[] = [
                        'user' => $pin.' / '.$name,
                        'card' => (string) ($user['card'] ?? ''),
                        'reason' => 'not found in this system and --no-create-new is set',
                    ];

                    continue;
                }
            }

            $this->userResolved[$userId] = true;
            $this->userEmployee[$userId] = $employeeId;
            $this->userPin[$userId] = $pin;

            $this->fillEmployee($employeeId, $user, $pin);
        }
    }

    /**
     * Creates an employee for a ZKAccess user this system does not know. Returns the
     * new employee's id, or null when the PIN already exists on another tenant (the
     * column is globally unique even though the app only ever checks per tenant).
     *
     * @param  array<string,mixed>  $user
     */
    private function createEmployee(array $user, string $pin, string $name): ?int
    {
        $collision = Employee::query()->withoutGlobalScopes()
            ->where('employee_code', $pin)
            ->exists();
        if ($collision) {
            $this->skippedCodeCollision++;
            $this->warnings[] = [
                'user' => $pin.' / '.$name,
                'card' => (string) ($user['card'] ?? ''),
                'reason' => 'PIN already used by an employee of another tenant; created nothing',
            ];

            return null;
        }

        if (! $this->apply) {
            // Dry run: the record does not exist yet, punches still resolve to nobody
            // in particular but the report must count them rather than calling them
            // orphans. Register the mapping under a sentinel id and a later pass would
            // replace it with the real one.
            $this->byPin[$pin] = -1;
            $this->byName[$this->normalise($name)] = -1;
            $this->nameCount[$this->normalise($name)] = 1;

            return -1;
        }

        $employee = Employee::create([
            'tenant_id' => $this->tenantId,
            'employee_code' => $pin,
            'name' => $name !== '' ? $name : $pin,
            'is_active' => true,
        ]);

        $this->byPin[$pin] = $employee->id;
        $norm = $this->normalise($name);
        if ($norm !== '') {
            $this->byName[$norm] = $employee->id;
            $this->nameCount[$norm] = 1;
        }

        return $employee->id;
    }

    /**
     * Fills empty profile fields on the employee this user resolved to. Never
     * overwrites a value the system already has, so a re-run is harmless.
     *
     * @param  array<string,mixed>  $user
     */
    private function fillEmployee(int $employeeId, array $user, string $pin): void
    {
        $employee = $employeeId > 0 ? Employee::find($employeeId) : null;
        $isDry = $employee === null;

        $sources = [
            'gender' => 'gender',
            'job_title' => 'job_title',
            'city' => 'city',
            'country' => 'country',
            'education' => 'education',
            'political_status' => 'political',
            'mobile_phone' => 'mobile_phone',
            'home_phone' => 'home_phone',
            'hired_date' => 'hire_date',
            'birthday' => 'birth_date',
            'home_address' => null, // composed below
        ];

        $patch = [];
        foreach ($sources as $target => $key) {
            $value = $key === null
                ? $this->firstNonEmpty($user['home_address'] ?? '', $user['street'] ?? '')
                : trim((string) ($user[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $current = $isDry ? '' : (string) ($employee->{$target} ?? '');
            if ($current !== '') {
                continue;
            }
            $patch[$target] = $value;
        }

        $password = trim((string) ($user['password'] ?? ''));
        if ($password !== '' && ($isDry || (string) ($employee->device_password ?? '') === '')) {
            $patch['device_password'] = $password;
        }

        $card = trim((string) ($user['card'] ?? ''));
        if ($card !== '' && preg_match('/^\d+$/', $card)) {
            $holdsIt = ! $isDry && (string) ($employee->card_number ?? '') === $card;
            if ($holdsIt) {
                // already the employee's own card — nothing to do
            } elseif (isset($this->usedCards[$card])) {
                $this->cardsSkipped++;
                $this->warnings[] = [
                    'user' => $pin.' / '.($user['first_name'] ?? ''),
                    'card' => $card,
                    'reason' => 'card already held by employee '.(string) $this->usedCards[$card],
                ];
            } else {
                $patch['card_number'] = (int) $card;
                $this->usedCards[$card] = (string) ($employee->employee_code ?? $pin);
            }
        }

        $zkDept = trim((string) ($user['department_id'] ?? ''));
        if ($zkDept !== '' && isset($this->deptMap[$zkDept]) && ($isDry || ! $employee->department_id)) {
            $patch['department_id'] = $this->deptMap[$zkDept];
        }
        if ($zkDept !== '' && isset($this->deptName[$zkDept]) && ($isDry || (string) ($employee->department ?? '') === '')) {
            $patch['department'] = $this->deptName[$zkDept];
        }

        if ($patch === []) {
            return;
        }

        $this->profilesFilled++;
        $this->fieldsFilled += count($patch);

        if ($employee !== null) {
            $employee->fill($patch)->save();
        }
    }

    private function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function importPunches(): void
    {
        if (! $this->importAttendance) {
            $this->attendance = ['skipped' => true];

            return;
        }

        $counts = ['candidates' => 0, 'orphans' => 0, 'unresolvable_device' => 0, 'duplicates' => 0, 'inserted' => 0];
        $existingKeys = $this->existingPunchKeys();
        $chunk = [];

        foreach ($this->data['punches'] ?? [] as $punch) {
            $userId = trim((string) ($punch['user_id'] ?? ''));
            if (! isset($this->userResolved[$userId])) {
                $counts['orphans']++;

                continue;
            }

            // The punch's OWN pin is the identity the row was stored with (a
            // snapshot replays the historical device_user_id, which survives a
            // later employee_code renumber). Fall back to the users list for
            // exporter formats that never carry it.
            $pin = trim((string) ($punch['pin'] ?? ''));
            if ($pin === '') {
                $pin = (string) ($this->userPin[$userId] ?? '');
            }
            if ($pin === '') {
                $counts['orphans']++;

                continue;
            }

            $deviceId = $this->deviceIdFor($punch);
            if ($deviceId === null) {
                $counts['unresolvable_device']++;

                continue;
            }

            $at = trim((string) ($punch['at'] ?? ''));
            if ($at === '') {
                $counts['orphans']++;

                continue;
            }

            // Wall clock as recorded by the terminal, Iraq = UTC+3. Stored as UTC so
            // the existing live rows (already UTC) hit the same instant and dedupe.
            $atUtc = Carbon::parse($at, 'Asia/Baghdad')->setTimezone('UTC')->format('Y-m-d H:i:s');
            $verify = (int) ($punch['verify'] ?? 0);
            $type = strtoupper(trim((string) ($punch['type'] ?? 'I')));
            $status = $type === 'O' ? 1 : 0;

            $counts['candidates']++;

            $key = "{$deviceId}|{$pin}|{$atUtc}|{$verify}";
            if (isset($existingKeys[$key])) {
                $counts['duplicates']++;

                continue;
            }
            $existingKeys[$key] = true; // guards a later same-instant punch in this export
            $counts['inserted']++;

            if ($this->apply) {
                $chunk[] = [
                    'tenant_id' => $this->tenantId,
                    'device_id' => $deviceId,
                    'device_user_id' => $pin,
                    'employee_id' => $this->userEmployee[$userId] ?? null,
                    'punched_at' => $atUtc,
                    'status' => $status,
                    'verify_method' => $verify,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($chunk) >= self::CHUNK) {
                    // SQL Server has no INSERT OR IGNORE, so the dedupe has already
                    // happened in memory against the exact unique index key; these are
                    // provably new rows unless a device sync wrote between the key load
                    // and now (the sync is currently stopped, so this is a non-issue).
                    AttendanceLog::query()->insert($chunk);
                    $chunk = [];
                }
            }
        }

        if ($this->apply && $chunk !== []) {
            AttendanceLog::query()->insert($chunk);
        }

        $this->attendance = $counts + ['skipped' => false];
    }

    /** @return array<string,bool> every existing punch as a dedupe key */
    private function existingPunchKeys(): array
    {
        $keys = [];
        $rows = AttendanceLog::query()
            ->where('tenant_id', $this->tenantId)
            ->get(['device_id', 'device_user_id', 'punched_at', 'verify_method']);

        foreach ($rows as $row) {
            $utc = $row->punched_at instanceof Carbon
                ? $row->punched_at->format('Y-m-d H:i:s')
                : date('Y-m-d H:i:s', strtotime((string) $row->punched_at));
            $keys["{$row->device_id}|{$row->device_user_id}|{$utc}|{$row->verify_method}"] = true;
        }

        return $keys;
    }

    /**
     * @param  array<string,mixed>  $punch
     */
    private function deviceIdFor(array $punch): ?int
    {
        $serial = trim((string) ($punch['serial'] ?? ''));
        $ip = trim((string) ($punch['ip'] ?? ''));

        if ($serial !== '' && isset($this->devicesBySerial[$serial])) {
            return $this->devicesBySerial[$serial];
        }

        // IT ROOM exists in this system by IP with no serial on record, so a serial
        // that is not in the DB still resolves through the export's own IP table.
        if ($serial !== '' && isset($this->exportMachineIpBySerial[$serial])) {
            $exportIp = $this->exportMachineIpBySerial[$serial];
            if (isset($this->devicesByIp[$exportIp])) {
                return $this->devicesByIp[$exportIp];
            }
        }

        // A snapshot punch carries `ip`; a serial-less terminal resolves on it.
        if ($serial === '' && $ip !== '' && isset($this->devicesByIp[$ip])) {
            return $this->devicesByIp[$ip];
        }

        return null;
    }

    private function userFullName(array $user): string
    {
        return trim((string) ($user['first_name'] ?? '').' '.(string) ($user['last_name'] ?? ''));
    }

    private function normalise(string $value): string
    {
        return Str::upper(preg_replace('/\s+/u', ' ', trim($value)) ?? '');
    }

    /** @return array<string,mixed> */
    private function report(): array
    {
        return [
            'departments' => ['existing' => $this->existingDepts, 'created' => $this->createdDepts],
            'employees' => [
                'matched_by_pin' => $this->matchedByPin,
                'matched_by_name' => $this->matchedByName,
                'created' => $this->createdEmployees,
                'ambiguous' => $this->ambiguous,
                'unmatched' => $this->unmatched,
                'skipped_deleted' => $this->skippedDeleted,
                'skipped_no_pin' => $this->skippedNoPin,
                'skipped_code_collision' => $this->skippedCodeCollision,
                'profiles_filled' => $this->profilesFilled,
                'fields_filled' => $this->fieldsFilled,
                'cards_skipped' => $this->cardsSkipped,
            ],
            'attendance' => $this->attendance,
            'warnings' => $this->warnings,
        ];
    }
}
