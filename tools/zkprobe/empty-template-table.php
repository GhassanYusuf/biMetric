<?php

/*
 * What does an F18 send for its fingerprint table when it holds NO templates?
 *
 * BLAST RADIUS: READ-ONLY. Sends CMD_DB_RRQ (7) with FCT_FINGERTMP — the template-table
 * read the app performs on every enrollment sync — and the user-table read. Nothing is
 * written. Safe while people are clocking in.
 *
 * THE QUESTION (2026-10-21). After IT-Office was wiped and Ghassan pushed to it, his
 * fingers came back "unknown" and the push wrote none: UserService::all() reports every
 * user's fingers as unknown whenever the template table decodes to no records, which
 * cannot tell "the table is empty" from "the table would not decode". To tell them apart
 * the library needs to know what an EMPTY table looks like on the wire — so read one.
 *
 * CONTROL (rule 16): a terminal known to hold templates, in the same run. IT ROOM
 * (192.168.7.62) is the usual control but was offline; Good Wheels (F18/ID) is used.
 *
 * Templates are BIOMETRIC DATA: the trace keeps byte LENGTHS and the 4-byte length
 * prefix only — never a template byte (rule 16).
 *
 * Usage:  php tools/zkprobe/empty-template-table.php <device-id> [device-id ...]
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Support\Tenancy;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Codec\TemplateCodec;
use Promoseven\Biometric\Codec\UserCodec;

Tenancy::spanAllTenants();

$driver = app(App\Contracts\BiometricDeviceDriver::class);
$trace = ['label' => 'empty-template-table', 'host' => 'multiple', 'started_at' => now()->toIso8601String(), 'devices' => []];

foreach (Device::query()->whereIn('id', array_slice($argv, 1))->orderBy('id')->get() as $device) {
    fwrite(STDERR, "\n=== {$device->name} ({$device->ip_address}, {$device->model})\n");
    $entry = ['device_id' => $device->id, 'name' => $device->name, 'host' => $device->ip_address, 'model' => $device->model, 'read_at' => now()->toIso8601String()];

    try {
        $entry += $driver->run($device, function (ProtocolDevice $d) {
            $users = $d->client()->readUsersRaw();
            $templates = $d->client()->readTemplatesRaw();

            return [
                'users_raw_bytes' => strlen($users),
                'users_decoded' => count(UserCodec::decode($users)),
                'templates_raw_bytes' => strlen($templates),
                // The 4-byte length prefix only: it says how long the body claims to be.
                'templates_prefix_hex' => bin2hex(substr($templates, 0, 4)),
                'templates_declared_body' => strlen($templates) >= 4 ? unpack('V', substr($templates, 0, 4))[1] : null,
                'templates_decoded' => count(TemplateCodec::decode($templates)),
            ];
        });
        fwrite(STDERR, json_encode(array_diff_key($entry, ['name' => 1, 'host' => 1])) . "\n");
    } catch (\Throwable $e) {
        $entry['error'] = get_class($e) . ': ' . $e->getMessage();
        fwrite(STDERR, "    FAILED: {$e->getMessage()}\n");
    }

    $trace['devices'][] = $entry;
}

$dir = __DIR__ . '/traces-' . date('Y-m-d');
@mkdir($dir, 0775, true);
$n = 1;
while (file_exists($path = sprintf('%s/empty-template-table-%02d.json', $dir, $n))) {
    $n++;
}
file_put_contents($path, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, "trace: {$path}\n");
