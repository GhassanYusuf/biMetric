<?php

/*
 * Does C3\Service\Personnel — the add / edit / card / fingerprint push / delete the
 * app now offers on the C3 door panels — do exactly that on a real panel, and touch
 * nobody else?
 *
 * BLAST RADIUS: WRITES TO THE DEVICE. On ONE panel per run (Glass Door, inBIO460,
 * 192.168.7.61, or Entrance, TF1700, 192.168.7.60), for ONE test user created for
 * the purpose, Pin 99999993:
 *   Personnel::add            user `zk-test` (card 0) + the panel's most common door
 *                             row (time zone 1 / door 1 on both panels, 2026-09-24)
 *   Personnel::update         rename to `zk-test-edit` — SET_DATA on an existing Pin
 *                             WITHOUT the UID, as ZKAccess edits (the step no panel
 *                             has run yet: is it an update, or a second row?)
 *   Personnel::update         card 3999999991, a number the probe first proves no one
 *                             on the panel holds; then card 0 again
 *   Personnel::addFingerprints one PLACEHOLDER template (finger 5): random bytes
 *                             behind the `SS21` header — it matches no finger; then
 *                             the same call again, which must write nothing
 *   Personnel::delete         removes all of it
 * With no real card and no real finger, the test user opens nothing at any point.
 *
 * HOW TO UNDO: the script deletes Pin 99999993 from all three tables itself and
 * checks every other row is byte-identical to before. If a run stops half-way,
 * re-run with --cleanup-only, or delete Pin 99999993 (zk-test) in ZKAccess. All
 * three tables are saved ENCRYPTED to storage/app/private/c3-snapshots/ first.
 *
 * Needs the owner's go-ahead (rule 16) — pass --owner-approved only once given.
 *
 * RESEARCH: ZKAccess 3.5.5 edits by the same SetDeviceData("User") as its add, no
 * delete first, UID not sent (draft/analysis/05, 2026-09-24). Builds on
 * traces-2026-09-24/c3-user-write-01 and c3-user-move-01..03.
 *
 * CONTROL: every other row of `user`, `userauthorize` and `templatev10`, digested
 * before and after each step. One model per run; a TF1700 result says nothing about
 * an inBIO460 (rule 15).
 *
 * PII: the trace keeps counts, digests of other rows, and the test rows only.
 * Template bytes appear only as sha1.
 *
 * Usage: php tools/zkprobe/c3-personnel.php <host> --owner-approved [--cleanup-only]
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Promoseven\Biometric\C3\Client\Panel;
use Promoseven\Biometric\C3\Service\Personnel;

Tenancy::spanAllTenants();

$opts = [];
$args = [];
foreach (array_slice($argv, 1) as $a) {
    preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m) ? $opts[$m[1]] = $m[2] ?? true : $args[] = $a;
}
$host = $args[0] ?? null;
const PIN = 99999993;
const TEST_CARD = 3999999991;

if ($host === null) {
    fwrite(STDERR, "Usage: php tools/zkprobe/c3-personnel.php <host> --owner-approved [--cleanup-only]\n");
    exit(2);
}
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
$people = new Personnel($panel);

$tables = ['user', 'userauthorize', 'templatev10'];
$trace = ['label' => 'c3-personnel', 'host' => $host, 'started_at' => now()->toIso8601String(),
    'device' => ['id' => $device->id, 'name' => $device->name, 'model' => $device->model],
    'pin' => PIN, 'steps' => []];

$read = fn () => array_combine($tables, array_map(fn ($t) => array_map(
    fn ($r) => isset($r['Template']) ? ['Template' => sha1((string) $r['Template'])] + $r : $r,
    $panel->readTable($t),
), $tables));
$test = fn (array $r) => (int) ($r['Pin'] ?? -1) === PIN;
$others = function (array $state) use ($test) {
    $out = [];
    foreach ($state as $t => $rows) {
        $keep = array_values(array_filter($rows, fn ($r) => ! $test($r)));
        usort($keep, fn ($a, $b) => [$a['Pin'] ?? 0, $a['UID'] ?? 0, $a['FingerID'] ?? 0] <=> [$b['Pin'] ?? 0, $b['UID'] ?? 0, $b['FingerID'] ?? 0]);
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
$cleanup = function () use ($panel) {
    foreach (['templatev10', 'userauthorize', 'user'] as $t) {
        try {
            $panel->deleteRows($t, 'Pin', PIN);
        } catch (Throwable) {
        }
    }
};
$step = function (string $name, array $extra = []) use (&$trace) {
    $trace['steps'][] = ['step' => $name] + $extra;
    fwrite(STDERR, json_encode(end($trace['steps']), JSON_UNESCAPED_SLASHES) . "\n");
};
$baseline = null;
$check = function (string $name, callable $action) use ($step, $read, $mine, $others, &$baseline) {
    try {
        $result = $action();
        $ok = true;
        $error = null;
    } catch (Throwable $e) {
        $result = null;
        $ok = false;
        $error = get_class($e) . ': ' . $e->getMessage();
    }
    $state = $read();
    $step($name, array_filter([
        'accepted' => $ok,
        'error' => $error,
        'result' => is_array($result) ? array_map(fn ($v) => is_string($v) && strlen($v) > 64 ? sha1($v) : $v, $result) : $result,
        'test_rows' => $mine($state),
        'others_unchanged' => $others($state) === $baseline,
    ], fn ($v) => $v !== null));

    return $ok;
};

$ranSteps = false;
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
        $step('refused', ['reason' => 'the test Pin is already present; run --cleanup-only first']);
    } elseif (array_filter($before['user'], fn ($r) => (int) ($r['CardNo'] ?? 0) === TEST_CARD)) {
        $step('refused', ['reason' => 'the test card number is held by someone on this panel']);
    } else {
        $ranSteps = true;
        $snapshot = sprintf('c3-snapshots/device-%d-personnel-%s.json.enc', $device->id, now()->format('Ymd-His'));
        Storage::disk('local')->put($snapshot, Crypt::encryptString(json_encode($before)));
        $trace['snapshot'] = "storage/app/private/{$snapshot}";

        $check('add', fn () => $people->add(PIN, 'zk-test'))
            && $check('edit-name', fn () => $people->update(PIN, 'zk-test-edit'))
            && $check('card-set', fn () => $people->update(PIN, null, TEST_CARD))
            && $check('card-clear', fn () => $people->update(PIN, null, 0))
            && $check('fingerprint-add', function () use ($people, &$trace) {
                $placeholder = "\x00\x00SS21" . random_bytes(700);
                $trace['placeholder_sha1'] = sha1($placeholder);
                $trace['placeholder'] = $placeholder; // removed before the trace is written

                return $people->addFingerprints(PIN, [['fid' => 5, 'template' => $placeholder]]);
            })
            && $check('fingerprint-add-again-writes-nothing', fn () => $people->addFingerprints(PIN, [['fid' => 5, 'template' => $trace['placeholder']]]))
            && $check('delete', fn () => $people->delete(PIN));
        unset($trace['placeholder']);
    }
} catch (Throwable $e) {
    $trace['error'] = get_class($e) . ': ' . $e->getMessage();
    fwrite(STDERR, "ERROR: {$trace['error']}\n");
} finally {
    unset($trace['placeholder']);
    if ($ranSteps) {
        try {
            $cleanup();
            $final = $read();
            $step('cleanup', [
                'test_rows' => $mine($final),
                'others_unchanged' => $others($final) === $baseline,
                'tables_restored' => array_map('count', $final) === array_map('count', $before),
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
while (file_exists($file = sprintf('%s/c3-personnel-%02d.json', $dir, $n))) {
    $n++;
}
file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
fwrite(STDOUT, "\nTrace: {$file}\n");
