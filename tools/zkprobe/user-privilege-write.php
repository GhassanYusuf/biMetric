<?php

/*
 * Does an administrator / normal-user change written by this app read back on the
 * terminal, and leave everything else as it was?
 *
 * BLAST RADIUS: WRITES TO THE DEVICE — a TEMPORARY TEST USER ONLY. On each terminal
 * named it:
 *   1. reads the roster (saved in memory as "before" — user ids and each user's
 *      privilege only);
 *   2. adds a new user "zkt-test" on a user id free on BOTH the terminal and in this
 *      system (DeviceUserSyncService::withTemporaryUser, the path the password and
 *      fingerprint write tests use), with keypad password 1357;
 *   3. makes it an administrator (14) and reads back; then a normal user (0) and reads
 *      back; checks name and password survived both;
 *   4. deletes the test user (withTemporaryUser's finally) and reads the roster again:
 *      same user ids, and every OTHER user's privilege unchanged.
 * No real person's record is touched: the test user's id is checked free on the
 * hardware inside the write session, and the write is refused if anyone holds it.
 * An administrator can open the terminal menu; the test user holds that for seconds and
 * has no fingerprint or card, only the dummy password.
 * TO UNDO by hand if a run is killed mid-way: delete user "zkt-test" on that terminal
 * (the id is printed and in the trace).
 *
 * Owner's go-ahead: 2026-10-20, "apply the changes … to all devices, also the admin and
 * normal user thing" (rule 16).
 *
 * WHAT IT SETTLES (rule 21): `user.privilege` on that terminal's MODEL — IMPLEMENTED
 * until this reads back, CONFIRMED after clean runs. One model's run says nothing about
 * another (rule 15). C3 panels are not in scope: their user table has no admin menu to
 * grant (`user.privilege` not_implemented, docs/device-models.md).
 *
 * WHAT REACHES THE TRACE: steps, verdicts, the TEST user's id, roster counts. No real
 * user's name, card or password.
 *
 * Usage:  php tools/zkprobe/user-privilege-write.php <device-id> [device-id ...]
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
    fwrite(STDERR, "Name the terminals: php tools/zkprobe/user-privilege-write.php <device-id> ...\n");
    exit(2);
}

$driver = app(BiometricDeviceDriver::class);
$sync = app(DeviceUserSyncService::class);
$devices = Device::query()->whereIn('id', $ids)->orderBy('id')->get();

$dir = __DIR__.'/traces-'.date('Y-m-d');
@mkdir($dir, 0775, true);
$trace = [
    'label' => 'user-privilege-write',
    'host' => $devices->pluck('ip_address')->implode(','),
    'started_at' => now()->toIso8601String(),
    'question' => 'Does setUserPrivilege() read back 14 then 0 on a test user, keeping its name and password, with every other user unchanged?',
    'devices' => [],
];

// user id => privilege, for everyone on the terminal.
$roster = fn (Device $d) => collect($driver->readUsers($d, false))->mapWithKeys(fn ($u) => [$u->userId => $u->privilege])->sortKeys()->all();

foreach ($devices as $device) {
    $entry = ['device_id' => $device->id, 'name' => $device->name, 'host' => $device->ip_address, 'model' => $device->model, 'steps' => []];
    fwrite(STDERR, "=== {$device->name} ({$device->ip_address}, {$device->model})\n");
    $step = function (string $what, array $data = []) use (&$entry) {
        $entry['steps'][] = ['at' => now()->toIso8601String(), 'step' => $what] + $data;
        fwrite(STDERR, "  {$what} ".json_encode($data)."\n");
    };

    try {
        $before = $roster($device);
        $step('roster before', ['users' => count($before), 'admins' => count(array_filter($before, fn ($p) => $p > 0))]);

        $sync->withTemporaryUser($device, function (string $pin) use ($driver, $device, $step) {
            $driver->saveUser($device, $pin, 'zkt-test', null, false, null, '1357');
            $step('test user added', ['user_id' => $pin]);
            foreach ([true => 14, false => 0] as $admin => $expected) {
                $user = $driver->setUserPrivilege($device, $pin, (bool) $admin);
                $ok = $user->privilege === $expected && $user->name === 'zkt-test' && $user->password === '1357';
                $step(($admin ? 'made administrator' : 'made normal user').' and read back', [
                    'user_id' => $pin, 'privilege' => $user->privilege, 'expected' => $expected,
                    'name_kept' => $user->name === 'zkt-test', 'password_kept' => $user->password === '1357',
                ]);
                if (! $ok) {
                    throw new RuntimeException("Read back privilege {$user->privilege}, expected {$expected} (or name/password lost).");
                }
            }
        });

        $after = $roster($device);
        $entry['roster_unchanged'] = $after === $before;
        $step('roster after', ['users' => count($after), 'unchanged' => $entry['roster_unchanged']]);
        $entry['verdict'] = $entry['roster_unchanged'] ? 'user.privilege reads back' : 'ROSTER CHANGED — check the terminal';
    } catch (Throwable $e) {
        $entry['error'] = get_class($e).': '.$e->getMessage();
        $entry['verdict'] = 'failed — level stays as it was (a throw is not evidence about firmware)';
        fwrite(STDERR, "  FAILED: {$entry['error']}\n");
    }

    $trace['devices'][] = $entry;
}

$n = 1;
while (file_exists($path = sprintf('%s/user-privilege-write-%02d.json', $dir, $n))) {
    $n++;
}
file_put_contents($path, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
fwrite(STDERR, "trace: {$path}\n");
