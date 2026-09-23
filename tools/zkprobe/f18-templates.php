<?php

/*
 * Fingerprint template BLOB read — ZKTeco F18 and F22, standalone binary protocol.
 *
 * BLAST RADIUS: READ-ONLY. Sends CMD_DB_RRQ (7) with FCT_FINGERTMP and CMD_USERTEMP_RRQ
 * (9) — the same two reads the app already performs on every enrollment sync. Nothing
 * is written, no capture mode is entered, no record is deleted. Safe to run while
 * people are clocking in.
 *
 * ---------------------------------------------------------------------------------
 * THE QUESTION
 * ---------------------------------------------------------------------------------
 * The owner's observation, 2026-09-15: with ZKAccess they enrol a finger on ONE
 * terminal and push it to the others. If that is what happened here, the same person's
 * template is byte-identical on several devices — which would identify duplicated
 * employee records far more reliably than a name ever could.
 *
 * So: can we read the template bytes at all, and do they match across terminals?
 *
 * ---------------------------------------------------------------------------------
 * WHAT RESEARCH SETTLED BEFORE ANY PACKET WAS SENT (rule 17)
 * ---------------------------------------------------------------------------------
 * Nothing new had to be reverse-engineered. The answer was already in this repository:
 *
 *   TemplateCodec's docblock records the layout, confirmed against a 22,066-byte
 *   capture from this very device on 2026-09-02:
 *
 *     [uint32 total length]
 *     then records of
 *     [uint16 record length][uint16 uid][uint8 slot][uint8 valid][TEMPLATE BLOB]
 *
 * The blob is the remainder of the record, and decode() was stepping straight over it
 * to return {uid, fid}. `template.download` is declared not_implemented — but that is
 * about the SEPARATE per-slot command and, more importantly, about writing a template
 * BACK, which remains unverified. Reading was never blocked; it was assumed to be.
 *
 * References consulted:
 *   https://github.com/adrobinoga/zk-protocol   — standalone command set, CMD_DB_RRQ
 *   https://github.com/fananimi/pyzk            — get_templates / FingerTemplate layout
 *
 * ---------------------------------------------------------------------------------
 * CONTROL (rule 16)
 * ---------------------------------------------------------------------------------
 * Every run reads the F18 at 192.168.7.62 as well as the subject, in the same session
 * and minutes apart. The F18 is the reference path; if IT shows a symptom, the finding
 * is about the network or this script, not about the subject device.
 *
 * A template blob is BIOMETRIC DATA. The trace records sizes and SHA-1 digests only —
 * never the bytes (rule 16: commit the decoded structure, never the payload).
 *
 * Usage:  php tools/zkprobe/f18-templates.php [device-id ...]
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Support\Tenancy;
use Promoseven\Biometric\Client\Device as ProtocolDevice;

Tenancy::spanAllTenants();

$ids = array_slice($argv, 1);
$devices = Device::query()
    ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
    ->where('protocol', Device::PROTOCOL_BINARY)
    ->where('is_active', true)
    ->orderBy('id')
    ->get();

// The control is not optional and is not a flag.
if (! $devices->contains(fn (Device $d) => $d->ip_address === '192.168.7.62')) {
    $control = Device::query()->where('ip_address', '192.168.7.62')->first();
    if ($control) {
        $devices->push($control);
    }
}

$driver = app(App\Contracts\BiometricDeviceDriver::class);
$startedAt = now()->toIso8601String();
$trace = ['label' => 'f18-templates', 'host' => 'multiple', 'started_at' => $startedAt, 'devices' => []];

foreach ($devices as $device) {
    fwrite(STDERR, "\n=== {$device->name} ({$device->ip_address}, {$device->model})\n");

    $entry = [
        'device_id' => $device->id,
        'name' => $device->name,
        'host' => $device->ip_address,
        'model' => $device->model,
        'is_control' => $device->ip_address === '192.168.7.62',
        'read_at' => now()->toIso8601String(),
    ];

    try {
        $records = $driver->run($device, fn (ProtocolDevice $d) => $d->templates()->allWithTemplates());

        $entry['records'] = count($records);
        $entry['template_bytes'] = array_sum(array_column($records, 'size'));
        // DIGESTS ONLY. The blobs themselves never reach the trace file.
        $entry['templates'] = array_map(static fn (array $r) => [
            'user_id' => $r['user_id'],
            'uid' => $r['uid'],
            'slot' => $r['fid'],
            'size' => $r['size'],
            'sha1' => $r['sha1'],
        ], $records);

        fwrite(STDERR, sprintf("    %d template(s), %d bytes\n", count($records), $entry['template_bytes']));
    } catch (\Throwable $e) {
        // A device that will not answer is evidence about nothing (rule 9).
        $entry['error'] = get_class($e) . ': ' . $e->getMessage();
        fwrite(STDERR, "    FAILED: {$e->getMessage()}\n");
    }

    $trace['devices'][] = $entry;
}

/* ---- cross-device comparison: the whole point of the run ---- */
$byHash = [];
foreach ($trace['devices'] as $d) {
    foreach ($d['templates'] ?? [] as $t) {
        $byHash[$t['sha1']][] = ['device' => $d['name'], 'user_id' => $t['user_id'], 'slot' => $t['slot']];
    }
}
$shared = array_filter($byHash, static fn (array $hits) => count(array_unique(array_column($hits, 'device'))) > 1);

$trace['comparison'] = [
    'distinct_templates' => count($byHash),
    'templates_on_more_than_one_device' => count($shared),
    'matches' => array_values(array_map(static fn ($hits) => $hits, $shared)),
];

fwrite(STDERR, sprintf(
    "\n=== %d distinct template(s); %d appear on MORE THAN ONE terminal\n",
    count($byHash),
    count($shared),
));

$dir = __DIR__ . '/traces-' . date('Y-m-d');
@mkdir($dir, 0775, true);
$n = 1;
while (file_exists($path = sprintf('%s/f18-templates-%02d.json', $dir, $n))) {
    $n++;
}
file_put_contents($path, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, "trace: {$path}\n");
