<?php

/*
 * Can a C3 panel user be moved to a new Pin — user row, door permissions AND
 * fingerprint rows — with Panel::moveUser() (copy under the new Pin, verify, then
 * remove the old)?
 *
 * BLAST RADIUS: WRITES TO THE DEVICE. On ONE panel (default Entrance, TF1700,
 * 192.168.7.60), for ONE test user created for the purpose:
 *   SET_DATA `user`           adds zk-test, Pin 99999991, CardNo 0 (no card)
 *   SET_DATA `userauthorize`  adds one row for it: time zone 1, door 1 — the same
 *                             pair every person on this panel has (read 2026-09-24).
 *                             With no card and no real fingerprint it opens nothing.
 *   SET_DATA `templatev10`    one PLACEHOLDER fingerprint row for it (finger 5):
 *                             random bytes behind the `SS21` header real templates
 *                             carry — it matches no finger. Valid 1, Resverd 0,
 *                             EndTag 13 (the values all 238 real rows carry).
 *   Panel::moveUser(99999991 -> 99999992): SET_DATA all three tables under the new
 *                             Pin (fingerprint under the NEW UID), reads back, then
 *                             DELETE_DATA all three under the old Pin
 *   DELETE_DATA all three tables, both Pins   cleanup
 * No other person's row is written.
 *
 * HOW TO UNDO: the script removes the test user from both tables itself and checks
 * both tables are back to their earlier contents. If a run stops half-way, delete
 * Pins 99999991 / 99999992 (name zk-test) in ZKAccess, or re-run with --cleanup-only.
 * Both tables are saved ENCRYPTED to storage/app/private/c3-snapshots/ first.
 *
 * Owner's go-ahead: 2026-09-24 ("Yes, run the zk-test on Entrance").
 *
 * RESEARCH: docs/device-capabilities.md, "C3 panels: writing users". Builds on the
 * add/delete run traces-2026-09-24/c3-user-write-01.json. Field ids come from the
 * panel's catalogue (RecordCodec), never from the reference fork's constants.
 *
 * CONTROL: every other row of `user` and `userauthorize`, digested before and after.
 * TF1700 only; says nothing about the inBIO460 (rule 15).
 *
 * PII: the trace keeps counts, digests of other rows, and the test rows only.
 *
 * Usage: php tools/zkprobe/c3-user-move.php [host] --owner-approved [--cleanup-only]
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
    preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m) ? $opts[$m[1]] = $m[2] ?? true : $args[] = $a;
}
$host = $args[0] ?? '192.168.7.60';
const PIN_A = 99999991;
const PIN_B = 99999992;

if (! isset($opts['owner-approved'])) {
    fwrite(STDERR, "Refusing: this WRITES TO THE DEVICE. Re-run with --owner-approved once the owner has said so (rule 16).\n");
    exit(2);
}
$dir = __DIR__ . '/traces-' . gmdate('Y-m-d');
if (! is_dir($dir) && ! @mkdir($dir, 0775, true) || ! is_writable($dir)) {
    fwrite(STDERR, "Refusing: cannot write traces to {$dir}.\n");
    exit(2);
}

$device = Device::query()->where('ip_address', $host)->where('protocol', 'c3')->firstOrFail();
$driver = app(App\Services\Devices\C3\C3Driver::class);
$factory = (new ReflectionClass($driver))->getMethod('panelFor');
$factory->setAccessible(true);
/** @var Panel $panel */
$panel = $factory->invoke($driver, $device);

$tables = ['user', 'userauthorize', 'templatev10'];
$trace = ['label' => 'c3-user-move', 'host' => $host, 'started_at' => now()->toIso8601String(),
    'device' => ['id' => $device->id, 'name' => $device->name, 'model' => $device->model],
    'pins' => [PIN_A, PIN_B], 'steps' => []];

// Template bytes never reach a digest input, a trace or the terminal: only their sha1.
$read = fn () => array_combine($tables, array_map(fn ($t) => array_map(
    fn ($r) => isset($r['Template']) ? ['Template' => sha1((string) $r['Template'])] + $r : $r,
    $panel->readTable($t),
), $tables));
$test = fn (array $r) => in_array((int) ($r['Pin'] ?? -1), [PIN_A, PIN_B], true);
$others = function (array $state) use ($test) {
    $out = [];
    foreach ($state as $t => $rows) {
        $keep = array_values(array_filter($rows, fn ($r) => ! $test($r)));
        usort($keep, fn ($a, $b) => [$a['Pin'] ?? 0, $a['UID'] ?? 0] <=> [$b['Pin'] ?? 0, $b['UID'] ?? 0]);
        $out[$t] = ['count' => count($keep), 'sha1' => sha1(json_encode($keep))];
    }

    return $out;
};
$mine = function (array $state) use ($test) {
    $out = [];
    foreach ($state as $t => $rows) {
        $out[$t] = array_values(array_filter($rows, $test));
    }

    return $out;
};
$cleanup = function () use ($panel, $tables) {
    foreach (array_reverse($tables) as $t) {
        foreach ([PIN_A, PIN_B] as $pin) {
            try {
                $panel->deleteRows($t, 'Pin', $pin);
            } catch (Throwable) {
            }
        }
    }
};
$step = function (string $name, array $extra = []) use (&$trace) {
    $trace['steps'][] = ['step' => $name] + $extra;
    fwrite(STDERR, json_encode(end($trace['steps']), JSON_UNESCAPED_SLASHES) . "\n");
};

try {
    $panel->open();
    $before = $read();
    $baseline = $others($before);
    $step('read-before', ['others' => $baseline, 'test_rows' => $mine($before)]);

    if (isset($opts['cleanup-only'])) {
        $cleanup();
        $after = $read();
        $step('cleanup-only', ['test_rows' => $mine($after), 'others_unchanged' => $others($after) === $baseline]);
    } elseif (array_filter($mine($before))) {
        $step('refused', ['reason' => 'a test Pin is already present; run --cleanup-only first']);
    } else {
        $snapshot = sprintf('c3-snapshots/device-%d-user-userauthorize-%s.json.enc', $device->id, now()->format('Ymd-His'));
        Storage::disk('local')->put($snapshot, Crypt::encryptString(json_encode($before)));
        $trace['snapshot'] = "storage/app/private/{$snapshot}";

        // Set up the test user under Pin A.
        $user = array_intersect_key(
            ['CardNo' => 0, 'Pin' => PIN_A, 'Password' => '', 'Group' => 0, 'StartTime' => 0, 'EndTime' => 0, 'Name' => 'zk-test', 'Privilege' => 0, 'SuperAuthorize' => 0],
            $panel->catalogue()->fields('user'),
        );
        $panel->writeRows('user', [$user]);
        $panel->writeRows('userauthorize', [['Pin' => PIN_A, 'AuthorizeTimezoneId' => 1, 'AuthorizeDoorId' => 1]]);
        $uidA = (int) array_values(array_filter($panel->readTable('user'), fn ($r) => (int) $r['Pin'] === PIN_A))[0]['UID'];
        $placeholder = "\x00\x00SS21".random_bytes(700);
        $trace['placeholder_sha1'] = sha1($placeholder);
        $panel->writeRows('templatev10', [[
            'Size' => strlen($placeholder), 'UID' => $uidA, 'Pin' => PIN_A, 'FingerID' => 5,
            'Valid' => 1, 'Template' => $placeholder, 'Resverd' => 0, 'EndTag' => 13,
        ]], allowBlobs: true);
        $setup = $read();
        $step('setup', ['test_rows' => $mine($setup), 'others_unchanged' => $others($setup) === $baseline]);

        // The move under test.
        try {
            $moved = $panel->moveUser(PIN_A, PIN_B);
            $step('move', ['accepted' => true, 'moved' => $moved]);
        } catch (Throwable $e) {
            $step('move', ['accepted' => false, 'error' => get_class($e) . ': ' . $e->getMessage()]);
        }
        $afterMove = $read();
        $m = $mine($afterMove);
        $step('read-after-move', [
            'test_rows' => $m,
            'on_new_pin' => array_map(fn ($rows) => count(array_filter($rows, fn ($r) => (int) $r['Pin'] === PIN_B)), $m),
            'on_old_pin' => array_map(fn ($rows) => count(array_filter($rows, fn ($r) => (int) $r['Pin'] === PIN_A)), $m),
            'template_identical' => ($m['templatev10'][0]['Template'] ?? null) === ($trace['placeholder_sha1'] ?? false),
            'template_uid_is_new_user_uid' => isset($m['templatev10'][0], $m['user'][0]) && $m['templatev10'][0]['UID'] === $m['user'][0]['UID'],
            'others_unchanged' => $others($afterMove) === $baseline,
        ]);
    }
} catch (Throwable $e) {
    $trace['error'] = get_class($e) . ': ' . $e->getMessage();
    fwrite(STDERR, "ERROR: {$trace['error']}\n");
} finally {
    if (! isset($opts['cleanup-only']) && ! isset($trace['steps'][1]['reason'])) {
        try {
            $cleanup();
            $final = $read();
            $step('cleanup', [
                'test_rows' => $mine($final),
                'others_unchanged' => $others($final) === ($baseline ?? null),
                'tables_restored' => isset($before) && array_map('count', $final) === array_map('count', $before),
            ]);
        } catch (Throwable $e) {
            $step('cleanup', ['error' => $e->getMessage()]);
        }
    }
    try {
        $panel->close();
    } catch (Throwable) {
    }
}

$trace['finished_at'] = now()->toIso8601String();
$n = 1;
while (file_exists($file = sprintf('%s/c3-user-move-%02d.json', $dir, $n))) {
    $n++;
}
file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
fwrite(STDOUT, "\nTrace: {$file}\n");
