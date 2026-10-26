<?php

/*
 * Does a UA300 take the writes the app needs: add a user, rename, card, fingerprint,
 * delete, and the clock?
 *
 * BLAST RADIUS: WRITES TO THE DEVICE — ONE TEST USER ONLY, plus the clock. On the one
 * terminal named it:
 *   1. holds the queue's own per-device lock for the whole run, so no queued command
 *      (the 5-minute health check) opens a second session meanwhile (rule 11);
 *   2. reads every user, card and fingerprint and SAVES them to
 *      storage/app/private/removed-records/ before writing anything (rule 19);
 *   3. adds the system's Test User (employee #1827) under its own Personnel ID,
 *      refused if anyone holds that user ID on the terminal; renames it; writes its
 *      card; writes its one stored fingerprint — each read back;
 *   4. deletes the test user and verifies it gone, and that every other user, card and
 *      fingerprint is exactly as before (a digest of the whole state);
 *   5. sets the clock, then reads it back at 0, 5, 30, 60 and 120 s.
 * Any failure in 3 still runs the delete in 4. No worker's record is written.
 * TO UNDO by hand if a run is killed mid-way: delete user 981122445 on that terminal;
 * the saved roster file is printed and in the trace.
 *
 * Needs the owner's go-ahead for each run (rule 16). Given 2026-10-26 for Catering
 * (UA300/ID, 192.168.7.120), casebook DC-032.
 *
 * WHAT IT SETTLES (rule 21): user.write, user.delete, card.write, template.upload and
 * device.time.write on the UA300 — the first write evidence for that model. A finding
 * here says nothing about the F18 or F22 (rule 15).
 *
 * WHAT REACHES THE TRACE: steps, verdicts, the TEST user's id, counts, SHA-1 digests and
 * sizes. Never template bytes, never a worker's name or card (rule 16).
 *
 * --clock-only skips 2-4 and runs step 5 alone.
 *
 * THE CLOCK STEP gives the IN-MEMORY device row a capability override,
 * device.time.write => implemented, for this run only: the library refuses SET_TIME on
 * a model where it is UNKNOWN (run 01, 2026-10-26). Nothing is saved to the database.
 *
 * Usage:  php tools/zkprobe/ua300-write.php <device-id> [--clock-only]
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Contracts\BiometricDeviceDriver;
use App\Jobs\ProcessDeviceCommand;
use App\Models\BiometricTemplate;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Support\Tenancy;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;

const TEST_EMPLOYEE_ID = 1827;

$deviceId = (int) ($argv[1] ?? 0);
$clockOnly = in_array('--clock-only', $argv, true);
$device = Device::withoutGlobalScopes()->find($deviceId);
if ($device === null) {
    fwrite(STDERR, "Name the terminal: php tools/zkprobe/ua300-write.php <device-id>\n");
    exit(2);
}
Tenancy::set($device->tenant_id);

$driver = app(BiometricDeviceDriver::class);
$employee = Employee::findOrFail(TEST_EMPLOYEE_ID);
$pin = (string) $employee->employee_code;
$card = (int) $employee->card_number;
$stored = BiometricTemplate::where('employee_id', $employee->id)->orderBy('finger_slot')->first();

$dir = __DIR__.'/traces-'.date('Y-m-d');
@mkdir($dir, 0775, true);
$n = 1;
while (is_file(sprintf('%s/ua300-write-%02d.json', $dir, $n))) {
    $n++;
}
$traceFile = sprintf('%s/ua300-write-%02d.json', $dir, $n);
$trace = [
    'label' => 'ua300-write',
    'host' => $device->ip_address,
    'started_at' => now()->toIso8601String(),
    'device' => ['id' => $device->id, 'name' => $device->name, 'model' => $device->model, 'firmware' => $device->firmware_version, 'platform' => $device->platform],
    'question' => 'Does the UA300 add, rename, card, fingerprint and delete a test user with read-back, leave everyone else unchanged, and when does a clock write take effect?',
    'test_user_id' => $pin,
    'steps' => [],
];
$step = function (string $what, array $data = []) use (&$trace) {
    $trace['steps'][] = ['at' => now()->toIso8601String(), 'step' => $what] + $data;
    fwrite(STDERR, "  {$what} ".json_encode($data)."\n");
};
$save = function () use (&$trace, $traceFile) {
    $trace['finished_at'] = now()->toIso8601String();
    file_put_contents($traceFile, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
};

/** Everyone except the test user: ids, cards and finger digests, as one digest. */
$state = function () use ($driver, $device, $pin): array {
    $users = collect($driver->readUsers($device, false))->reject(fn ($u) => $u->userId === $pin);
    $fingers = collect($driver->readTemplateDigests($device))->reject(fn ($t) => (string) $t['user_id'] === $pin);
    $rows = $users->map(fn ($u) => $u->userId.'|'.$u->name.'|'.($u->cardNumber ?? '').'|'.$u->privilege)->sort()->values()->all();
    $prints = $fingers->map(fn ($t) => $t['user_id'].'|'.$t['slot'].'|'.$t['sha1'])->sort()->values()->all();

    return ['users' => count($rows), 'fingers' => count($prints), 'digest' => sha1(json_encode([$rows, $prints]))];
};
$find = fn () => collect($driver->readUsers($device, false))->first(fn ($u) => $u->userId === $pin);

$lockKey = (new WithoutOverlapping("device:{$device->id}"))->getLockKey(new ProcessDeviceCommand(0));
$lock = Cache::lock($lockKey, 900);
if (! $lock->get()) {
    fwrite(STDERR, "A queued command holds {$device->name}'s lock; try again in a minute.\n");
    exit(1);
}

$added = false;
try {
    if ($clockOnly) {
        throw new LogicException('clock-only');
    }
    if (DeviceCommand::withoutGlobalScopes()->where('device_id', $device->id)->where('status', DeviceCommand::STATUS_RUNNING)->exists()) {
        throw new RuntimeException('A command is running on the terminal.');
    }
    $step('lock held', ['key' => $lockKey]);

    // --- 2. save everything first (rule 19)
    $users = $driver->readUsers($device, false);
    $templates = $driver->readTemplates($device);
    $backup = storage_path('app/private/removed-records/'.strtolower($device->name).'-roster-before-ua300-write-'.date('Ymd-His').'.json.gz');
    @mkdir(dirname($backup), 0775, true);
    file_put_contents($backup, gzencode(json_encode([
        'users' => array_map(fn ($u) => ['uid' => $u->uid, 'user_id' => $u->userId, 'name' => $u->name, 'card' => $u->cardNumber, 'privilege' => $u->privilege], $users),
        'templates' => array_map(fn ($t) => ['user_id' => $t['user_id'], 'slot' => $t['slot'], 'size' => $t['size'], 'sha1' => $t['sha1'], 'template' => base64_encode($t['template'])], $templates),
    ])));
    $before = $state();
    $step('saved and read before', $before + ['backup' => basename($backup), 'test_id_free' => collect($users)->every(fn ($u) => $u->userId !== $pin)]);

    // --- 3. add, rename, card, fingerprint
    $driver->saveUser($device, $pin, 'Test User', function ($occupant) use ($pin) {
        if ($occupant !== null) {
            throw new RuntimeException("User ID {$pin} is taken on the terminal; nothing written.");
        }
    });
    $added = true;
    $u = $find();
    $step('add', ['ok' => $u?->name === 'Test User', 'read_back_name' => $u?->name]);
    if ($u?->name !== 'Test User') {
        throw new RuntimeException('Added user did not read back.');
    }

    $driver->saveUser($device, $pin, 'Test User UA', fn ($occupant) => $occupant?->name === 'Test User' ?: throw new RuntimeException('Not our test user.'));
    $u = $find();
    $step('rename', ['ok' => $u?->name === 'Test User UA', 'read_back_name' => $u?->name]);

    $driver->setUserCard($device, $pin, $card);
    $u = $find();
    $step('card', ['ok' => (int) $u?->cardNumber === $card, 'name_kept' => $u?->name === 'Test User UA']);

    if ($stored !== null) {
        // Stored as base64 of the device blob, encrypted at rest (BiometricTemplate).
        $bytes = (string) base64_decode((string) $stored->template, true);
        $driver->uploadTemplates($device, $pin, [['slot' => (int) $stored->finger_slot, 'template' => $bytes]]);
        $back = collect($driver->readTemplateDigests($device))->first(fn ($t) => (string) $t['user_id'] === $pin && (int) $t['slot'] === (int) $stored->finger_slot);
        $step('fingerprint', ['ok' => $back !== null && $back['sha1'] === sha1($bytes), 'slot' => (int) $stored->finger_slot, 'size_written' => strlen($bytes), 'size_read' => $back['size'] ?? null, 'sha1_match' => ($back['sha1'] ?? null) === sha1($bytes)]);
    } else {
        $step('fingerprint', ['ok' => null, 'skipped' => 'Test User has no stored fingerprint']);
    }
} catch (LogicException $e) {
    $step('user steps skipped (--clock-only)');
} catch (Throwable $e) {
    $step('FAILED', ['error' => get_class($e).': '.$e->getMessage()]);
} finally {
    // --- 4. delete and compare, whatever happened above
    try {
        if ($added || $find() !== null) {
            $driver->deleteUser($device, $pin);
            $gone = $find() === null;
            $fingersGone = collect($driver->readTemplateDigests($device))->every(fn ($t) => (string) $t['user_id'] !== $pin);
            $step('delete', ['ok' => $gone && $fingersGone, 'user_gone' => $gone, 'fingers_gone' => $fingersGone]);
        }
        if (isset($before)) {
            $after = $state();
            $step('everyone else unchanged', ['ok' => $after === $before] + $after);
        }
    } catch (Throwable $e) {
        $step('DELETE FAILED — remove user '.$pin.' by hand', ['error' => get_class($e).': '.$e->getMessage()]);
    }
}

// --- 5. the clock, after the user steps, still under the lock
try {
    // In memory only, never saved: see the header.
    $clockDevice = (clone $device)->setAttribute('capabilities_cache', array_merge((array) $device->capabilities_cache, ['device.time.write' => 'implemented']));
    $set = $driver->setClock($clockDevice);
    $setAt = time();
    $tz = new DateTimeZone(config('app.device_timezone', 'Asia/Bahrain'));
    $step('clock set', ['before' => $set['before']?->format('Y-m-d H:i:s'), 'immediate_read' => $set['after']?->format('Y-m-d H:i:s')]);
    foreach ([0, 5, 30, 60, 120] as $wait) {
        while (time() < $setAt + $wait) {
            usleep(250000);
        }
        $c = $driver->readClock($device);
        $real = new DateTimeImmutable('now', $tz);
        $step("clock +{$wait}s", ['terminal' => $c?->format('H:i:s'), 'real' => $real->format('H:i:s'), 'drift_s' => $c ? $c->getTimestamp() - $real->getTimestamp() : null]);
    }
} catch (Throwable $e) {
    $step('clock FAILED', ['error' => get_class($e).': '.$e->getMessage()]);
} finally {
    $lock->release();
    $save();
    fwrite(STDERR, "trace: {$traceFile}\n");
}
