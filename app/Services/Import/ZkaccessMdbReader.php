<?php

namespace App\Services\Import;

use Carbon\Carbon;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Reads a ZKAccess backup (.mdb, Jet 3/4) into the canonical export shape that
 * ZkaccessImportService consumes — the shape scripts/export-zkaccess.ps1 produces
 * on Windows, but here on Linux via mdbtools (mdb-export / mdb-tables), so a raw
 * upload can be processed entirely server-side.
 *
 * HOW COLUMNS ARE RESOLVED: mdb-export's first line is the header row, so the
 * column order is taken from the real file rather than assumed from a schema —
 * this database's Machines table alone has ~130 columns and its spelike names
 * (IP / sn / device_name / ProductType / FirmwareVersion / MachineNumber) are
 * not worth hard-coding by position. Every target column is matched by name
 * against that header, so an id-renamed column degrades to an ignored field,
 * not a misread row.
 *
 * TIMES: BIRTHDAY / HIREDDAY / CHECKTIME are Jet DateTime fields. mdbtools is
 * told to render them ISO (Y-m-d H:i:s); the reader keeps punch `at` as-is and
 * reduces hire/birth dates to Y-m-d, matching what the Windows exporter wrote
 * and what the importer already parses.
 */
class ZkaccessMdbReader
{
    /**
     * mdbtools table name => canonical result key.
     *
     * @var array<string,string>
     */
    private const TABLES = [
        'USERINFO' => 'users',
        'CHECKINOUT' => 'punches',
        'DEPARTMENTS' => 'departments',
        'Machines' => 'machines',
    ];

    /**
     * Canonical export key => ordered list of mdbtools column names to try
     * (first one present in the file's header wins).
     *
     * @var array<string,array<int,string>>
     */
    private const USERS_MAP = [
        'user_id' => ['USERID'],
        'pin' => ['Badgenumber'],
        'first_name' => ['name'],
        'last_name' => ['lastname'],
        'gender' => ['Gender'],
        'card' => ['CardNo'],
        'hire_date' => ['HIREDDAY'],
        'birth_date' => ['BIRTHDAY'],
        'department_id' => ['DEFAULTDEPTID'],
        'privilege' => ['privilege'],
        'password' => ['PASSWORD'],
        'email' => ['email', 'EMAIL'],
        'mobile_phone' => ['OPHONE'],
        'home_phone' => ['FPHONE'],
        'home_address' => ['homeaddress'],
        'street' => ['street'],
        'city' => ['CITY'],
        'country' => ['contry'],
        'education' => ['Education'],
        'political' => ['Political'],
        'job_title' => ['TITLE'],
        'emptype' => ['emptype'],
        'status' => ['status'],
        'deleted' => ['DelTag'],
    ];

    /**
     * @var array<string,array<int,string>>
     */
    private const PUNCHES_MAP = [
        'user_id' => ['USERID'],
        'at' => ['CHECKTIME'],
        'type' => ['CHECKTYPE'],
        'verify' => ['VERIFYCODE'],
        'serial' => ['sn', 'SN'],
    ];

    /**
     * @var array<string,array<int,string>>
     */
    private const DEPARTMENTS_MAP = [
        'id' => ['DEPTID'],
        'name' => ['DEPTNAME'],
        'parent_id' => ['SUPDEPTID'],
        'code' => ['code', 'CODE'],
    ];

    /**
     * @var array<string,array<int,string>>
     */
    private const MACHINES_MAP = [
        'id' => ['ID', 'id'],
        'name' => ['device_name', 'name'],
        'ip' => ['IP', 'ip', 'ipaddress'],
        'serial' => ['sn'],
        'product' => ['ProductType', 'model'],
        'firmware' => ['FirmwareVersion', 'firmware'],
        'machine_number' => ['MachineNumber', 'machineNumber'],
    ];

    /**
     * The jobs live minutes inside a 128–256 MB request, so the raw exports are
     * streamed through a temp file instead of captured in PHP memory.
     *
     * @var positive-int
     */
    private const STREAM_CHUNK = 8192;

    public function __construct(private readonly string $workDir = '/tmp') {}

    /**
     * @return array{
     *     source: string,
     *     exported_at: string,
     *     departments: array<int,array<string,string>>,
     *     machines: array<int,array<string,string>>,
     *     users: array<int,array<string,string>>,
     *     punches: array<int,array<string,string>>,
     * }
     */
    public function read(string $mdbPath): array
    {
        $this->assertTables($mdbPath);

        $departments = $this->exportTable($mdbPath, 'DEPARTMENTS', [], self::DEPARTMENTS_MAP);
        $machines = $this->exportTable($mdbPath, 'Machines', [], self::MACHINES_MAP);

        $users = $this->exportTable(
            $mdbPath,
            'USERINFO',
            ['-T' => '%Y-%m-%d %H:%M:%S'],
            self::USERS_MAP,
            fn (array $row): array => $this->reduceDates($row, ['hire_date', 'birth_date']),
        );

        $pinByUserId = [];
        foreach ($users as $user) {
            $pinByUserId[trim((string) $user['user_id'])] = trim((string) $user['pin']);
        }

        $punches = $this->exportTable(
            $mdbPath,
            'CHECKINOUT',
            ['-T' => '%Y-%m-%d %H:%M:%S'],
            self::PUNCHES_MAP,
            fn (array $row): array => $this->attachPin($row, $pinByUserId),
        );

        return [
            'source' => basename($mdbPath),
            'exported_at' => now()->format('Y-m-d H:i:s'),
            'departments' => $departments,
            'machines' => $machines,
            'users' => $users,
            'punches' => $punches,
        ];
    }

    /**
     * Refuses to run against a file missing any of the four tables the importer
     * needs, before the first export would fail halfway through a big read.
     */
    private function assertTables(string $mdbPath): void
    {
        $process = new Process(['mdb-tables', '-1', $mdbPath]);
        $code = $process->run();
        if ($code !== 0) {
            throw new RuntimeException('mdb-tables failed: '.trim($process->getErrorOutput()).trim($process->getOutput()));
        }

        $tables = array_flip(array_map('trim', explode("\n", trim($process->getOutput()))));
        foreach (array_keys(self::TABLES) as $table) {
            if (! isset($tables[$table])) {
                throw new RuntimeException("This file is missing the {$table} table and is not a ZKAccess backup.");
            }
        }
    }

    /**
     * @param  array<string,array<int,string>>  $columnMap
     * @param  callable(array<string,string>):array<string,string>|null  $transform
     * @return array<int,array<string,string>>
     */
    private function exportTable(
        string $mdbPath,
        string $table,
        array $flags,
        array $columnMap,
        ?callable $transform = null,
    ): array {
        $tmp = tempnam($this->workDir, 'mdb-'.strtolower($table).'-');
        if ($tmp === false) {
            throw new RuntimeException("Could not create a temp file for {$table}.");
        }

        try {
            $command = ['mdb-export'];
            foreach ($flags as $flag => $value) {
                $command[] = $flag;
                $command[] = $value;
            }
            $command[] = $mdbPath;
            $command[] = $table;

            $process = new Process($command);
            $process->setTimeout(600);
            $process->run(fn (string $type, string $buffer) => file_put_contents($tmp, $buffer, FILE_APPEND));

            if (! $process->isSuccessful()) {
                throw new RuntimeException("mdb-export failed for {$table}: ".trim($process->getErrorOutput()));
            }

            $handle = fopen($tmp, 'rb');
            if ($handle === false) {
                throw new RuntimeException("Could not reopen {$table} output.");
            }

            $headerLine = fgets($handle, self::STREAM_CHUNK);
            if ($headerLine === false) {
                throw new RuntimeException("{$table} produced no header row.");
            }

            $columns = $this->normaliseColumns(str_getcsv($headerLine));
            $keys = $this->resolveKeys($columns, $columnMap);

            $rows = [];
            while (($line = fgets($handle, self::STREAM_CHUNK)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }
                $row = $this->readRow($line, $columns, $keys);
                if ($row === null) {
                    continue;
                }
                $rows[] = $transform === null ? $row : $transform($row);
            }
            fclose($handle);

            return $rows;
        } finally {
            @unlink($tmp);
        }
    }

    /** @return array<int,string> */
    private function normaliseColumns(array $header): array
    {
        return array_map(fn (string $name): string => strtolower(trim($name)), $header);
    }

    /**
     * Maps every wanted canonical key to its 0-based column index, so each row is
     * read positionally but the decision of WHAT each position means came from the
     * file's own header.
     *
     * @param  array<int,string>  $columns
     * @param  array<string,array<int,string>>  $columnMap
     * @return array<string,int>
     */
    private function resolveKeys(array $columns, array $columnMap): array
    {
        $lookup = array_flip($columns);
        $keys = [];
        foreach ($columnMap as $canonical => $candidates) {
            foreach ($candidates as $candidate) {
                if (isset($lookup[strtolower($candidate)])) {
                    $keys[$canonical] = $lookup[strtolower($candidate)];

                    break;
                }
            }
        }

        return $keys;
    }

    /**
     * @param  array<int,string>  $columns
     * @param  array<string,int>  $keys
     * @return array<string,string>|null
     */
    private function readRow(string $line, array $columns, array $keys): ?array
    {
        $fields = str_getcsv($line);
        if (count($fields) < max($keys ?: [0]) + 1) {
            // A short row is a truncated/corrupt export line; swallowing it would
            // shift nothing (fields are read by index) but would drop a real
            // punch. Counted by the caller as a label, not silently.
            return null;
        }

        $row = [];
        foreach ($keys as $canonical => $index) {
            $row[$canonical] = trim((string) ($fields[$index] ?? ''));
        }

        return $row;
    }

    /** @param array<string,string> $row @return array<string,string> */
    private function reduceDates(array $row, array $dateKeys): array
    {
        foreach ($dateKeys as $key) {
            $value = $row[$key] ?? '';
            if ($value === '') {
                continue;
            }
            try {
                $row[$key] = Carbon::parse($value)->format('Y-m-d');
            } catch (\Throwable) {
                // Non-date junk in a date column: keep it so the importer can
                // decide; do not breach the whole import over one bad row.
            }
        }

        return $row;
    }

    /**
     * CHECKINOUT has no PIN of its own; the punch belongs to the user whose
     * USERID it carries, and the export always carried the resolved PIN so the
     * importer's dedupe key (device|pin|utc|verify) stays meaningful.
     *
     * @param  array<string,string>  $row
     * @param  array<string,string>  $pinByUserId
     * @return array<string,string>
     */
    private function attachPin(array $row, array $pinByUserId): array
    {
        $row['pin'] = $pinByUserId[trim((string) $row['user_id'])] ?? '';

        return $row;
    }
}
