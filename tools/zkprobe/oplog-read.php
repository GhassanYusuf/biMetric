<?php

/*
 * When was City One Hotel's punch log emptied? (casebook DC-049.) Reads a terminal's OPERATION LOG and
 * finds its record layout from events whose times we already know (our restart of City One at
 * 2026-11-17 12:55:09 UTC, command 57760; clock settings by the nightly job).
 *
 * Research (rule 17): pyzk zk/const.py (github.com/fananimi/pyzk) — CMD_OPLOG_RRQ = 34, CMD_DB_RRQ = 7,
 * FCT_OPLOG = 4; openzk (kengggg.github.io/openzk/reference/protocol/) — the op log is FCT 4 read through
 * the buffered mechanism (PREPARE_BUFFER 1503 / READ_BUFFER 1504 / FREE_DATA 1502). No public source
 * gives the record layout; the vendor SDK's GetSuperLogData names its fields (admin, operation code,
 * time, four parameters), so this script finds the stride and the time field from the bytes, using the
 * same packed-time encoding as the attendance table.
 *
 * BLAST RADIUS: READ-ONLY. CONNECT (+AUTH), PREPARE_BUFFER for table 4 (DB_RRQ + FCT_OPLOG),
 * READ_BUFFER by offset, FREE_DATA (releases the copy the device staged for us), EXIT — the library's
 * BulkReader, the same path that reads the attendance and template tables. Nothing is written to the
 * terminal or the database. Holds the queue's per-device lock (rule 11).
 *
 * The trace holds the table size, the layout found and the decoded events (time, operation code, the
 * operator's user ID, parameters). An operation log holds no names, cards or fingerprints. The raw bytes
 * are printed to the terminal only (--hex), never kept.
 *
 * Usage:  php tools/zkprobe/oplog-read.php <device-id> [--hex] [--stride=16]
 *   --stride forces the record size (2026-11-17: Gems' table fits 32 as well as 16, and 32 hid half the events).
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ProcessDeviceCommand;
use App\Models\Device;
use App\Support\Tenancy;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Promoseven\Biometric\Codec\Timestamp;
use Promoseven\Biometric\Protocol\Command;

$device = Device::withoutGlobalScopes()->find((int) ($argv[1] ?? 0));
if ($device === null) {
    fwrite(STDERR, "Usage: php tools/zkprobe/oplog-read.php <device-id> [--hex]\n");
    exit(2);
}
$hex = in_array('--hex', $argv, true);
$tz = App\Support\Timezone::forDevice($device);

$lock = Cache::lock((new WithoutOverlapping("device:{$device->id}"))->getLockKey(new ProcessDeviceCommand(0)), 300);
if (! $lock->get()) {
    exit("{$device->name}: busy\n");
}
Tenancy::set($device->tenant_id);
try {
    // The library's own session and bulk reader: the path every table read takes.
    $raw = app(App\Contracts\BiometricDeviceDriver::class)->run($device, function ($d) {
        return (new Promoseven\Biometric\Transport\BulkReader($d->client()->session()))->read(Command::DB_RRQ, chr(4), "oplog");
    });
} finally {
    $lock->release();
    Tenancy::clear();
}

$size = strlen($raw);
echo "{$device->name}: operation log {$size} bytes\n";

// The table usually opens with a 4-byte length, as the attendance table does.
$body = $raw;
if ($size >= 4 && unpack('V', substr($raw, 0, 4))[1] === $size - 4) {
    $body = substr($raw, 4);
    echo "  starts with its own length (4 bytes), skipped\n";
}

/** A plausible packed ZKTeco time at $off: decodes to 2015..2030. */
$timeAt = function (string $rec, int $off) use ($tz): ?DateTimeImmutable {
    if ($off + 4 > strlen($rec)) {
        return null;
    }
    $t = Timestamp::decode(unpack('V', substr($rec, $off, 4))[1], $tz);

    return ($t !== null && (int) $t->format('Y') >= 2015 && (int) $t->format('Y') <= 2030) ? $t : null;
};

// Find stride and time offset: the pair under which (nearly) every record carries a plausible time,
// and the times run forward.
$best = null;
$forced = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--stride=')) {
        $forced = (int) substr($a, 9);
    }
}
foreach ($forced ? [$forced] : [8, 12, 14, 16, 18, 20, 24, 28, 32] as $stride) {
    if (strlen($body) % $stride !== 0 || strlen($body) < $stride) {
        continue;
    }
    $n = intdiv(strlen($body), $stride);
    for ($off = 0; $off <= $stride - 4; $off++) {
        $ok = 0;
        $forward = 0;
        $prev = null;
        for ($i = 0; $i < $n; $i++) {
            $t = $timeAt(substr($body, $i * $stride, $stride), $off);
            if ($t) {
                $ok++;
                if ($prev && $t >= $prev) {
                    $forward++;
                }
                $prev = $t;
            }
        }
        $score = $n ? ($ok / $n) + ($forward / max(1, $n - 1)) : 0;
        if ($best === null || $score > $best['score']) {
            $best = ['stride' => $stride, 'time_at' => $off, 'records' => $n, 'plausible' => $ok, 'forward' => $forward, 'score' => round($score, 3)];
        }
    }
}

$trace = [
    'label' => 'oplog-read',
    'host' => "{$device->ip_address}:{$device->port}",
    'device' => ['id' => $device->id, 'name' => $device->name, 'model' => $device->model],
    'started_at' => gmdate('c'),
    'question' => 'When was the punch log emptied? Operation log layout and its events.',
    'table_bytes' => $size,
    'layout' => $best,
    'events' => [],
];

if ($best === null) {
    echo "  no record size fits; nothing decoded\n";
} else {
    echo "  layout: {$best['records']} records of {$best['stride']} bytes, time at byte {$best['time_at']} ({$best['plausible']} plausible, {$best['forward']} in order)\n";
    for ($i = 0; $i < $best['records']; $i++) {
        $rec = substr($body, $i * $best['stride'], $best['stride']);
        $t = $timeAt($rec, $best['time_at']);
        // Everything that is not the time, as small integers, so the operation code and the
        // operator stand out against the known events.
        $other = substr($rec, 0, $best['time_at']).substr($rec, $best['time_at'] + 4);
        $event = [
            'n' => $i,
            'time' => $t?->format('Y-m-d H:i:s'),
            'bytes_before_time' => array_values(unpack('C*', substr($rec, 0, $best['time_at'])) ?: []),
            'words_after_time' => array_values(unpack('v*', str_pad(substr($rec, $best['time_at'] + 4), (int) ceil((strlen($rec) - $best['time_at'] - 4) / 2) * 2, "\0")) ?: []),
        ];
        $trace['events'][] = $event;
        printf("  #%-4d %s  before=%s  after=%s%s\n", $i, $event['time'] ?? '????-??-?? ??:??:??',
            json_encode($event['bytes_before_time']), json_encode($event['words_after_time']),
            $hex ? '  '.bin2hex($rec) : '');
    }
}

$trace['finished_at'] = gmdate('c');
$dir = __DIR__.'/traces-'.gmdate('Y-m-d');
@mkdir($dir, 0775, true);
$k = 1;
while (file_exists(sprintf('%s/oplog-read-%02d.json', $dir, $k))) {
    $k++;
}
$file = sprintf('%s/oplog-read-%02d.json', $dir, $k);
file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "trace: {$file}\n";
