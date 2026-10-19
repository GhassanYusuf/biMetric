<?php

/*
 * READ-ONLY. Why did CardNo not stick on the two C3 panels?
 *
 * saveUser()+setUserCard() reported success, but an independent read shows
 * CardNo = 0 for PIN 781054761. This dumps the panel's own `user` row, the
 * catalogue's field list for that table, and a known-good user that DOES hold a
 * card, so the two can be compared field by field.
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
    echo "model: " . $panel->modelName() . "\n";

    $cat = $panel->catalogue();

    echo "\n-- catalogue fields for `user` --\n";
    $t = $cat->fields('user');
    echo "  " . (is_array($t) ? implode(', ', array_keys($t)) : json_encode($t)) . "\n";

    echo "\n-- our row, PIN {$PIN} --\n";
    foreach ($panel->readTable('user') as $r) {
        if ((string) ($r['Pin'] ?? '') === $PIN) {
            foreach ($r as $k => $v) {
                printf("   %-18s %s\n", $k, var_export($v, true));
            }
        }
    }

    echo "\n-- a user that DOES hold a card (first 3 found) --\n";
    $shown = 0;
    foreach ($panel->readTable('user') as $r) {
        if ((int) ($r['CardNo'] ?? 0) > 0 && (string) ($r['Pin'] ?? '') !== $PIN) {
            printf("   Pin=%-11s UID=%-6s CardNo=%-12s Name=%s\n",
                $r['Pin'] ?? '?', $r['UID'] ?? '?', $r['CardNo'] ?? '?', $r['Name'] ?? '?');
            if (++$shown >= 3) {
                break;
            }
        }
    }

    echo "\n-- is card 7458281 anywhere on this panel? --\n";
    $hit = null;
    foreach ($panel->readTable('user') as $r) {
        if ((int) ($r['CardNo'] ?? 0) === 7458281) {
            $hit = $r;
        }
    }
    echo $hit === null
        ? "   no\n"
        : sprintf("   yes: Pin=%s UID=%s Name=%s\n", $hit['Pin'] ?? '?', $hit['UID'] ?? '?', $hit['Name'] ?? '?');

    echo "\n-- CardNo field values across the panel (sanity) --\n";
    $vals = [];
    foreach ($panel->readTable('user') as $r) {
        $vals[(string) ($r['CardNo'] ?? '')][] = $r['Pin'] ?? '?';
    }
    $nonZero = array_filter($vals, fn ($k) => $k !== '0' && $k !== '');
    printf("   distinct CardNo values: %d total, %d non-zero\n", count($vals), count($nonZero));
    $sample = array_slice($nonZero, 0, 5, true);
    foreach ($sample as $k => $p) {
        printf("   CardNo=%-12s pins=%s\n", $k, implode(',', array_slice($p, 0, 3)));
    }

    $panel->close();
}
