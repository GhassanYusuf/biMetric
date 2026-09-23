<?php

/*
 * Where can a 9-character national id (CPR) live in a ZKTeco user record?
 *
 * BLAST RADIUS: READ-ONLY. Sends exactly one read — the user table, via
 * DeviceClient::readUsersRaw() (CMD_DB_RRQ / FCT_USER), which is the same read the
 * app performs on every enrollment sync. Nothing is written, no record is created
 * or deleted, no capture mode is entered. Safe while people are clocking in.
 *
 * ---------------------------------------------------------------------------------
 * THE QUESTION (owner, 2026-09-21)
 * ---------------------------------------------------------------------------------
 * The national id is the HRMS join key. Could the terminal itself carry it, so a
 * device read alone identifies the human — no employee_code mapping in between?
 *
 * That is only answerable by looking at what a real record actually contains:
 * which fields exist, how wide each is, and which bytes are genuinely unused as
 * opposed to "we never looked".
 *
 * ---------------------------------------------------------------------------------
 * WHAT RESEARCH SETTLED FIRST (rule 17)
 * ---------------------------------------------------------------------------------
 * The 72-byte user record is documented in two independent reimplementations:
 *
 *   https://github.com/adrobinoga/zk-protocol   — standalone command set / user table
 *   https://github.com/fananimi/pyzk            — struct '<HB8s24sIx7sx24s', set_user()
 *
 * Decomposed, pyzk's struct is:
 *   uid(2)@0  privilege(1)@2  password(8)@3  name(24)@11  card(4)@35
 *   pad(1)@39  group(7)@40  pad(1)@47  user_id(24)@48
 *
 * NOTE THE DISAGREEMENT WITH OUR OWN CODEC. UserCodec reads user_id as NINE bytes at
 * 48 and calls 57..71 "padding". Per the references those fifteen bytes are the rest
 * of ONE 24-byte user_id field, not spare room. Which of the two the FIRMWARE
 * believes is exactly what this probe is here to settle, because the answer decides
 * whether there is anywhere to put a CPR at all.
 *
 * ---------------------------------------------------------------------------------
 * CONTROL (rule 16)
 * ---------------------------------------------------------------------------------
 * The F18 at 192.168.7.62 is both subject and reference here — the question is about
 * the model that works. Any other device id passed on the command line is read too,
 * and every finding is recorded per device: an offset confirmed on the F18 says
 * NOTHING about the F22 until an F22 has been read (rule 15).
 *
 * ---------------------------------------------------------------------------------
 * WHAT IS AND IS NOT WRITTEN TO THE TRACE
 * ---------------------------------------------------------------------------------
 * A user table is employee PII — names, card numbers, ids. The trace therefore keeps
 * STRUCTURE ONLY: per-offset occupancy, field widths, value lengths, character
 * classes. No name, no card number, no user id, and no record hex ever reaches the
 * file (rule 16: commit the decoded structure, never the payload). The console shows
 * one fully decoded record so a human can see what is really there; that output is
 * not committed.
 *
 * Usage:  php tools/zkprobe/f18-user-record-fields.php [device-id ...]
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Support\Tenancy;
use Promoseven\Biometric\Client\Device as ProtocolDevice;

Tenancy::spanAllTenants();

const CONTROL_IP = '192.168.7.62';
const RECORD_SIZE = 72;

/** The layout under test, per the references above. */
const FIELDS = [
    ['uid', 0, 2, 'int'],
    ['privilege', 2, 1, 'int'],
    ['password', 3, 8, 'text'],
    ['name', 11, 24, 'text'],
    ['card', 35, 4, 'int'],
    ['pad_39', 39, 1, 'bytes'],
    ['group', 40, 7, 'bytes'],
    ['pad_47', 47, 1, 'bytes'],
    ['user_id', 48, 24, 'text'],
];

$ids = array_slice($argv, 1);
$devices = Device::query()
    ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
    ->when($ids === [], fn ($q) => $q->where('ip_address', CONTROL_IP))
    ->where('protocol', Device::PROTOCOL_BINARY)
    ->where('is_active', true)
    ->orderBy('id')
    ->get();

// The control is not optional and is not a flag.
if (! $devices->contains(fn (Device $d) => $d->ip_address === CONTROL_IP)) {
    $control = Device::query()->where('ip_address', CONTROL_IP)->first();
    if ($control) {
        $devices->push($control);
    }
}

$driver = app(App\Contracts\BiometricDeviceDriver::class);
$trace = [
    'label' => 'f18-user-record-fields',
    'host' => $devices->pluck('ip_address')->implode(','),
    'started_at' => now()->toIso8601String(),
    'question' => 'Which bytes of the 72-byte user record are occupied, and is user_id 9 bytes or 24?',
    'devices' => [],
];

/** Printable ASCII run at the start of a fixed-width field, NUL-terminated. */
function cstring(string $field): string
{
    $end = strpos($field, "\0");

    return $end === false ? $field : substr($field, 0, $end);
}

foreach ($devices as $device) {
    fwrite(STDERR, "\n=== {$device->name} ({$device->ip_address}, {$device->model})\n");

    $entry = [
        'device_id' => $device->id,
        'name' => $device->name,
        'host' => $device->ip_address,
        'model' => $device->model,
        'is_control' => $device->ip_address === CONTROL_IP,
        'read_at' => now()->toIso8601String(),
    ];

    try {
        $raw = $driver->run($device, fn (ProtocolDevice $d) => $d->client()->readUsersRaw());

        // 4-byte length header, then fixed-width records (UserCodec's layout).
        $body = substr($raw, 4);
        $count = intdiv(strlen($body), RECORD_SIZE);

        $entry['raw_bytes'] = strlen($raw);
        $entry['record_size'] = RECORD_SIZE;
        $entry['records'] = $count;
        $entry['trailing_bytes'] = strlen($body) % RECORD_SIZE;

        // Occupancy: for each of the 72 offsets, how many records have a non-zero
        // byte there. An offset that is zero in EVERY record is the only kind of
        // candidate for carrying something new — and even then only until a firmware
        // that uses it turns up.
        $occupancy = array_fill(0, RECORD_SIZE, 0);
        $userIdTailUsed = 0;   // records with anything at 57..71
        $lengths = ['name' => [], 'user_id' => [], 'password' => []];
        $first = null;

        for ($i = 0; $i < $count; $i++) {
            $chunk = substr($body, $i * RECORD_SIZE, RECORD_SIZE);

            for ($b = 0; $b < RECORD_SIZE; $b++) {
                if ($chunk[$b] !== "\0") {
                    $occupancy[$b]++;
                }
            }

            if (rtrim(substr($chunk, 57, 15), "\0") !== '') {
                $userIdTailUsed++;
            }

            $decoded = [];
            foreach (FIELDS as [$name, $off, $len, $kind]) {
                $slice = substr($chunk, $off, $len);
                $decoded[$name] = match ($kind) {
                    'int' => $len === 2 ? unpack('v', $slice)[1] : ($len === 4 ? unpack('V', $slice)[1] : ord($slice)),
                    'text' => cstring($slice),
                    default => bin2hex($slice),
                };
            }

            $lengths['name'][] = strlen($decoded['name']);
            $lengths['user_id'][] = strlen($decoded['user_id']);
            $lengths['password'][] = strlen($decoded['password']);

            $first ??= $decoded;
        }

        // CONSOLE ONLY — one real record, so a human can see the fields populated.
        if ($first !== null) {
            fwrite(STDERR, "    one record, decoded (NOT written to the trace):\n");
            foreach ($first as $k => $v) {
                fwrite(STDERR, sprintf("      %-10s %s\n", $k, is_int($v) ? $v : "'{$v}'"));
            }
        }

        $entry['occupancy'] = $occupancy;
        $entry['always_zero_offsets'] = array_values(array_keys(array_filter(
            $occupancy,
            static fn (int $hits) => $hits === 0,
        )));
        $entry['records_using_user_id_tail_57_71'] = $userIdTailUsed;
        $entry['max_lengths'] = [
            'name' => $lengths['name'] === [] ? null : max($lengths['name']),
            'user_id' => $lengths['user_id'] === [] ? null : max($lengths['user_id']),
            'password' => $lengths['password'] === [] ? null : max($lengths['password']),
        ];

        fwrite(STDERR, sprintf(
            "    %d record(s), %d byte(s); %d record(s) use user_id past byte 57; always-zero offsets: %s\n",
            $count,
            strlen($raw),
            $userIdTailUsed,
            $entry['always_zero_offsets'] === [] ? 'none' : implode(',', $entry['always_zero_offsets']),
        ));
    } catch (\Throwable $e) {
        // A device that will not answer is evidence about nothing (rule 9).
        $entry['error'] = get_class($e) . ': ' . $e->getMessage();
        fwrite(STDERR, "    FAILED: {$e->getMessage()}\n");
    }

    $trace['devices'][] = $entry;
}

$dir = __DIR__ . '/traces-' . date('Y-m-d');
@mkdir($dir, 0775, true);
$n = 1;
while (file_exists($path = sprintf('%s/%s-%02d.json', $dir, $trace['label'], $n))) {
    $n++;
}
file_put_contents($path, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
fwrite(STDERR, "\ntrace: {$path}\n");
