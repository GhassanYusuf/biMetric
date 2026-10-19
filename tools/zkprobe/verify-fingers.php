<?php

/*
 * READ-ONLY. Ground-truth verification of Abdul Haq's fingerprints, read straight
 * from each panel's own template table rather than through the driver's
 * user-keyed digest view (which identifies F18 templates by uid, with user_id
 * NULL, so a PIN filter finds nothing there).
 *
 * C3 : templatev10 rows, filtered on Pin = 781054761.
 * F18: template entries, filtered on uid = 1 (his UID on that terminal).
 *
 * No device writes.
 */

require '/var/www/zkt/vendor/autoload.php';
$app = require_once '/var/www/zkt/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Services\Devices\DeviceDriverManager;
use Illuminate\Support\Facades\DB;
use Promoseven\Biometric\C3\Client\Panel;
use Promoseven\Biometric\Value\DeviceConfig;

$PIN = '781054761';
$EMP = 1428;

$vault = [];
foreach (DB::table('biometric_templates')->where('employee_id', $EMP)->get() as $t) {
    $vault[(int) $t->finger_slot] = (string) $t->template_sha1;
}
echo "vault expects: ";
foreach ($vault as $slot => $sha) {
    printf("finger %d sha1=%s  ", $slot, substr($sha, 0, 16));
}
echo "\n\n";

function panelFor(Device $device): Panel
{
    return new Panel(
        new DeviceConfig(
            host: $device->ip_address,
            port: $device->port ?: 4370,
            commKey: (int) ($device->comm_key ?? 0),
            timeout: (float) config('attendance.device_timeout', 10.0),
            timezone: $device->timezone ?: config('attendance.device_timezone', '+03:00'),
        ),
        $device->model,
    );
}

foreach ([13 => 'Glass Door', 14 => 'Entrance'] as $id => $label) {
    $dev = Device::find($id);
    $panel = panelFor($dev);
    $panel->open();

    echo "=== #{$id} {$label} ({$dev->ip_address}) ===\n";
    $rows = $panel->readTable('templatev10');
    printf("  templatev10 rows on panel: %d\n", count($rows));

    $mine = array_values(array_filter($rows, fn ($r) => (string) ($r['Pin'] ?? '') === $PIN));
    printf("  rows for Pin %s: %d\n", $PIN, count($mine));

    foreach ($mine as $r) {
        $blob = (string) ($r['Template'] ?? '');
        $slot = (int) ($r['FingerID'] ?? -1);
        $sha = sha1($blob);
        $want = $vault[$slot] ?? null;
        $verdict = $want === null
            ? 'no vault copy for this finger'
            : ($sha === $want ? 'BYTE-IDENTICAL to vault' : '*** DIFFERS from vault ***');
        printf("    FingerID=%-3d UID=%-5s Valid=%-3s Size=%-5s len=%-5d sha1=%s  %s\n",
            $slot, $r['UID'] ?? '?', $r['Valid'] ?? '?', $r['Size'] ?? '?', strlen($blob),
            substr($sha, 0, 16), $verdict);
        printf("       head=%s\n", strtoupper(bin2hex(substr($blob, 0, 32))));
    }
    if ($mine === []) {
        echo "  *** none ***\n";
    }
    $panel->close();
    echo "\n";
}

echo "=== #15 IT Room (source, uid 1) ===\n";
$f18 = Device::find(15);
$raw = app(DeviceDriverManager::class)->readTemplates($f18);
$his = array_values(array_filter($raw, fn ($t) => (int) ($t['uid'] ?? 0) === 1));
printf("  entries for uid 1: %d\n", count($his));
foreach ($his as $t) {
    $slot = (int) ($t['slot'] ?? 0);
    $sha = (string) ($t['sha1'] ?? sha1((string) $t['template']));
    printf("    slot=%-3d %5d bytes sha1=%s  %s\n", $slot, strlen((string) $t['template']),
        substr($sha, 0, 16), ($vault[$slot] ?? null) === $sha ? 'matches vault' : 'differs');
}
