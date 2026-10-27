<?php

/*
 * What does an F18 report as its door / lock state right now? (casebook DC-035: IT ROOM's
 * door opens without a finger since 2026-10-26.)
 *
 * BLAST RADIUS: READ-ONLY. Per terminal: CONNECT (+ AUTH when asked), then
 * CMD_DOORSTATE_RRQ (75) once, then EXIT. 75 READS the door state; it unlocks nothing and
 * changes no setting. Each terminal is read while holding the queue's per-device lock
 * (rule 11).
 *
 * SOURCE (rule 17): fananimi/pyzk, `zk/const.py`: CMD_DOORSTATE_RRQ = 75 ("Read door
 * state"). Its reply layout is not documented there, so the raw reply is kept whole (it is a
 * few bytes of state, no personal data) and compared across terminals.
 *
 * CONTROLS: Camelot and Good Wheels (F18/ID), whose doors lock, in the same run (rule 16).
 *
 * Usage:  php tools/zkprobe/door-state.php <device-id> [device-id ...]
 */

require __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/Probe.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ProcessDeviceCommand;
use App\Models\Device;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Promoseven\Biometric\Protocol\Command;

const CMD_DOORSTATE_RRQ = 75;

$ids = array_map('intval', array_slice($argv, 1));
$devices = Device::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->get();
if ($devices->isEmpty()) {
    fwrite(STDERR, "Usage: php tools/zkprobe/door-state.php <device-id> [device-id ...]\n");
    exit(2);
}

$trace = [
    'label' => 'door-state',
    'host' => $devices->pluck('ip_address')->implode(','),
    'started_at' => gmdate('c'),
    'question' => 'What does CMD_DOORSTATE_RRQ (75) report on IT ROOM vs F18s whose doors lock?',
    'devices' => [],
];

foreach ($devices as $device) {
    $entry = ['id' => $device->id, 'name' => $device->name, 'host' => $device->ip_address];
    $lock = Cache::lock((new WithoutOverlapping("device:{$device->id}"))->getLockKey(new ProcessDeviceCommand(0)), 60);
    if (! $lock->get()) {
        $entry['error'] = 'queue lock busy; skipped';
        $trace['devices'][] = $entry;
        continue;
    }
    try {
        $p = new Probe($device->ip_address, (int) $device->port);
        $p->open();
        if (! $p->connect((int) ($device->comm_key ?? 0))) {
            throw new RuntimeException('CONNECT/AUTH refused');
        }
        $r = $p->cmd(CMD_DOORSTATE_RRQ);
        $entry['reply'] = $r === [] ? null : Command::name($r['command']);
        $entry['data_hex'] = $r === [] ? null : bin2hex($r['data'] ?? '');
        $p->disconnect();
        $p->close();
    } catch (Throwable $e) {
        $entry['error'] = get_class($e).': '.$e->getMessage();
    } finally {
        $lock->release();
    }
    $trace['devices'][] = $entry;
    printf("%-12s reply=%-28s data=%s %s\n", $device->name, $entry['reply'] ?? '-', $entry['data_hex'] ?? '-', $entry['error'] ?? '');
}

$trace['finished_at'] = gmdate('c');
$dir = __DIR__.'/traces-'.gmdate('Y-m-d');
@mkdir($dir, 0775, true);
$n = 1;
while (file_exists(sprintf('%s/door-state-%02d.json', $dir, $n))) {
    $n++;
}
$file = sprintf('%s/door-state-%02d.json', $dir, $n);
file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "trace: {$file}\n";
