<?php

/*
 * READ-ONLY. Sends CMD_GET_FREE_SIZES (50) to ZKTeco standalone terminals and records
 * the raw reply, so the "how many records does the terminal hold" counters can be
 * decoded against hardware rather than assumed.
 *
 * WHY: a safe "clear attendance" (CMD_CLEAR_ATTLOG) must prove the read before it was
 * COMPLETE — every record the terminal holds was stored — and that the clear worked —
 * the terminal now holds none. Both need the terminal's own record count, which
 * GET_FREE_SIZES carries and nothing in this project decodes yet.
 *
 * WHAT IT SENDS: CMD_CONNECT, CMD_GET_FREE_SIZES (50), CMD_EXIT — the same reply
 * `DeviceClient::getDeviceInfo()` already fetches on every info read. No table is
 * read, nothing is written, the device is never disabled.
 *
 * REFERENCE: fananimi/pyzk `zk/base.py` read_sizes(): unpack('20i', data[:80]),
 * users = [4], fingers = [6], records = [8], cards = [12], fingers_cap = [14],
 * users_cap = [15], rec_cap = [16]; faces = unpack('3i', data[80:92]) when present.
 * Transcribed, not invented — a mismatch here is a finding about the device.
 *
 * Usage: php tools/zkprobe/free-sizes.php <label> <device_id> [<device_id> …]
 */

require '/var/www/zkt/vendor/autoload.php';
$app = require_once '/var/www/zkt/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Services\Devices\ZktecoDriver;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Protocol\Command;

$label = $argv[1] ?? 'free-sizes';
$ids = array_slice($argv, 2);

$driver = app(ZktecoDriver::class);
$dir = __DIR__.'/traces-'.gmdate('Y-m-d');
@mkdir($dir, 0775, true);

foreach ($ids as $id) {
    $device = Device::withoutGlobalScopes()->findOrFail($id);
    $trace = [
        'label' => $label,
        'host' => $device->ip_address,
        'started_at' => gmdate('c'),
        'device' => ['id' => $device->id, 'name' => $device->name, 'model' => $device->model],
        'question' => 'GET_FREE_SIZES layout: which int32 is the attendance record count?',
    ];

    try {
        $raw = $driver->run($device, fn (ProtocolDevice $d) => $d->client()->send(Command::GET_FREE_SIZES)->data);
        $ints = array_values(unpack('l*', substr($raw, 0, intdiv(strlen($raw), 4) * 4)));
        $trace['reply_bytes'] = strlen($raw);
        $trace['reply_hex'] = bin2hex($raw);
        $trace['int32'] = $ints;
        $trace['pyzk_reading'] = [
            'users' => $ints[4] ?? null, 'fingers' => $ints[6] ?? null, 'records' => $ints[8] ?? null,
            'cards' => $ints[12] ?? null, 'fingers_cap' => $ints[14] ?? null,
            'users_cap' => $ints[15] ?? null, 'rec_cap' => $ints[16] ?? null,
        ];
    } catch (Throwable $e) {
        $trace['error'] = $e::class.': '.$e->getMessage();
    }

    $trace['finished_at'] = gmdate('c');
    $n = 1;
    while (file_exists($file = sprintf('%s/%s-%s-%02d.json', $dir, $label, $device->id, $n))) {
        $n++;
    }
    file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT)."\n");
    echo $device->name.': '.json_encode($trace['pyzk_reading'] ?? $trace['error'])."\n";
}
