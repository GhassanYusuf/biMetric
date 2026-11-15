<?php

/*
 * Does library 2.24.0's window read (BulkReader::awaitLateReply + readNumberedWindow, casebook
 * DC-047) read every punch whole, on the device it was written for and on the ones it must not move?
 *
 * Loads the library from a SOURCE checkout given on the command line, ahead of the installed
 * copy in vendor/, so the change is exercised on real terminals before it is released and
 * installed (rule 15: shared transport code re-confirmed on the F18 before it lands; rule 21:
 * the F22 and UA300 checked too). Production keeps running the installed version meanwhile.
 *
 * BLAST RADIUS: READ-ONLY. Per read: CONNECT (+AUTH), PREPARE_BUFFER of the punch table,
 * READ_BUFFER by offset, FREE_DATA (releases the copy the device staged for us), EXIT —
 * ZktecoDriver::readAttendance, the scheduled sync's own read — holding the queue's per-device
 * lock (rule 11). Nothing is written to a terminal or to the database.
 *
 * The trace holds counts, timings and the newest decoded date only — no user ids.
 *
 * Usage:  php tools/zkprobe/numbered-window-check.php <library-src-dir> <device-id>:<reads> ...
 *   e.g.  php tools/zkprobe/numbered-window-check.php /var/biometric-protocol/src 26:6 15:2 11:1 17:1
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$src = rtrim((string) ($argv[1] ?? ''), '/');
if (! is_file("{$src}/Transport/BulkReader.php")) {
    fwrite(STDERR, "Usage: php tools/zkprobe/numbered-window-check.php <library-src-dir> <device-id>:<reads> ...\n");
    exit(2);
}
// Registered after composer's loader, prepended: the source checkout wins for library classes.
spl_autoload_register(function (string $class) use ($src): void {
    if (str_starts_with($class, 'Promoseven\\Biometric\\')) {
        $file = $src.'/'.str_replace('\\', '/', substr($class, strlen('Promoseven\\Biometric\\'))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
}, true, true);

use App\Contracts\BiometricDeviceDriver;
use App\Jobs\ProcessDeviceCommand;
use App\Models\Device;
use App\Support\Tenancy;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Promoseven\Biometric\Transport\BulkReader;

$loadedFrom = (new ReflectionClass(BulkReader::class))->getFileName();
$numbered = method_exists(BulkReader::class, 'readNumberedWindow')
    || (new ReflectionClass(BulkReader::class))->hasMethod('readNumberedWindow');
echo "BulkReader from {$loadedFrom}".($numbered ? ' (numbered windows)' : ' (OLD: no numbered windows)')."\n";

$plan = [];
foreach (array_slice($argv, 2) as $arg) {
    [$id, $reads] = array_pad(explode(':', $arg, 2), 2, '1');
    $plan[] = [(int) $id, max(1, (int) $reads)];
}

$trace = [
    'label' => 'numbered-window-check',
    'host' => '',
    'started_at' => gmdate('c'),
    'question' => 'Does the numbered window read (library 2.24.0) decode every punch, on every model?',
    'library_loaded_from' => $loadedFrom,
    'numbered_windows' => $numbered,
    'runs' => [],
];
$hosts = [];

foreach ($plan as [$id, $reads]) {
    $device = Device::withoutGlobalScopes()->find($id);
    if ($device === null) {
        continue;
    }
    $hosts[] = "{$device->ip_address}:{$device->port}";
    for ($n = 1; $n <= $reads; $n++) {
        $entry = ['id' => $id, 'name' => $device->name, 'model' => $device->model, 'read' => $n, 'started_at' => gmdate('c')];
        $lock = Cache::lock((new WithoutOverlapping("device:{$id}"))->getLockKey(new ProcessDeviceCommand(0)), 900);
        if (! $lock->get()) {
            $entry['error'] = 'queue lock busy; skipped';
        } else {
            $t0 = microtime(true);
            try {
                Tenancy::set($device->tenant_id);
                $records = app(BiometricDeviceDriver::class)->readAttendance($device);
                $decoded = array_filter($records, fn ($r) => isset($r['timestamp']));
                $newest = null;
                foreach ($decoded as $r) {
                    $newest = max($newest, $r['timestamp']->format('Y-m-d H:i'));
                }
                $entry += ['records' => count($records), 'decoded' => count($decoded),
                    'undecodable' => count($records) - count($decoded), 'newest_decoded' => $newest];
            } catch (Throwable $e) {
                $entry['error'] = get_class($e).': '.mb_scrub($e->getMessage(), 'UTF-8');
            } finally {
                $entry['seconds'] = round(microtime(true) - $t0, 1);
                Tenancy::clear();
                $lock->release();
            }
        }
        $trace['runs'][] = $entry;
        printf("%-14s %-10s #%d %6ss records=%-6s decoded=%-6s undecodable=%-6s newest=%s %s\n",
            $device->name, $device->model, $n, $entry['seconds'] ?? '-', $entry['records'] ?? '-',
            $entry['decoded'] ?? '-', $entry['undecodable'] ?? '-', $entry['newest_decoded'] ?? '-', $entry['error'] ?? '');
    }
}

$trace['host'] = implode(',', array_unique($hosts));
$trace['finished_at'] = gmdate('c');
$dir = __DIR__.'/traces-'.gmdate('Y-m-d');
@mkdir($dir, 0775, true);
$k = 1;
while (file_exists(sprintf('%s/numbered-window-check-%02d.json', $dir, $k))) {
    $k++;
}
$file = sprintf('%s/numbered-window-check-%02d.json', $dir, $k);
file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "trace: {$file}\n";
