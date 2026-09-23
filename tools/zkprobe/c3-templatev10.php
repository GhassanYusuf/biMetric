<?php

/*
 * C3 / PullSDK panel — can the `templatev10` table be read?
 *
 * BLAST RADIUS: READ-ONLY. Sends DATATABLE_CFG (0x06) to fetch the schema and
 * GETDATA (0x08) for one table. Both are reads; the panel's own vendor software
 * issues them continuously. Nothing is written, nothing is deleted.
 *
 * ---------------------------------------------------------------------------------
 * THE QUESTION
 * ---------------------------------------------------------------------------------
 * Glass Door (.61, inBIO460) and Entrance (.60, TF1700) each report `fingercount` 238
 * in the vendor's own Machines table, so the templates ARE on the hardware. This
 * driver reads their `user` and `transaction` tables but has never asked for
 * `templatev10`, and `readTemplateDigests()` throws on this family as a result.
 *
 * ---------------------------------------------------------------------------------
 * WHAT RESEARCH SETTLED FIRST (rule 17)
 * ---------------------------------------------------------------------------------
 * The schema is already captured, in
 * tests/Fixtures/c3-datatable-cfg-tf1700.txt, straight from the panel:
 *
 *   templatev10=10,Size=i1,UID=i2,Pin=i3,FingerID=i4,Valid=i5,Template=B6,Resverd=i7,EndTag=i8
 *
 * So the table exists, its index is 10, and field 6 is a blob. Nothing needs guessing
 * about WHAT to ask for.
 *
 * THE DOUBT WORTH STATING BEFORE SENDING ANYTHING: every field in a C3 record is
 * length-prefixed with ONE byte, so a field can carry at most 255 bytes — and a
 * fingerprint template is 700-1,600. Either the panel splits the blob, or it uses a
 * different framing for this table, or the explicit `Size=i1` field exists precisely
 * because the per-field length byte cannot express it. This run is to find out which,
 * by reading the refusal or the reply rather than predicting it (rule 16).
 *
 * References:
 *   https://github.com/vwout/zkaccess-c3-py    — C3 command set, RTDATA/GETDATA
 *   the panel's own DATATABLE_CFG reply         — the authority for the schema
 *
 * A template is BIOMETRIC DATA. This records sizes, offsets and digests only.
 *
 * Usage:  php tools/zkprobe/c3-templatev10.php [device-id ...]
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Support\Tenancy;
use Promoseven\Biometric\C3\Client\Panel;

Tenancy::spanAllTenants();

$ids = array_slice($argv, 1);
$devices = Device::query()
    ->where('protocol', Device::PROTOCOL_C3)
    ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
    ->orderBy('id')->get();

$trace = ['label' => 'c3-templatev10', 'host' => 'multiple', 'started_at' => now()->toIso8601String(), 'devices' => []];
$driver = app(App\Services\Devices\C3\C3Driver::class);

foreach ($devices as $device) {
    fwrite(STDERR, "\n=== {$device->name} ({$device->ip_address}, {$device->model})\n");
    $entry = ['device_id' => $device->id, 'name' => $device->name, 'host' => $device->ip_address, 'model' => $device->model];

    try {
        // Driven through the panel's PUBLIC surface, so what this proves is what the
        // app would actually get — not what a hand-rolled request might.
        $panel = (new ReflectionClass($driver))->getMethod('panelFor');
        $panel->setAccessible(true);
        /** @var Panel $p */
        $p = $panel->invoke($driver, $device);
        $p->open();

        try {
            $catalogue = $p->catalogue();
            $tables = $catalogue->tableNames();
            $entry['tables'] = $tables;
            fwrite(STDERR, '    tables: ' . implode(', ', $tables) . "\n");

            if (! in_array('templatev10', $tables, true)) {
                $entry['verdict'] = 'the panel does not publish a templatev10 table';
            } else {
                $entry['schema'] = $catalogue->fields('templatev10');
                $entry['table_index'] = $catalogue->indexOf('templatev10');
                $entry['field_indexes'] = $catalogue->fieldIndexes('templatev10');

                $started = microtime(true);
                $rows = $p->readTable('templatev10');
                $entry['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
                $entry['rows'] = count($rows);
                // Shape only — a template blob never reaches the trace.
                $entry['row_keys'] = $rows === [] ? [] : array_keys($rows[0]);
                $entry['sample'] = array_map(
                    static fn (array $r) => array_map(
                        static fn ($v) => is_string($v) && strlen($v) > 24 ? '<'.strlen($v).' bytes>' : $v,
                        $r,
                    ),
                    array_slice($rows, 0, 3),
                );

                $sizes = array_column($rows, 'Size');
                $entry['declared_sizes'] = $sizes === [] ? null
                    : ['min' => min($sizes), 'max' => max($sizes), 'sum' => array_sum($sizes)];

                fwrite(STDERR, sprintf("    templatev10 -> %d row(s) in %d ms\n", count($rows), $entry['elapsed_ms']));
                if ($rows !== []) {
                    fwrite(STDERR, '    fields: ' . implode(', ', array_keys($rows[0])) . "\n");
                }
            }
        } finally {
            $p->close();
        }

        $entry['ok'] = true;
    } catch (\Throwable $e) {
        // A refusal is the device talking to us (rule 17). Record it in full.
        $entry['error'] = get_class($e) . ': ' . $e->getMessage();
        fwrite(STDERR, "    FAILED: {$e->getMessage()}\n");
    }

    $trace['devices'][] = $entry;
}

$dir = __DIR__ . '/traces-' . date('Y-m-d');
@mkdir($dir, 0775, true);
$n = 1;
while (file_exists($path = sprintf('%s/c3-templatev10-%02d.json', $dir, $n))) {
    $n++;
}
file_put_contents($path, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, "\ntrace: {$path}\n");
