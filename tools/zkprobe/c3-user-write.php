<?php

/*
 * Does a C3 panel accept a user written by SET_DATA (0x07) and removed by
 * DELETE_DATA (0x09), with field ids taken from the panel's own catalogue?
 *
 * BLAST RADIUS: WRITES TO THE DEVICE. On ONE panel (default Entrance, TF1700,
 * 192.168.7.60), for ONE test user created for the purpose, it sends:
 *   DATATABLE_CFG (0x06), GET_DATA (0x08) on `user`   reads, before and after each step
 *   SET_DATA (0x07) on `user`                         adds the test user:
 *       Pin 99999991 (override --pin), CardNo 0, Name "zk-test", Password "",
 *       Group 0, StartTime 0, EndTime 0, Privilege 0, SuperAuthorize 0 — no UID sent
 *   DELETE_DATA (0x09) on `user`, key Pin             removes it again
 * Nothing touches `userauthorize` or `templatev10`: the test user can open no door
 * and has no fingerprint. No other person's row is written.
 *
 * HOW TO UNDO: the script deletes the test user itself and verifies the table is back
 * to exactly its earlier contents. If a run stops half-way, the test user (Pin
 * 99999991, Name zk-test) is left on the panel: delete it in ZKAccess, or re-run with
 * --delete-only. The panel's full `user` table is saved ENCRYPTED to
 * storage/app/private/c3-snapshots/ before the first write.
 *
 * Owner's go-ahead: 2026-09-24 ("Yes, test on Entrance"). Refuses without
 * --owner-approved, refuses if the test Pin already exists, refuses unless the host is
 * a C3 panel known to the app.
 *
 * ---------------------------------------------------------------------------------
 * RESEARCH (rule 17) — docs/device-capabilities.md, "C3 panels: writing users"
 *   Command numbers 0x07 / 0x09: plcommpro.dll (src/C3/Protocol/Command.php) and
 *   xub/zkaccess-c3-py branch main @ a6b7b92c (zkaccess_c3/core.py set_user,
 *   delete_user), reported validated live there.
 *   DISAGREEMENT: that fork hard-codes user field ids (CardNo 1, Pin 2...); the
 *   TF1700 publishes UID 1, CardNo 2, Pin 3... So the payload here is built from the
 *   panel's catalogue (RecordCodec::encode), byte-checked against the fork in
 *   tests/Unit/C3RecordWriteTest.php.
 * QUESTIONS THIS RUN ANSWERS: is SET_DATA accepted session-less (reads are)? Is the
 *   row stored as sent (Pin, Name)? Does the panel assign a UID? Does DELETE by Pin
 *   remove exactly that row? Is every other row untouched?
 *
 * CONTROL: the other people on the same panel — their rows are hashed before and
 * after and must be identical. A finding here says nothing about the inBIO460
 * (rule 15).
 *
 * PII: the trace keeps counts, a digest of the other rows, and the TEST row only.
 *
 * Usage: php tools/zkprobe/c3-user-write.php [host] --owner-approved [--pin=99999991] [--delete-only]
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Promoseven\Biometric\C3\Client\Panel;

Tenancy::spanAllTenants();

$opts = [];
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        $args[] = $a;
    }
}
$host = $args[0] ?? '192.168.7.60';
$pin = (int) ($opts['pin'] ?? 99999991);

if (! isset($opts['owner-approved'])) {
    fwrite(STDERR, "Refusing: this WRITES TO THE DEVICE. Re-run with --owner-approved once the owner has said so (rule 16).\n");
    exit(2);
}

$dir = __DIR__ . '/traces-' . gmdate('Y-m-d');
if (! is_dir($dir) && ! @mkdir($dir, 0775, true) || ! is_writable($dir)) {
    fwrite(STDERR, "Refusing: cannot write traces to {$dir}. Create it for this user first.\n");
    exit(2);
}

$device = Device::query()->where('ip_address', $host)->where('protocol', 'c3')->firstOrFail();
$driver = app(App\Services\Devices\C3\C3Driver::class);
$factory = (new ReflectionClass($driver))->getMethod('panelFor');
$factory->setAccessible(true);
/** @var Panel $panel */
$panel = $factory->invoke($driver, $device);

$trace = [
    'label' => 'c3-user-write',
    'host' => $host,
    'started_at' => now()->toIso8601String(),
    'device' => ['id' => $device->id, 'name' => $device->name, 'model' => $device->model],
    'test_pin' => $pin,
    'steps' => [],
];

$usersNow = fn () => $panel->readTable('user');
$others = function (array $rows) use ($pin) {
    $keep = array_values(array_filter($rows, fn ($r) => (int) ($r['Pin'] ?? -1) !== $pin));
    usort($keep, fn ($a, $b) => [$a['Pin'] ?? 0, $a['UID'] ?? 0] <=> [$b['Pin'] ?? 0, $b['UID'] ?? 0]);

    return ['count' => count($keep), 'sha1' => sha1(json_encode($keep))];
};
$mine = fn (array $rows) => array_values(array_filter($rows, fn ($r) => (int) ($r['Pin'] ?? -1) === $pin));

$save = function () use (&$trace, $dir) {
    $trace['finished_at'] = now()->toIso8601String();
    $n = 1;
    while (file_exists($file = sprintf('%s/c3-user-write-%02d.json', $dir, $n))) {
        $n++;
    }
    file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    fwrite(STDOUT, "\nTrace: {$file}\n");
};

try {
    $panel->open();
    $catalogue = $panel->catalogue();
    $trace['user_schema'] = $catalogue->fields('user');

    $before = $usersNow();
    $trace['steps'][] = ['step' => 'read-before', 'rows' => count($before), 'others' => $others($before), 'test_rows' => $mine($before)];

    if (isset($opts['delete-only'])) {
        $panel->deleteRows('user', 'Pin', $pin);
        $after = $usersNow();
        $trace['steps'][] = ['step' => 'delete-only', 'rows' => count($after), 'test_rows' => $mine($after), 'others_unchanged' => $others($after) === $others($before)];
        $save();
        exit(0);
    }

    if ($mine($before) !== []) {
        fwrite(STDERR, "Refusing: Pin {$pin} already exists on {$device->name}.\n");
        $trace['refused'] = 'test pin already present';
        $save();
        exit(2);
    }

    // Rule 16: the current state, saved before the first write. Encrypted — it is a roster.
    $snapshot = sprintf('c3-snapshots/device-%d-user-%s.json.enc', $device->id, now()->format('Ymd-His'));
    Storage::disk('local')->put($snapshot, Crypt::encryptString(json_encode($before)));
    $trace['snapshot'] = "storage/app/private/{$snapshot}";

    // 1. Add.
    $row = ['CardNo' => 0, 'Pin' => $pin, 'Password' => '', 'Group' => 0, 'StartTime' => 0, 'EndTime' => 0, 'Name' => 'zk-test', 'Privilege' => 0, 'SuperAuthorize' => 0];
    $row = array_intersect_key($row, $catalogue->fields('user'));
    try {
        $panel->writeRows('user', [$row]);
        $trace['steps'][] = ['step' => 'set-data', 'sent' => $row, 'accepted' => true];
    } catch (Throwable $e) {
        $trace['steps'][] = ['step' => 'set-data', 'sent' => $row, 'accepted' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
    }

    $afterAdd = $usersNow();
    $trace['steps'][] = [
        'step' => 'read-after-add',
        'rows' => count($afterAdd),
        'test_rows' => $mine($afterAdd),
        'others_unchanged' => $others($afterAdd) === $others($before),
    ];

    // 2. Delete — attempted whenever the row is there, whatever happened above.
    if ($mine($afterAdd) !== []) {
        try {
            $panel->deleteRows('user', 'Pin', $pin);
            $trace['steps'][] = ['step' => 'delete-data', 'accepted' => true];
        } catch (Throwable $e) {
            $trace['steps'][] = ['step' => 'delete-data', 'accepted' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
        }

        $afterDelete = $usersNow();
        $trace['steps'][] = [
            'step' => 'read-after-delete',
            'rows' => count($afterDelete),
            'test_rows' => $mine($afterDelete),
            'others_unchanged' => $others($afterDelete) === $others($before),
            'table_restored' => count($afterDelete) === count($before) && $mine($afterDelete) === [],
        ];
    }
} catch (Throwable $e) {
    $trace['error'] = get_class($e) . ': ' . $e->getMessage();
} finally {
    try {
        $panel->close();
    } catch (Throwable) {
    }
}

foreach ($trace['steps'] as $s) {
    fwrite(STDERR, json_encode($s, JSON_UNESCAPED_SLASHES) . "\n");
}
if (isset($trace['error'])) {
    fwrite(STDERR, "ERROR: {$trace['error']}\n");
}
$save();
