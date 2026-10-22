<?php

/*
 * Will an F18 accept a punch WRITTEN to it — CMD_SET_DATA (3001) on the ATT_LOG table?
 *
 * BLAST RADIUS: WRITES TO THE DEVICE — ONE test punch, on a TEST terminal only. It adds a
 * single attendance record for user 999999001 (on no terminal, mapped to nobody) dated
 * 2001-01-01 09:00:00 — a marker no report covers. No user, card or fingerprint is
 * written; nothing is deleted. TO UNDO: the record stays on the terminal until its punch
 * log is cleared or the terminal wiped (Devices → Clear punches / Wipe terminal). If a
 * read imports it, it lands as an unmatched punch for 999999001 on 2001-01-01.
 *
 * Owner's go-ahead, 2026-10-22: copy recovered punches onto the test terminal IT-Office
 * (192.168.7.64) to view them in the old software, and a "copy onto a replacement
 * terminal" feature. Run ONLY against IT-Office (refuses any other address).
 *
 * REFERENCE (rule 17), transcribed literally: taha-rafi/php-zkteco, commit
 * bf7805c532a68f6402a701fcf04944ef39cfea16 (2026-10-17), src/ZKTeco.php
 * writeAttendanceToDevice(): command 3001 with
 *   "TableName=ATT_LOG\r\nUser_PIN=<pin>\tVerify_Type=<n>\tVerify_Time=<Y-m-dTH:i:s>\tStatus=<n>\tWork_Code_ID=<n>\0"
 * ACK_OK (2000) = accepted, then CMD_REFRESHDATA (1013). Claimed for "ZMM/new-architecture
 * SQLite devices (TX628, ZMM200_TFT, ZMM220, uFace, SpeedFace, iClock)"; reverse-
 * engineered, not ZKTeco documentation. IT-Office is ZMM210_TFT, Ver 6.60 Apr 26 2016.
 * Run 1 follows the reference exactly — no DISABLE_DEVICE around it (rule 16: one
 * variable at a time; our own user writes do disable).
 *
 * WHAT REACHES THE TRACE: reply codes, record counts before/after, and the fields of
 * the TEST record only. No real person's punch or credential.
 *
 * Usage:  php tools/zkprobe/attlog-write.php <device-id> [2001-01-01THH:MM:SS]
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Support\Tenancy;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Protocol\Command;

Tenancy::spanAllTenants();

const TEST_PIN = '999999001';
// A second run uses another minute on the same marker day (rule 16: reproduce it).
define('TEST_TIME', $argv[2] ?? '2001-01-01T09:00:00');

$device = Device::query()->findOrFail((int) ($argv[1] ?? 0));
if ($device->ip_address !== '192.168.7.64') {
    fwrite(STDERR, "Refusing: this probe writes, and runs only against the test terminal IT-Office (192.168.7.64).\n");
    exit(1);
}

$driver = app(App\Contracts\BiometricDeviceDriver::class);
$trace = ['label' => 'attlog-write', 'host' => $device->ip_address, 'started_at' => now()->toIso8601String(), 'device' => [
    'id' => $device->id, 'name' => $device->name, 'model' => $device->model,
], 'test_time' => TEST_TIME, 'steps' => []];

$testRecords = function (ProtocolDevice $d): array {
    $all = $d->attendance()->all();
    $mine = array_values(array_filter($all, fn ($r) => isset($r['user_id']) && $r['user_id'] === TEST_PIN));

    return ['total' => count($all), 'test_records' => array_map(fn ($r) => [
        'user_id' => $r['user_id'], 'verify_method' => $r['verify_method'], 'status' => $r['status'],
        'timestamp' => $r['timestamp']->format('Y-m-d H:i:s'),
    ], $mine)];
};

try {
    $trace['steps'][] = ['step' => 'before', 'at' => now()->toIso8601String()] + $driver->run($device, $testRecords);
    fwrite(STDERR, 'before: '.json_encode(end($trace['steps']))."\n");

    $payload = "TableName=ATT_LOG\r\n"
        .'User_PIN='.TEST_PIN."\tVerify_Type=1\tVerify_Time=".TEST_TIME."\tStatus=0\tWork_Code_ID=0\0";

    $trace['steps'][] = ['step' => 'write', 'at' => now()->toIso8601String()] + $driver->run($device, function (ProtocolDevice $d) use ($payload) {
        $set = $d->client()->send(3001, $payload);
        $refresh = $d->client()->send(Command::REFRESH_DATA);

        return [
            'payload_bytes' => strlen($payload),
            'set_data_reply' => $set->command, 'set_data_reply_name' => $set->commandName(), 'set_data_reply_data_hex' => bin2hex($set->data),
            'refresh_reply' => $refresh->command, 'refresh_reply_name' => $refresh->commandName(),
        ];
    });
    fwrite(STDERR, 'write: '.json_encode(end($trace['steps']))."\n");

    $trace['steps'][] = ['step' => 'after', 'at' => now()->toIso8601String()] + $driver->run($device, $testRecords);
    fwrite(STDERR, 'after: '.json_encode(end($trace['steps']))."\n");
} catch (\Throwable $e) {
    $trace['error'] = get_class($e).': '.$e->getMessage();
    fwrite(STDERR, "FAILED: {$trace['error']}\n");
}

$dir = __DIR__.'/traces-'.date('Y-m-d');
@mkdir($dir, 0775, true);
$n = 1;
while (file_exists($path = sprintf('%s/attlog-write-%02d.json', $dir, $n))) {
    $n++;
}
file_put_contents($path, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, "trace: {$path}\n");
