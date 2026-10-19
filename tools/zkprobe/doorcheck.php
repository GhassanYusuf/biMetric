<?php

/*
 * READ-ONLY. Does Abdul Haq actually have door access on the two C3 panels?
 *
 * The `user` row is written; that only identifies him. Whether he can open a
 * door is the `userauthorize` table. Personnel::add() defaults new people to
 * commonAuthorization() — "what most people on this panel have" — which on a
 * 4-door inBIO460 may well be one door only. This reports exactly what he was
 * granted, against what other people hold.
 *
 * No device writes.
 */

require '/var/www/zkt/vendor/autoload.php';
$app = require_once '/var/www/zkt/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use Promoseven\Biometric\C3\Client\Panel;
use Promoseven\Biometric\Value\DeviceConfig;

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

$PIN = '781054761';

foreach ([13 => 'Glass Door', 14 => 'Entrance'] as $id => $label) {
    $dev = Device::find($id);
    $panel = panelFor($dev);
    $panel->open();

    echo "\n=========== #{$id} {$label} ({$dev->ip_address}) ===========\n";

    if (! $panel->catalogue()->has('userauthorize')) {
        echo "  no userauthorize table on this panel\n";
        $panel->close();
        continue;
    }

    $mine = [];
    $tally = [];
    foreach ($panel->readTable('userauthorize') as $r) {
        $pair = [(int) $r['AuthorizeDoorId'], (int) $r['AuthorizeTimezoneId']];
        if ((string) ($r['Pin'] ?? '') === $PIN) {
            $mine[] = $pair;
        }
        $k = json_encode($pair);
        $tally[$k] = ($tally[$k] ?? 0) + 1;
    }

    printf("  Abdul Haq (Pin %s) holds %d userauthorize row(s)\n", $PIN, count($mine));
    if ($mine === []) {
        echo "    *** NONE — he cannot open any door on this panel ***\n";
    }
    foreach ($mine as [$door, $tz]) {
        printf("    door=%d timezone=%d\n", $door, $tz);
    }

    arsort($tally);
    echo "\n  what everyone else on this panel has:\n";
    $n = 0;
    foreach ($tally as $k => $c) {
        [$door, $tz] = json_decode($k, true);
        printf("    door=%-3d timezone=%-3d held by %d users\n", $door, $tz, $c);
        if (++$n >= 6) {
            break;
        }
    }

    $panel->close();
}
