<?php

/*
 * Which lock / door settings does an F18 hold, and does IT ROOM's differ from F18s whose
 * doors work? (casebook DC-035: IT ROOM's door stopped locking after its restart on
 * 2026-10-26 12:58 UTC.)
 *
 * BLAST RADIUS: READ-ONLY. Per terminal: CONNECT (+ AUTH when it asks), then
 * `CMD_OPTIONS_RRQ` (11, Command::DEVICE) once per setting name below, then EXIT. 11 only
 * READS a named option; it is what DeviceClient::deviceParameter() and every info() read
 * already send. No unlock (31), no option write (12), no relay, no restart. Each terminal is
 * read while holding the queue's per-device lock (rule 11).
 *
 * THE NAMES: the F18 manual is not public (ZKTeco members only, 2026-10-27). The names are
 * the ones ZKTeco standalone firmware uses in its option table, as seen in option dumps;
 * a name the firmware does not know comes back empty, which is recorded, not guessed at.
 * "~Platform" is the positive control: it must answer on every F18.
 *
 * CONTROLS: Camelot and Good Wheels (F18/ID) are read in the same run (rule 16): a value is
 * only evidence when the terminals whose doors work hold a different one.
 *
 * Usage:  php tools/zkprobe/door-options.php <device-id> [device-id ...]
 */

require __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/Probe.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ProcessDeviceCommand;
use App\Models\Device;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Promoseven\Biometric\Codec\RecordBuffer;
use Promoseven\Biometric\Protocol\Command;

const NAMES = [
    '~Platform',
    '~LockFunOn', 'LockOn', 'LockDelay', 'OpenDoorDelay', 'LockPowerOn',
    'DoorSensorMode', 'DoorSensorType', '~DoorSensorType', 'DoorSensorTimeout', 'DSensorTimeOut',
    'DoorCloseTimeZone', 'NormalOpenTZ', '~NormalOpenTZ', 'LockGroup', '~ExtendFmt',
    'AntiPassback', 'MasterState', 'WiegandFmt', 'WiegandOutFmt', '~SRBOn', 'SRBOn',
];

$ids = array_map('intval', array_slice($argv, 1));
$devices = Device::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->get();
if ($devices->isEmpty()) {
    fwrite(STDERR, "Usage: php tools/zkprobe/door-options.php <device-id> [device-id ...]\n");
    exit(2);
}

$trace = [
    'label' => 'door-options',
    'host' => $devices->pluck('ip_address')->implode(','),
    'started_at' => gmdate('c'),
    'question' => 'Lock/door options per F18; does IT ROOM differ from terminals whose doors work?',
    'names' => NAMES,
    'devices' => [],
];

foreach ($devices as $device) {
    $entry = ['id' => $device->id, 'name' => $device->name, 'model' => $device->model, 'host' => $device->ip_address, 'options' => []];
    $lock = Cache::lock((new WithoutOverlapping("device:{$device->id}"))->getLockKey(new ProcessDeviceCommand(0)), 120);
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
        foreach (NAMES as $name) {
            $r = $p->cmd(Command::DEVICE, $name); // exactly as DeviceClient::deviceParameter() sends it
            $raw = $r === [] ? null : RecordBuffer::cString($r['data'] ?? '');
            $entry['options'][$name] = $r === []
                ? ['reply' => null]
                : ['reply' => Command::name($r['command']), 'value' => $raw];
        }
        $p->disconnect();
        $p->close();
    } catch (Throwable $e) {
        $entry['error'] = get_class($e).': '.$e->getMessage();
    } finally {
        $lock->release();
    }
    $trace['devices'][] = $entry;
}

$trace['finished_at'] = gmdate('c');
$dir = __DIR__.'/traces-'.gmdate('Y-m-d');
@mkdir($dir, 0775, true);
$n = 1;
while (file_exists(sprintf('%s/door-options-%02d.json', $dir, $n))) {
    $n++;
}
$file = sprintf('%s/door-options-%02d.json', $dir, $n);
file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

// Side by side, one row per name.
printf("%-20s", 'option');
foreach ($trace['devices'] as $d) {
    printf(" | %-24s", $d['name']);
}
echo "\n";
foreach (NAMES as $name) {
    printf("%-20s", $name);
    foreach ($trace['devices'] as $d) {
        $o = $d['options'][$name] ?? null;
        $v = $o === null ? ($d['error'] ?? '-') : ($o['reply'] === null ? '(no reply)' : (($o['value'] ?? '') === '' ? '(empty) '.$o['reply'] : $o['value']));
        printf(" | %-24s", substr($v, 0, 24));
    }
    echo "\n";
}
echo "trace: {$file}\n";
