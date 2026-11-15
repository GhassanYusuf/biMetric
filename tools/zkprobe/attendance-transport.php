<?php

/*
 * Why does gems workshop (F18, 95.84.64.159:4371, internet) decode only part of its punch
 * table? (casebook DC-047.) Its three full reads on 2026-11-15 read every record (16,274 /
 * 16,276) and decoded 48 %, 30 % and 33 %; the undecodable records are real punches read at a
 * shifted offset, and the shift differs between runs and within one. That points at the UDP
 * windowed read reassembling datagrams out of order, not at the terminal's table.
 *
 * This reads the punch table over UDP and then over TCP, the same read the scheduled sync makes,
 * and compares: how many records decode, and in which 16 KB windows (BulkReader::WINDOW_BYTES)
 * the undecodable ones sit. If the damage is window-shaped on UDP and absent on TCP, the cause
 * is the UDP reassembly.
 *
 * BLAST RADIUS: READ-ONLY. Per device and transport: CONNECT, the bulk read of the punch table
 * (PREPARE_BUFFER / READ_BUFFER / FREE_DATA), EXIT — ZktecoDriver::readAttendance, exactly what
 * the sync sends — while holding the queue's per-device lock (rule 11). Nothing is written to the
 * terminal or to the database.
 *
 * The trace holds counts, window numbers and the newest decoded date only — no user ids.
 *
 * Usage:  php tools/zkprobe/attendance-transport.php <device-id>:<udp|tcp>[,<udp|tcp>] ...
 *   e.g.  php tools/zkprobe/attendance-transport.php 15:udp 26:udp,tcp
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Contracts\BiometricDeviceDriver;
use App\Jobs\ProcessDeviceCommand;
use App\Models\Device;
use App\Support\Tenancy;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;

$plan = [];
foreach (array_slice($argv, 1) as $arg) {
    [$id, $transports] = array_pad(explode(':', $arg, 2), 2, 'udp');
    foreach (explode(',', $transports) as $t) {
        if (in_array($t, ['udp', 'tcp'], true)) {
            $plan[] = [(int) $id, $t];
        }
    }
}
if ($plan === []) {
    fwrite(STDERR, "Usage: php tools/zkprobe/attendance-transport.php <device-id>:<udp|tcp>[,...] ...\n");
    exit(2);
}

$devices = Device::withoutGlobalScopes()->whereIn('id', array_column($plan, 0))->get()->keyBy('id');
$trace = [
    'label' => 'attendance-transport',
    'host' => $devices->pluck('ip_address')->implode(','),
    'started_at' => gmdate('c'),
    'question' => 'Is the undecodable share of a full punch read caused by the UDP windowed reassembly?',
    'window_bytes' => 16384,
    'runs' => [],
];

foreach ($plan as [$id, $transport]) {
    $device = $devices[$id] ?? null;
    if ($device === null) {
        continue;
    }
    $entry = ['id' => $id, 'name' => $device->name, 'model' => $device->model,
        'host' => $device->ip_address.':'.$device->port, 'transport' => $transport, 'started_at' => gmdate('c')];
    $lock = Cache::lock((new WithoutOverlapping("device:{$id}"))->getLockKey(new ProcessDeviceCommand(0)), 900);
    if (! $lock->get()) {
        $entry['error'] = 'queue lock busy; skipped';
        $trace['runs'][] = $entry;
        echo "{$device->name} {$transport}: queue lock busy, skipped\n";

        continue;
    }
    $t0 = microtime(true);
    try {
        Tenancy::set($device->tenant_id);
        config(['attendance.device_transport' => $transport]);
        $records = app(BiometricDeviceDriver::class)->readAttendance($device);

        $bad = [];   // window number => undecodable records in it
        $newest = null;
        foreach (array_values($records) as $i => $r) {
            if (isset($r['timestamp'])) {
                $newest = max($newest, $r['timestamp']->format('Y-m-d H:i'));
            } else {
                $w = intdiv($i * 40, 16384);
                $bad[$w] = ($bad[$w] ?? 0) + 1;
            }
        }
        $decoded = count($records) - array_sum($bad);
        $entry += [
            'seconds' => round(microtime(true) - $t0, 1),
            'records' => count($records),
            'decoded' => $decoded,
            'undecodable' => array_sum($bad),
            'windows_total' => (int) ceil(count($records) * 40 / 16384),
            'windows_with_undecodable' => count($bad),
            'undecodable_by_window' => $bad,
            'newest_decoded' => $newest,
        ];
    } catch (Throwable $e) {
        $entry['seconds'] = round(microtime(true) - $t0, 1);
        $entry['error'] = get_class($e).': '.mb_scrub($e->getMessage(), 'UTF-8');
    } finally {
        Tenancy::clear();
        $lock->release();
    }
    $trace['runs'][] = $entry;
    printf("%-14s %-4s %6ss records=%-6s decoded=%-6s undecodable=%-6s bad windows=%s/%s newest=%s %s\n",
        $device->name, $transport, $entry['seconds'], $entry['records'] ?? '-', $entry['decoded'] ?? '-',
        $entry['undecodable'] ?? '-', $entry['windows_with_undecodable'] ?? '-', $entry['windows_total'] ?? '-',
        $entry['newest_decoded'] ?? '-', $entry['error'] ?? '');
}

$trace['finished_at'] = gmdate('c');
$dir = __DIR__.'/traces-'.gmdate('Y-m-d');
@mkdir($dir, 0775, true);
$n = 1;
while (file_exists(sprintf('%s/attendance-transport-%02d.json', $dir, $n))) {
    $n++;
}
$file = sprintf('%s/attendance-transport-%02d.json', $dir, $n);
file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "trace: {$file}\n";
