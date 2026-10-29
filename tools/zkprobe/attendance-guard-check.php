<?php

/*
 * Would library 2.21.0's new attendance guard (a 40-byte record whose user id is not all
 * digits is undecodable) reject anything REAL? (casebook DC-036, rule 15: a change to the
 * shared decoder is re-confirmed against the F18 before it lands; rule 21: and the other
 * models must not move.)
 *
 * BLAST RADIUS: READ-ONLY. Per terminal: the same attendance read the scheduled sync makes
 * (ZktecoDriver::readAttendance: CONNECT, bulk read of the punch table, EXIT), while holding
 * the queue's per-device lock (rule 11). Nothing is written to the terminal or to the
 * database. The punches are decoded with the INSTALLED decoder; the new rule is then
 * applied to what it decoded, which is exactly the change (2.21.0 adds one check after the
 * existing ones).
 *
 * The trace holds counts only. For a record the new rule would reject, its user id is kept
 * as hex (at most 20): a real id there would be the finding, and junk is not personal data.
 *
 * Usage:  php tools/zkprobe/attendance-guard-check.php <device-id> [device-id ...]
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

$ids = array_map('intval', array_slice($argv, 1));
$devices = Device::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->get();
if ($devices->isEmpty()) {
    fwrite(STDERR, "Usage: php tools/zkprobe/attendance-guard-check.php <device-id> [device-id ...]\n");
    exit(2);
}

$trace = [
    'label' => 'attendance-guard-check',
    'host' => $devices->pluck('ip_address')->implode(','),
    'started_at' => gmdate('c'),
    'question' => 'Does "user id must be all digits" (2.21.0) reject any record the installed decoder accepts?',
    'devices' => [],
];

foreach ($devices as $device) {
    $entry = ['id' => $device->id, 'name' => $device->name, 'model' => $device->model, 'host' => $device->ip_address];
    $lock = Cache::lock((new WithoutOverlapping("device:{$device->id}"))->getLockKey(new ProcessDeviceCommand(0)), 600);
    if (! $lock->get()) {
        $entry['error'] = 'queue lock busy; skipped';
        $trace['devices'][] = $entry;
        continue;
    }
    try {
        Tenancy::set($device->tenant_id);
        $records = app(BiometricDeviceDriver::class)->readAttendance($device);
        $decoded = array_filter($records, fn ($r) => isset($r['timestamp']));
        $rejected = array_filter($decoded, fn ($r) => ! ctype_digit((string) $r['user_id']));
        $entry += [
            'records' => count($records),
            'decoded' => count($decoded),
            'undecodable' => count($records) - count($decoded),
            'new_rule_rejects' => count($rejected),
            'rejected_user_ids_hex' => array_values(array_map(
                fn ($r) => bin2hex((string) $r['user_id']).' @ '.$r['timestamp']->format('Y-m-d H:i:s'),
                array_slice($rejected, 0, 20),
            )),
        ];
    } catch (Throwable $e) {
        $entry['error'] = get_class($e).': '.mb_scrub($e->getMessage(), 'UTF-8');
    } finally {
        Tenancy::clear();
        $lock->release();
    }
    $trace['devices'][] = $entry;
    printf("%-12s %-10s records=%-6s decoded=%-6s undecodable=%-6s new_rule_rejects=%s %s\n",
        $device->name, $device->model, $entry['records'] ?? '-', $entry['decoded'] ?? '-',
        $entry['undecodable'] ?? '-', $entry['new_rule_rejects'] ?? '-', $entry['error'] ?? '');
}

$trace['finished_at'] = gmdate('c');
$dir = __DIR__.'/traces-'.gmdate('Y-m-d');
@mkdir($dir, 0775, true);
$n = 1;
while (file_exists(sprintf('%s/attendance-guard-check-%02d.json', $dir, $n))) {
    $n++;
}
$file = sprintf('%s/attendance-guard-check-%02d.json', $dir, $n);
file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "trace: {$file}\n";
