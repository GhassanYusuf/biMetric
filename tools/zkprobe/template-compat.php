<?php

/*
 * READ-ONLY. Can a fingerprint captured on the F18 (IT Room) be written onto the
 * C3 panels (Glass Door / Entrance)?
 *
 * The app stores no templates for Abdul Haq (biometric_templates is empty), so
 * the only copy of his fingers is on the F18 itself. Copying them across means:
 *   1. read the template off the F18
 *   2. write it to the C3 as a `templatev10` row
 *   3. verify byte for byte
 *
 * Step 2 is the open question: the F18 and the C3 both call their slot "v10",
 * but a matching label is not a matching wire format. This dumps both sides —
 * slot, size, and the first bytes — so they can be compared before anyone
 * writes a finger to a live door.
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
$manager = app(DeviceDriverManager::class);

echo "=== app-side vault: does the app hold any of his templates? ===\n";
$n = DB::table('biometric_templates')->where('employee_id', 1428)->count();
printf("  biometric_templates rows for #1428: %d\n", $n);
printf("  (total rows in table: %d)\n", DB::table('biometric_templates')->count());

echo "\n=== F18 IT Room (#15): what fingers does he have there? ===\n";
$f18 = Device::find(15);
try {
    $users = $manager->readUsers($f18, true);
    $hit = null;
    foreach ($users as $u) {
        if ((string) $u->userId === $PIN) {
            $hit = $u;
        }
    }
    if ($hit === null) {
        echo "  PIN {$PIN} not on the F18\n";
    } else {
        printf("  found: uid=%s name=%s card=%s\n", $hit->uid ?? '?', $hit->name ?? '?', $hit->cardNumber ?? '?');
        printf("  fingerSlots: %s\n", implode(', ', $hit->fingerSlots ?? []));
    }

    $raw = $manager->readTemplates($f18);
    printf("  readTemplates() returned %d entries\n", is_array($raw) ? count($raw) : -1);
    if (is_array($raw) && $raw !== []) {
        echo "  entry keys: " . implode(', ', array_keys(reset($raw))) . "\n";
        $byUid = [];
        foreach ($raw as $t) {
            $byUid[(int) ($t['uid'] ?? 0)][] = $t;
        }
        ksort($byUid);
        printf("  all %d entries grouped by uid:\n", count($raw));
        foreach ($byUid as $u => $ts) {
            $slots = array_map(fn ($t) => ($t['slot'] ?? '?') . '(' . strlen((string) ($t['template'] ?? '')) . ')', $ts);
            printf("    uid=%-4d %s\n", $u, implode(' ', $slots));
        }
        printf("\n  Abdul Haq is uid=%s on this F18.\n", $hit->uid ?? '?');
        $hisUid = (int) ($hit->uid ?? 0);
        printf("  templates held for his uid: %d\n", count($byUid[$hisUid] ?? []));
        foreach ($byUid[$hisUid] ?? [] as $t) {
            $blob = (string) ($t['template'] ?? '');
            printf("    slot=%-3s size=%-5d valid=%-3s head=%s\n",
                $t['slot'] ?? '?', strlen($blob), var_export($t['valid'] ?? null, true),
                strtoupper(bin2hex(substr($blob, 0, 32))));
        }
    }
} catch (\Throwable $e) {
    echo "  F18 READ FAILED: " . $e->getMessage() . "\n";
}

echo "\n=== C3 panels: what does an accepted template look like? ===\n";
foreach ([13 => 'Glass Door', 14 => 'Entrance'] as $id => $label) {
    $dev = Device::find($id);
    $panel = new Panel(
        new DeviceConfig(
            host: $dev->ip_address,
            port: $dev->port ?: 4370,
            commKey: (int) ($dev->comm_key ?? 0),
            timeout: (float) config('attendance.device_timeout', 10.0),
            timezone: $dev->timezone ?: config('attendance.device_timezone', '+03:00'),
        ),
        $dev->model,
    );
    $panel->open();

    echo "\n-- #{$id} {$label} --\n";
    if (! $panel->catalogue()->has('templatev10')) {
        echo "  no templatev10 table\n";
        $panel->close();
        continue;
    }
    echo "  templatev10 fields: " . json_encode($panel->catalogue()->fields('templatev10')) . "\n";

    $rows = $panel->readTable('templatev10');
    printf("  template rows on panel: %d\n", count($rows));

    $mine = array_values(array_filter($rows, fn ($r) => (string) ($r['Pin'] ?? '') === $PIN));
    printf("  rows for PIN %s: %d\n", $PIN, count($mine));
    foreach ($mine as $r) {
        $blob = (string) ($r['Template'] ?? '');
        printf("    FingerID=%-3s Valid=%-3s Size=%-5s len=%-5d head=%s\n",
            $r['FingerID'] ?? '?', $r['Valid'] ?? '?', $r['Size'] ?? '?', strlen($blob),
            strtoupper(bin2hex(substr($blob, 0, 32))));
    }

    echo "  sample of templates this panel already accepts:\n";
    $shown = 0;
    foreach ($rows as $r) {
        if ((string) ($r['Pin'] ?? '') === $PIN) {
            continue;
        }
        $blob = (string) ($r['Template'] ?? '');
        printf("    Pin=%-11s FingerID=%-3s Size=%-5s len=%-5d head=%s\n",
            $r['Pin'] ?? '?', $r['FingerID'] ?? '?', $r['Size'] ?? '?', strlen($blob),
            strtoupper(bin2hex(substr($blob, 0, 32))));
        if (++$shown >= 5) {
            break;
        }
    }

    $panel->close();
}
