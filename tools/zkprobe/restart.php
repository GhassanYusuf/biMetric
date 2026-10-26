<?php

/*
 * What does a remote RESTART (1004) do to a ZKTeco standalone terminal, and does it
 * clear a stuck state the way a power cycle does?
 *
 * BLAST RADIUS: WRITES TO THE DEVICE — REBOOTS IT. Nothing in its tables is written.
 * The terminal is unusable while it restarts (no punches can be made then; punches it
 * already holds stay on it). On the one terminal named it:
 *   1. holds the queue's own per-device lock for the run (rule 11);
 *   2. reads BEFORE: device info, clock, GET_FREE_SIZES counters, user and template
 *      counts (no names or templates reach the trace);
 *   3. opens a raw session (AUTH when the terminal asks) and sends RESTART, recording
 *      the exact reply;
 *   4. tries CONNECT every 2 s and records when, and how, it answers again;
 *   5. reads AFTER the same set as 2.
 * TO UNDO: nothing to undo; if it does not come back, power it off and on at the site.
 *
 * Needs the owner's go-ahead for each run (rule 16). Given 2026-10-26 for Camelot
 * (F18/ID, 87.237.196.60), casebook DC-034: "try to restart it remote, maybe you catch
 * something unfamiliar". device.restart is IMPLEMENTED on the F18 and had never run.
 *
 * Usage:  php tools/zkprobe/restart.php <device-id> [max-wait-seconds]
 */

require __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/Probe.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Contracts\BiometricDeviceDriver;
use App\Jobs\ProcessDeviceCommand;
use App\Models\Device;
use App\Support\Tenancy;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Promoseven\Biometric\Codec\FreeSizesCodec;
use Promoseven\Biometric\Protocol\Command;

$device = Device::withoutGlobalScopes()->find((int) ($argv[1] ?? 0));
$maxWait = (int) ($argv[2] ?? 300);
if ($device === null) {
    fwrite(STDERR, "Usage: php tools/zkprobe/restart.php <device-id> [max-wait-seconds]\n");
    exit(2);
}
Tenancy::set($device->tenant_id);
$driver = app(BiometricDeviceDriver::class);
$commKey = (int) ($device->comm_key ?? 0);

$trace = [
    'label' => 'restart',
    'host' => $device->ip_address,
    'started_at' => gmdate('c'),
    'device' => ['id' => $device->id, 'name' => $device->name, 'model' => $device->model],
    'question' => 'What does RESTART (1004) reply, how long is the terminal away, and what is different after?',
    'steps' => [],
];
$step = function (string $what, array $data = []) use (&$trace) {
    $trace['steps'][] = ['at' => gmdate('c'), 'step' => $what] + $data;
    fwrite(STDOUT, '  '.gmdate('H:i:s')." {$what} ".json_encode($data, JSON_UNESCAPED_SLASHES)."\n");
};
$state = function () use ($driver, $device, $commKey) {
    $out = [];
    try {
        $info = $driver->info($device);
        $out['firmware'] = $info['firmware'] ?? null;
        $out['serial'] = $info['serial'] ?? null;
    } catch (Throwable $e) {
        $out['info_error'] = $e->getMessage();
    }
    try {
        $c = $driver->readClock($device);
        $out['clock'] = $c?->format('Y-m-d H:i:s');
        $out['drift_s'] = $c ? $c->getTimestamp() - time() : null;
    } catch (Throwable $e) {
        $out['clock_error'] = $e->getMessage();
    }
    try {
        $p = new Probe($device->ip_address, (int) $device->port);
        $p->open();
        if ($p->connect($commKey)) {
            $r = $p->cmd(Command::GET_FREE_SIZES);
            $out['free_sizes'] = FreeSizesCodec::decode($r['data'] ?? '');
            $p->disconnect();
        }
        $p->close();
    } catch (Throwable $e) {
        $out['free_sizes_error'] = $e->getMessage();
    }
    try {
        $users = $driver->readUsers($device, false);
        $out['users_read'] = count($users);
        $out['max_uid'] = max(array_map(fn ($u) => $u->uid, $users) ?: [0]);
    } catch (Throwable $e) {
        $out['users_error'] = $e->getMessage();
    }
    try {
        $out['templates_read'] = count($driver->readTemplateDigests($device));
    } catch (Throwable $e) {
        $out['templates_error'] = $e->getMessage();
    }

    return $out;
};

$lock = Cache::lock((new WithoutOverlapping("device:{$device->id}"))->getLockKey(new ProcessDeviceCommand(0)), $maxWait + 300);
if (! $lock->get()) {
    fwrite(STDERR, "A queued command holds {$device->name}'s lock; try again in a minute.\n");
    exit(1);
}

try {
    $step('before', $state());

    $p = new Probe($device->ip_address, (int) $device->port);
    $p->open();
    if (! $p->connect($commKey)) {
        throw new RuntimeException('CONNECT/AUTH refused; RESTART not sent.');
    }
    $r = $p->cmd(Command::RESTART);
    $sentAt = microtime(true);
    $step('RESTART sent', ['reply_command' => $r['command'] ?? null, 'reply_data' => isset($r['data']) ? bin2hex($r['data']) : null, 'no_reply' => $r === []]);
    $p->close();

    // 4. when does it answer again?
    $firstDown = null;
    $back = null;
    while (microtime(true) - $sentAt < $maxWait) {
        sleep(2);
        $q = new Probe($device->ip_address, (int) $device->port);
        $q->open();
        $q->setPollTimeout(1.0);
        $ok = false;
        try {
            $ok = $q->connect($commKey);
            if ($ok) {
                $q->disconnect();
            }
        } catch (Throwable) {
        }
        $q->close();
        $t = round(microtime(true) - $sentAt, 1);
        if (! $ok && $firstDown === null) {
            $firstDown = $t;
            $step('not answering', ['after_s' => $t]);
        }
        if ($ok && $firstDown !== null) {
            $back = $t;
            $step('answering again', ['after_s' => $t]);
            break;
        }
        if ($ok && $firstDown === null && $t > 20) {
            $step('never stopped answering', ['after_s' => $t]);
            break;
        }
    }
    if ($back === null && $firstDown !== null) {
        $step('NOT BACK within the wait', ['waited_s' => $maxWait]);
    } else {
        sleep(5); // let it finish starting its services before the reads
        $step('after', $state());
    }
} catch (Throwable $e) {
    $step('FAILED', ['error' => get_class($e).': '.$e->getMessage()]);
} finally {
    $lock->release();
    $trace['finished_at'] = gmdate('c');
    $dir = __DIR__.'/traces-'.gmdate('Y-m-d');
    @mkdir($dir, 0775, true);
    $n = 1;
    while (file_exists(sprintf('%s/restart-%02d.json', $dir, $n))) {
        $n++;
    }
    $file = sprintf('%s/restart-%02d.json', $dir, $n);
    file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    fwrite(STDOUT, "trace: {$file}\n");
}
