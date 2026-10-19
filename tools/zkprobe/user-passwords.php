<?php

/*
 * How many people on each terminal have a keypad password, and what shape are they?
 *
 * BLAST RADIUS: READ-ONLY. Sends exactly one read per terminal — the user table:
 * DeviceClient::readUsersRaw() (CMD_DB_RRQ / FCT_USER) on the ZKTeco standalone
 * terminals, Panel::readTable('user') (GETDATA) on the C3 panels. Both are the reads
 * the app performs on every roster sync. Nothing is written, nothing is created or
 * deleted, no capture mode is entered. Safe while people are clocking in. Each read
 * takes the terminal's queue lock (DeviceDriverManager, 2026-10-12), so it waits for
 * any operator or job already talking to that terminal.
 *
 * ---------------------------------------------------------------------------------
 * THE QUESTION (owner, 2026-10-12)
 * ---------------------------------------------------------------------------------
 * The owner asked for keypad passwords to be pushed to the terminals and read back
 * with every roster read. Before building it: are passwords in use at all, on which
 * terminals, and are they digits only and how long? Also the risk this answers: the
 * library's UserService::save() rebuilds the ZKTeco 72-byte record with the password
 * bytes zeroed (UserCodec::buildUserRecord), so every name push and card write on a
 * standalone terminal may have been erasing them. C3 Personnel::update() keeps the
 * row's own Password; Personnel::add() writes ''.
 *
 * WHAT RESEARCH SETTLED FIRST (rule 17): password(8)@3 in the 72-byte record —
 * adrobinoga/zk-protocol "Set User Info", pyzk struct '<HB8s24sIx7sx24s'. On C3 the
 * user table's field 4, `Password`, a string (vwout/zkaccess-c3-py; this panel's own
 * DATATABLE_CFG, docs/device-capabilities.md). On 2026-09-21 the F18 IT ROOM had a
 * password of at most 4 characters (traces-2026-09-21/f18-user-record-fields-01.json).
 *
 * CONTROL (rule 16): IT ROOM, F18 at 192.168.7.62, is always read.
 *
 * DECODE CHECK: each terminal password is compared, in memory, with the system's
 * stored copy for the same person (employees.device_password via the live mapping).
 * Only the verdict counts are kept.
 *
 * WHAT REACHES THE TRACE: counts, lengths and character classes only. No password,
 * name, card or user id is written to the trace or printed (rule 16 — a keypad
 * password is a door credential).
 *
 * Usage:  php tools/zkprobe/user-passwords.php [device-id ...]   (default: every active terminal)
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Services\Devices\C3\C3Driver;
use App\Services\Devices\DeviceDriverManager;
use App\Support\Tenancy;
use Promoseven\Biometric\Client\Device as ProtocolDevice;

Tenancy::spanAllTenants();

const CONTROL_IP = '192.168.7.62';

$ids = array_slice($argv, 1);
$devices = Device::query()
    ->where('is_active', true)
    ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
    ->orderBy('id')
    ->get();
if (! $devices->contains(fn (Device $d) => $d->ip_address === CONTROL_IP)) {
    $devices->push(Device::query()->where('ip_address', CONTROL_IP)->firstOrFail());
}

$manager = app(DeviceDriverManager::class);
$c3 = app(C3Driver::class);
$panelFor = (new ReflectionClass($c3))->getMethod('panelFor');
$panelFor->setAccessible(true);
/** Runs $work holding the terminal's queue lock, like every app call. */
$exclusive = fn (Device $d, Closure $work) => (fn () => $this->exclusive($d, $work))->call($manager);

/** @param list<string> $passwords */
function shape(array $passwords): array
{
    $set = array_values(array_filter($passwords, fn ($p) => $p !== ''));
    $lengths = array_count_values(array_map('strlen', $set));
    ksort($lengths);

    return [
        'users' => count($passwords),
        'with_password' => count($set),
        'lengths' => $lengths,
        'digits_only' => count(array_filter($set, fn ($p) => ctype_digit($p))),
        'other_chars' => count(array_filter($set, fn ($p) => ! ctype_digit($p))),
    ];
}

/*
 * The decode check: for each person the system knows on this terminal (a live
 * mapping), does the terminal's password equal the system's stored copy? Only the
 * verdicts are kept — equal, differs, only on the terminal, only in the system.
 */
function compareWithSystem(Device $device, array $pins, array $passwords): array
{
    $byPin = array_combine($pins, $passwords);
    $out = ['equal' => 0, 'differs' => 0, 'terminal_only' => 0, 'system_only' => 0, 'unmapped_with_password' => 0];
    $maps = App\Models\EmployeeDeviceMap::withoutGlobalScopes()->where('device_id', $device->id)->whereNull('valid_to')
        ->with('employee:id,device_password')->get();
    $mapped = [];
    foreach ($maps as $m) {
        $pin = (string) $m->device_user_id;
        $mapped[$pin] = true;
        if (! array_key_exists($pin, $byPin) || ! $m->employee) {
            continue;
        }
        $terminal = $byPin[$pin];
        $system = (string) ($m->employee->device_password ?? '');
        if ($terminal === '' && $system === '') {
            continue;
        }
        $key = $terminal === '' ? 'system_only' : ($system === '' ? 'terminal_only' : ($terminal === $system ? 'equal' : 'differs'));
        $out[$key]++;
    }
    foreach ($byPin as $pin => $pw) {
        if ($pw !== '' && ! isset($mapped[(string) $pin])) {
            $out['unmapped_with_password']++;
        }
    }

    return $out;
}

$dir = __DIR__.'/traces-'.date('Y-m-d');
@mkdir($dir, 0775, true);
$trace = [
    'label' => 'user-passwords',
    'host' => $devices->pluck('ip_address')->implode(','),
    'started_at' => now()->toIso8601String(),
    'question' => 'Which terminals hold keypad passwords, how many, how long, digits only?',
    'devices' => [],
];

foreach ($devices as $device) {
    $entry = ['device_id' => $device->id, 'name' => $device->name, 'host' => $device->ip_address,
        'model' => $device->model, 'protocol' => $device->protocol,
        'is_control' => $device->ip_address === CONTROL_IP, 'read_at' => now()->toIso8601String()];
    fwrite(STDERR, "=== {$device->name} ({$device->ip_address}, {$device->model}) … ");

    try {
        if ($device->protocol === 'c3') {
            $rows = $exclusive($device, function () use ($panelFor, $c3, $device) {
                $panel = $panelFor->invoke($c3, $device);
                try {
                    $panel->open();

                    return $panel->readTable('user');
                } finally {
                    $panel->close();
                }
            });
            $entry['field'] = 'user.Password (C3 table)';
            $passwords = array_map(fn ($r) => (string) ($r['Password'] ?? ''), $rows);
            $pins = array_map(fn ($r) => (string) ($r['Pin'] ?? ''), $rows);
        } else {
            $raw = $manager->run($device, fn (ProtocolDevice $d) => $d->client()->readUsersRaw());
            $body = substr($raw, 4);
            $entry['field'] = 'password(8)@3 of the 72-byte record';
            $entry['raw_bytes'] = strlen($raw);
            $passwords = [];
            $pins = [];
            for ($o = 0; $o + 72 <= strlen($body); $o += 72) {
                $field = substr($body, $o + 3, 8);
                $end = strpos($field, "\0");
                $passwords[] = $end === false ? $field : substr($field, 0, $end);
                $id = substr($body, $o + 48, 9);
                $end = strpos($id, "\0");
                $pins[] = $end === false ? $id : substr($id, 0, $end);
            }
        }
        $entry += shape($passwords);
        $entry['against_system'] = compareWithSystem($device, $pins, $passwords);
        fwrite(STDERR, "{$entry['with_password']} of {$entry['users']} have one; vs system ".json_encode($entry['against_system'])."\n");
    } catch (Throwable $e) {
        // A failed read is `unknown`, never "none" (rule 9).
        $entry['error'] = get_class($e).': '.$e->getMessage();
        fwrite(STDERR, "FAILED: {$entry['error']}\n");
    }

    $trace['devices'][] = $entry;
}

$n = 1;
while (file_exists($path = sprintf('%s/user-passwords-%02d.json', $dir, $n))) {
    $n++;
}
file_put_contents($path, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
fwrite(STDERR, "trace: {$path}\n");
