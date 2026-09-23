<?php

/*
 * C3 `templatev10` — how is the blob field actually framed?
 *
 * BLAST RADIUS: READ-ONLY. One DATATABLE_CFG and one GETDATA, the same two reads
 * tools/zkprobe/c3-templatev10.php performs. Nothing written.
 *
 * WHAT THE PREVIOUS RUN ESTABLISHED (traces-2026-09-15/c3-templatev10-01.json):
 * GETDATA on templatev10 SUCCEEDS — 345 rows staged and pulled in ~1s from both
 * panels. The first record decodes perfectly:
 *
 *     Size=878  UID=1  Pin=10  FingerID=3  Valid=1  Template=<110 bytes>
 *
 * 878 is exactly the length of badge 10 / finger 3 in the vendor's own BioTemplate
 * table, so the fixed fields are right and the table is the one we want. But every
 * record after the first is nonsense, because RecordCodec reads ONE length byte per
 * field and a 878-byte blob cannot be expressed in it — alignment is lost inside
 * record 1 and never recovers.
 *
 * THE HYPOTHESIS THIS RUN TESTS: the `Size=i1` field exists precisely because the
 * per-field length byte cannot carry the template, so the Template field is read for
 * `Size` bytes rather than for the byte that precedes it.
 *
 * It is tested by ALIGNMENT, not by looking at template content: if the hypothesis is
 * right, walking the stream consumes it exactly, every record yields a plausible Pin
 * and FingerID (0-9), and the row count matches. If it is wrong, the walk overruns.
 *
 * NO TEMPLATE BYTES ARE PRINTED OR WRITTEN. Sizes, offsets and digests only.
 *
 * Usage:  php tools/zkprobe/c3-templatev10-framing.php [device-id]
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Support\Tenancy;
use Promoseven\Biometric\C3\Client\Panel;

Tenancy::spanAllTenants();

$ids = array_slice($argv, 1);
$devices = Device::query()->where('protocol', Device::PROTOCOL_C3)
    ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))->orderBy('id')->get();

$driver = app(App\Services\Devices\C3\C3Driver::class);
$trace = ['label' => 'c3-templatev10-framing', 'host' => 'multiple', 'started_at' => now()->toIso8601String(), 'devices' => []];

/**
 * Walks the stream assuming the Template field is `Size` bytes long.
 *
 * @return array{rows:list<array<string,int|string>>, consumed:int, total:int, failed_at:?int}
 */
function walk(string $payload, int $tableIndex, array $order): array
{
    $len = strlen($payload);
    $pos = 0;

    // An inline reply repeats the table index and field list; a staged one may not.
    if ($len > 2 && ord($payload[0]) === $tableIndex && ord($payload[1]) === count($order)) {
        $order = array_values(unpack('C*', substr($payload, 2, count($order))));
        $pos = 2 + count($order);
    }

    $names = [1 => 'Size', 2 => 'UID', 3 => 'Pin', 4 => 'FingerID', 5 => 'Valid', 6 => 'Template', 7 => 'Resverd', 8 => 'EndTag'];
    $rows = [];

    while ($pos < $len) {
        $row = [];
        $ok = true;

        foreach ($order as $idx) {
            if ($pos >= $len) { $ok = false; break; }

            $name = $names[$idx] ?? "f{$idx}";

            if ($name === 'Template') {
                // THE HYPOTHESIS: length comes from the Size field, not from a prefix.
                $size = (int) ($row['Size'] ?? 0);
                if ($size <= 0 || $pos + $size > $len) { $ok = false; break; }
                $blob = substr($payload, $pos, $size);
                $pos += $size;
                $row['Template'] = strlen($blob);
                $row['sha1'] = sha1($blob);   // digest only; bytes are discarded here
                continue;
            }

            $width = ord($payload[$pos++]);
            if ($pos + $width > $len) { $ok = false; break; }
            $raw = substr($payload, $pos, $width);
            $pos += $width;
            $v = 0;
            foreach (array_reverse(str_split($raw)) as $b) { $v = ($v << 8) | ord($b); }
            $row[$name] = $v;
        }

        if (! $ok) {
            return ['rows' => $rows, 'consumed' => $pos, 'total' => $len, 'failed_at' => count($rows)];
        }

        $rows[] = $row;
    }

    return ['rows' => $rows, 'consumed' => $pos, 'total' => $len, 'failed_at' => null];
}

foreach ($devices as $device) {
    fwrite(STDERR, "\n=== {$device->name} ({$device->ip_address}, {$device->model})\n");
    $entry = ['device_id' => $device->id, 'name' => $device->name, 'host' => $device->ip_address, 'model' => $device->model];

    try {
        $m = (new ReflectionClass($driver))->getMethod('panelFor');
        $m->setAccessible(true);
        /** @var Panel $p */
        $p = $m->invoke($driver, $device);
        $p->open();

        try {
            $cat = $p->catalogue();
            $idx = $cat->indexOf('templatev10');
            $fieldIdx = $cat->fieldIndexes('templatev10');

            $raw = $p->readTableRaw('templatev10');
            $entry['payload_bytes'] = strlen($raw);

            $out = walk($raw, $idx, $fieldIdx);
            $entry['rows'] = count($out['rows']);
            $entry['consumed'] = $out['consumed'];
            $entry['total'] = $out['total'];
            $entry['exact'] = $out['consumed'] === $out['total'];
            $entry['failed_at_record'] = $out['failed_at'];

            $pins = array_column($out['rows'], 'Pin');
            $fingers = array_column($out['rows'], 'FingerID');
            $sizes = array_map(fn ($r) => $r['Template'] ?? 0, $out['rows']);

            $entry['sane'] = [
                'distinct_pins' => count(array_unique($pins)),
                'finger_ids' => array_values(array_unique($fingers)),
                'template_size_min' => $sizes ? min($sizes) : null,
                'template_size_max' => $sizes ? max($sizes) : null,
                'distinct_digests' => count(array_unique(array_map(fn ($r) => $r['sha1'] ?? '', $out['rows']))),
            ];
            // Digests only, so the trace can be committed as evidence.
            $entry['digests'] = array_map(
                fn ($r) => ['pin' => $r['Pin'] ?? null, 'finger' => $r['FingerID'] ?? null,
                            'size' => $r['Template'] ?? null, 'sha1' => $r['sha1'] ?? null],
                $out['rows'],
            );

            fwrite(STDERR, sprintf(
                "    payload %d B -> %d record(s), consumed %d/%d%s\n",
                strlen($raw), count($out['rows']), $out['consumed'], $out['total'],
                $out['consumed'] === $out['total'] ? '  EXACT' : '  OVERRUN',
            ));
            fwrite(STDERR, sprintf("    distinct pins %d, finger ids %s, template %d-%d bytes\n",
                $entry['sane']['distinct_pins'], implode(',', $entry['sane']['finger_ids']),
                $entry['sane']['template_size_min'] ?? 0, $entry['sane']['template_size_max'] ?? 0));
        } finally {
            $p->close();
        }
    } catch (\Throwable $e) {
        $entry['error'] = get_class($e) . ': ' . $e->getMessage();
        fwrite(STDERR, "    FAILED: {$e->getMessage()}\n");
    }

    $trace['devices'][] = $entry;
}

$dir = __DIR__ . '/traces-' . date('Y-m-d');
@mkdir($dir, 0775, true);
$n = 1;
while (file_exists($path = sprintf('%s/c3-templatev10-framing-%02d.json', $dir, $n))) { $n++; }
file_put_contents($path, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, "\ntrace: {$path}\n");
