<?php

/*
 * Do the C3 door panels report live door state? (owner, 2026-11-18: "can we see if the door is locked
 * and the status of the lock".) Asks each panel for its real-time log a few times and decodes it.
 *
 * Research (rule 17): vwout/zkaccess-c3-py — c3/consts.py (Command.RTLOG_BINARY = 0x0B; EventType
 * DOOR_ALARM_STATUS = 255, DOOR_OPENED_CORRECT = 200, DOOR_CLOSED_CORRECT = 201, EXIT_BUTTON_OPEN = 202;
 * InOutStatus UNKNOWN 0 / CLOSED 1 / OPEN 2; AlarmStatus bits ALARM 1, DOOR_OPEN_TIMEOUT 2) and c3/rtlog.py
 * (16-byte records; byte 10 = event type; a 255 record is door/alarm status: alarm bytes 0–3 and door
 * sensor bytes 4–7 per door, low nibble; otherwise an event: card 0–3, pin 4–7, verified 8, door 9,
 * event 10, in/out 11, time 12–15). That source's docstring and code disagree on the status offsets; the
 * code (alarm 0–3, sensor 4–7) is transcribed here, and the raw bytes are kept to settle it.
 *
 * BLAST RADIUS: READ-ONLY. Per panel: open the TCP session the library's panel model sets up (the same as
 * every roster read), RTLOG (0x0B) a few times one second apart, DISCONNECT. RTLOG returns the panel's
 * latest status and events; it opens no door, drives no relay, changes no setting, and deletes nothing
 * (the transaction table is separate and untouched). Holds the queue's per-device lock (rule 11).
 *
 * The trace keeps each record's decoded fields and its raw hex. An event record may carry a card number
 * and a user number: the card is masked in the trace (only whether one was present), the user number kept.
 *
 * Usage:  php tools/zkprobe/c3-rtlog.php <device-id> [device-id ...] [--polls=5]
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ProcessDeviceCommand;
use App\Models\Device;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Promoseven\Biometric\C3\Model\PanelModelFactory;
use Promoseven\Biometric\C3\Protocol\Command;
use Promoseven\Biometric\C3\Transport\C3TcpTransport;

$polls = 5;
$ids = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--polls=')) {
        $polls = max(1, (int) substr($a, 8));
    } else {
        $ids[] = (int) $a;
    }
}
const RTLOG = 0x0B;
$sensor = [0 => 'unknown', 1 => 'closed', 2 => 'open'];
$events = [200 => 'door opened', 201 => 'door closed', 202 => 'opened by exit button', 255 => 'door/alarm status'];

$trace = ['label' => 'c3-rtlog', 'host' => '', 'started_at' => gmdate('c'),
    'question' => 'Does RTLOG (0x0B) report live door state on our C3 panels?', 'panels' => []];
$hosts = [];

foreach (Device::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->get() as $d) {
    $hosts[] = "{$d->ip_address}:{$d->port}";
    $entry = ['id' => $d->id, 'name' => $d->name, 'model' => $d->model, 'polls' => []];
    $lock = Cache::lock((new WithoutOverlapping("device:{$d->id}"))->getLockKey(new ProcessDeviceCommand(0)), 120);
    if (! $lock->get()) {
        $entry['error'] = 'busy';
        $trace['panels'][] = $entry;
        echo "{$d->name}: busy\n";
        continue;
    }
    $t = new C3TcpTransport($d->ip_address, (int) ($d->port ?: 4370), 6.0);
    try {
        $t->open();
        PanelModelFactory::for($d->model)->establishSession($t);
        for ($p = 1; $p <= $polls; $p++) {
            [$code, $payload] = $t->send(RTLOG);
            $records = [];
            foreach (str_split($payload, 16) as $rec) {
                if (strlen($rec) < 16) {
                    continue;
                }
                $b = array_values(unpack('C*', $rec));
                $type = $b[10];
                $row = ['raw' => bin2hex($rec), 'event' => $type, 'meaning' => $events[$type] ?? 'event '.$type];
                if ($type === 255) {
                    for ($door = 1; $door <= 4; $door++) {
                        $row['doors'][$door] = [
                            'sensor' => $sensor[$b[3 + $door] & 0x0F] ?? ('?'.($b[3 + $door] & 0x0F)),
                            'alarm' => ($b[$door - 1] & 1) === 1,
                            'open_too_long' => ($b[$door - 1] & 2) === 2,
                        ];
                    }
                } else {
                    $row += ['door' => $b[9], 'pin' => unpack('V', substr($rec, 4, 4))[1],
                        'card_present' => unpack('V', substr($rec, 0, 4))[1] !== 0, 'verified' => $b[8], 'in_out' => $b[11]];
                    $row['raw'] = '********'.substr($row['raw'], 8); // never keep a card number
                }
                $records[] = $row;
            }
            $entry['polls'][] = ['n' => $p, 'reply' => $code, 'bytes' => strlen($payload), 'records' => $records];
            printf("%-11s poll %d: reply 0x%02X, %d bytes, %d records\n", $d->name, $p, $code, strlen($payload), count($records));
            foreach ($records as $r) {
                if ($r['event'] === 255) {
                    $doors = array_map(fn ($n, $x) => "door {$n} {$x['sensor']}".($x['alarm'] ? ' ALARM' : '').($x['open_too_long'] ? ' OPEN-TOO-LONG' : ''), array_keys($r['doors']), $r['doors']);
                    echo '    status: '.implode(', ', $doors)."  raw {$r['raw']}\n";
                } else {
                    echo "    event {$r['event']} ({$r['meaning']}) door {$r['door']} pin {$r['pin']}\n";
                }
            }
            usleep(1_000_000);
        }
    } catch (Throwable $e) {
        $entry['error'] = get_class($e).': '.$e->getMessage();
        echo "{$d->name}: {$entry['error']}\n";
    } finally {
        try {
            $t->send(Command::DISCONNECT, '', 3.0);
        } catch (Throwable) {
        }
        $t->close();
        $lock->release();
    }
    $trace['panels'][] = $entry;
}

$trace['host'] = implode(',', $hosts);
$trace['finished_at'] = gmdate('c');
$dir = __DIR__.'/traces-'.gmdate('Y-m-d');
@mkdir($dir, 0775, true);
$k = 1;
while (file_exists(sprintf('%s/c3-rtlog-%02d.json', $dir, $k))) {
    $k++;
}
$file = sprintf('%s/c3-rtlog-%02d.json', $dir, $k);
file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "trace: {$file}\n";
