<?php

/*
 * Copies a prepared list of punches onto the TEST terminal IT-Office (DC-028).
 *
 * BLAST RADIUS: WRITES TO THE DEVICE — adds attendance records to IT-Office
 * (192.168.7.64) ONLY; refuses any other address. No user, card or fingerprint is
 * written; nothing is deleted. TO UNDO: Devices → Clear punches / Wipe terminal on
 * IT-Office. Owner's go-ahead, 2026-10-22: "copy all … to IT-Office" — Camelot's
 * attendance as it was before its 2026-10-21 wipe (the system's copy plus the punches
 * rebuilt from the raw archive), to look at in the old software.
 *
 * METHOD: CMD_SET_DATA (3001) with an ATT_LOG text record per punch, then REFRESH_DATA,
 * exactly as tools/zkprobe/attlog-write.php proved twice on this terminal
 * (traces-2026-10-22/attlog-write-01/02.json; reference taha-rafi/php-zkteco bf7805c).
 * Verify_Time is the terminal's LOCAL wall clock (+03:00); the list holds UTC.
 *
 * RESUMABLE, NEVER DOUBLE-WRITES: the terminal stores a repeated punch twice, so the
 * index of the next punch to send is kept in <list>.progress and every run continues
 * from it. It stops at the first reply that is not ACK_OK and says where.
 *
 * WHAT REACHES THE TRACE: counts, timings, reply codes, the verify result — not the
 * punches themselves (they are attendance records of real people).
 *
 * Usage:  php tools/zkprobe/attlog-copy.php <device-id> <list.json> [max-to-send] [batch]
 *         php tools/zkprobe/attlog-copy.php <device-id> <list.json> --verify
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Support\Tenancy;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Protocol\Command;

Tenancy::spanAllTenants();

$device = Device::query()->findOrFail((int) ($argv[1] ?? 0));
if ($device->ip_address !== '192.168.7.64') {
    fwrite(STDERR, "Refusing: this writes, and runs only against the test terminal IT-Office (192.168.7.64).\n");
    exit(1);
}
$listPath = $argv[2] ?? '';
$list = json_decode((string) file_get_contents($listPath), true, 512, JSON_THROW_ON_ERROR);
$progressPath = $listPath.'.progress';
$next = is_file($progressPath) ? (int) file_get_contents($progressPath) : 0;
$verify = ($argv[3] ?? '') === '--verify';
$max = $verify ? 0 : (int) ($argv[3] ?? count($list));
$batch = (int) ($argv[4] ?? 1000);

$driver = app(App\Contracts\BiometricDeviceDriver::class);
$local = new DateTimeZone('+03:00');
$trace = ['label' => 'attlog-copy', 'host' => $device->ip_address, 'started_at' => now()->toIso8601String(),
    'list_size' => count($list), 'start_index' => $next, 'batches' => []];

$stopAt = min(count($list), $next + $max);
while (! $verify && $next < $stopAt) {
    $end = min($stopAt, $next + $batch);
    $t0 = microtime(true);
    $result = $driver->run($device, function (ProtocolDevice $d) use ($list, &$next, $end, $local, $progressPath) {
        $sent = 0;
        for (; $next < $end; $next++) {
            $p = $list[$next];
            $time = (new DateTimeImmutable($p['utc'], new DateTimeZone('UTC')))->setTimezone($local)->format('Y-m-d\TH:i:s');
            $reply = $d->client()->send(3001, "TableName=ATT_LOG\r\nUser_PIN={$p['pin']}\tVerify_Type={$p['verify']}\tVerify_Time={$time}\tStatus={$p['status']}\tWork_Code_ID=0\0");
            if (! $reply->isAccepted()) {
                file_put_contents($progressPath, (string) $next);

                return ['sent' => $sent, 'refused_at' => $next, 'reply' => $reply->command];
            }
            $sent++;
            if ($sent % 100 === 0) {
                file_put_contents($progressPath, (string) ($next + 1));
            }
        }
        file_put_contents($progressPath, (string) $next);
        $refresh = $d->client()->send(Command::REFRESH_DATA);

        return ['sent' => $sent, 'refresh_reply' => $refresh->command];
    });
    $result['seconds'] = round(microtime(true) - $t0, 1);
    $result['next_index'] = $next;
    $trace['batches'][] = $result;
    fwrite(STDERR, json_encode($result)."\n");
    if (isset($result['refused_at'])) {
        break;
    }
}

if ($verify) {
    $onTerminal = $driver->run($device, fn (ProtocolDevice $d) => $d->attendance()->all());
    $have = [];
    foreach ($onTerminal as $r) {
        if (isset($r['user_id'])) {
            $have[$r['user_id'].'|'.$r['timestamp']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')] = true;
        }
    }
    $missing = 0;
    foreach ($list as $p) {
        if (! isset($have[$p['pin'].'|'.$p['utc']])) {
            $missing++;
        }
    }
    $trace['verify'] = ['records_on_terminal' => count($onTerminal), 'decoded' => count($have), 'list_size' => count($list), 'list_missing_on_terminal' => $missing];
    fwrite(STDERR, json_encode($trace['verify'])."\n");
}

$dir = __DIR__.'/traces-'.date('Y-m-d');
@mkdir($dir, 0775, true);
$n = 1;
while (file_exists($path = sprintf('%s/attlog-copy-%02d.json', $dir, $n))) {
    $n++;
}
file_put_contents($path, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, "trace: {$path}\n");
