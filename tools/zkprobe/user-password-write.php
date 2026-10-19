<?php

/*
 * Does a keypad password written by this app read back on the terminal?
 *
 * BLAST RADIUS: WRITES TO THE DEVICE — a TEMPORARY TEST USER ONLY. On each terminal
 * named it:
 *   1. reads the roster (saved in memory as "before" — counts and user ids only);
 *   2. adds a new user "zkt-test" on a user id free on BOTH the terminal and in this
 *      system (DeviceUserSyncService::withTemporaryUser, the same path the fingerprint
 *      write test uses), with keypad password 1357;
 *   3. reads it back; then writes password 2468 to the same user and reads back;
 *   4. deletes the test user (withTemporaryUser's finally) and reads the roster again:
 *      it must equal "before" — same count, same user ids.
 * No real person's record is touched: the test user's id is checked free on the
 * hardware inside the write session, and the write is refused if anyone holds it.
 * TO UNDO by hand if a run is killed mid-way: delete user "zkt-test" on that terminal
 * (the id is printed and in the trace).
 *
 * Needs the owner's go-ahead for each run (rule 16). Asked 2026-10-12.
 *
 * WHAT IT SETTLES (rule 21): `password.write` on that terminal's MODEL — IMPLEMENTED
 * until this reads back, CONFIRMED after a clean run. One model's run says nothing
 * about another (rule 15): run it on each model, with IT ROOM (F18) as the control.
 *
 * WHAT REACHES THE TRACE: steps, verdicts, user ids of the TEST user, roster counts.
 * The test passwords are fixed dummies, not anybody's credential.
 *
 * Usage:  php tools/zkprobe/user-password-write.php <device-id> [device-id ...]
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Contracts\BiometricDeviceDriver;
use App\Models\Device;
use App\Services\Devices\DeviceUserSyncService;
use App\Support\Tenancy;

Tenancy::spanAllTenants();

$ids = array_slice($argv, 1);
if ($ids === []) {
    fwrite(STDERR, "Name the terminals: php tools/zkprobe/user-password-write.php <device-id> ...\n");
    exit(2);
}

$driver = app(BiometricDeviceDriver::class);
$sync = app(DeviceUserSyncService::class);
$devices = Device::query()->whereIn('id', $ids)->orderBy('id')->get();

$dir = __DIR__.'/traces-'.date('Y-m-d');
@mkdir($dir, 0775, true);
$trace = [
    'label' => 'user-password-write',
    'host' => $devices->pluck('ip_address')->implode(','),
    'started_at' => now()->toIso8601String(),
    'question' => 'Does a keypad password written through saveUser() read back, and is the roster unchanged after?',
    'devices' => [],
];

$roster = fn (Device $d) => collect($driver->readUsers($d, false))->map(fn ($u) => $u->userId)->sort()->values()->all();

foreach ($devices as $device) {
    $entry = ['device_id' => $device->id, 'name' => $device->name, 'host' => $device->ip_address, 'model' => $device->model, 'steps' => []];
    fwrite(STDERR, "=== {$device->name} ({$device->ip_address}, {$device->model})\n");
    $step = function (string $what, array $data = []) use (&$entry) {
        $entry['steps'][] = ['at' => now()->toIso8601String(), 'step' => $what] + $data;
        fwrite(STDERR, "  {$what} ".json_encode($data)."\n");
    };

    try {
        $before = $roster($device);
        $step('roster before', ['users' => count($before)]);

        $sync->withTemporaryUser($device, function (string $pin) use ($driver, $device, $step) {
            $step('test user added', ['user_id' => $pin]);
            foreach (['1357', '2468'] as $password) {
                $user = $driver->saveUser($device, $pin, 'zkt-test', null, false, null, $password);
                $step('password written and read back', ['user_id' => $pin, 'matches' => $user->password === $password]);
                if ($user->password !== $password) {
                    throw new RuntimeException('The password did not read back as written.');
                }
            }
            // A name write without a password must keep it (the 2.13.0 fix).
            $kept = $driver->saveUser($device, $pin, 'zkt-test2', null, false, null, null);
            $step('name write kept the password', ['user_id' => $pin, 'kept' => $kept->password === '2468']);
        });

        $after = $roster($device);
        $entry['roster_unchanged'] = $after === $before;
        $step('roster after', ['users' => count($after), 'unchanged' => $entry['roster_unchanged']]);
        $entry['verdict'] = $entry['roster_unchanged'] ? 'password.write reads back' : 'ROSTER CHANGED — check the terminal';
    } catch (Throwable $e) {
        $entry['error'] = get_class($e).': '.$e->getMessage();
        $entry['verdict'] = 'failed — level stays implemented (a throw is not evidence about firmware)';
        fwrite(STDERR, "  FAILED: {$entry['error']}\n");
    }

    $trace['devices'][] = $entry;
}

$n = 1;
while (file_exists($path = sprintf('%s/user-password-write-%02d.json', $dir, $n))) {
    $n++;
}
file_put_contents($path, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
fwrite(STDERR, "trace: {$path}\n");
