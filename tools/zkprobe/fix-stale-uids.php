<?php

/*
 * Correct stale device_uid in employee_device_maps to match what the device
 * currently reports for that PIN.
 *
 * DEVICE BLAST RADIUS: none. READ-ONLY with respect to every terminal: the only
 * device call is DeviceDriverManager::readUsers(), and no write packet is built
 * or sent. WRITES TO THE DEVICE: never.
 *
 * It does UPDATE employee_device_maps.device_uid in SQL, so run --dry-run first;
 * it writes nothing at all in that mode. Every corrected row is appended to the
 * JSON backup, which is what makes the UPDATE reversible.
 *
 * SAFETY: device_uid is display-only. Every write path keys on the PIN
 * (device_user_id): DeviceUserSyncService:168, DeviceIdentityResolver::pinFor(),
 * and C3 Personnel::addFingerprints() re-reads the UID live from the panel.
 * Nothing reads device_uid to decide where to write, so this cannot mis-target
 * anything.
 *
 * Rows whose PIN is absent from the panel are NOT touched — a missing PIN is a
 * different problem and guessing a UID there would be inventing data.
 */

require '/var/www/zkt/vendor/autoload.php';
$app = require_once '/var/www/zkt/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Services\Devices\DeviceDriverManager;
use Illuminate\Support\Facades\DB;

$manager = app(DeviceDriverManager::class);
$dryRun = in_array('--dry-run', $argv, true);

$devices = [
    13 => 'Glass Door (inBIO460)',
    14 => 'Entrance (TF1700)',
    15 => 'IT ROOM (F18)',
];

$backup = [];
$plan = [];

foreach ($devices as $id => $label) {
    $d = Device::find($id);
    echo "=== {$label} :: {$d->ip_address} ({$d->protocol}) ===\n";

    $users = $manager->readUsers($d, false);
    $panel = [];
    foreach ($users as $u) {
        $panel[(string) $u->userId] = (int) $u->uid;
    }
    echo '  panel rows read: ' . count($panel) . "\n";

    $rows = DB::select(
        'SELECT id, employee_id, device_id, device_user_id, device_uid
         FROM employee_device_maps WHERE device_id = ? ORDER BY id',
        [$id]
    );

    $fix = 0; $keep = 0; $absent = [];
    foreach ($rows as $r) {
        $pin = (string) $r->device_user_id;
        $oldUid = $r->device_uid === null ? null : (int) $r->device_uid;

        if (! isset($panel[$pin])) {
            $absent[] = ['map_id' => $r->id, 'employee_id' => $r->employee_id, 'pin' => $pin, 'stale_uid' => $oldUid];
            continue;
        }
        $newUid = $panel[$pin];
        if ($oldUid === $newUid) { $keep++; continue; }

        $fix++;
        $plan[] = ['map_id' => $r->id, 'device_id' => $id, 'employee_id' => $r->employee_id,
                   'pin' => $pin, 'old_uid' => $oldUid, 'new_uid' => $newUid];
    }

    printf("  map rows: %d | already correct: %d | to correct: %d | PIN absent from panel: %d\n\n",
        count($rows), $keep, $fix, count($absent));

    if ($absent) {
        echo "  PINs on our books but NOT on this device (left untouched):\n";
        foreach ($absent as $a) {
            printf("    map#%-6s employee=%-6s pin=%-12s stale_uid=%s\n",
                $a['map_id'], $a['employee_id'], $a['pin'], var_export($a['stale_uid'], true));
        }
        echo "\n";
    }
}

echo "==================================================\n";
echo "TOTAL rows to correct: " . count($plan) . "\n\n";

if ($dryRun || $plan === []) {
    echo "DRY RUN — nothing written.\n";
    exit(0);
}

// ---- backup before touching anything ----
$stamp = date('Ymd-His');
$path = "/var/www/zkt/storage/app/private/uid-repairs/uid-fix-{$stamp}.json";
@mkdir(dirname($path), 0775, true);
file_put_contents($path, json_encode([
    'created_at' => date('c'),
    'reason' => 'device_uid corrected to match the PIN reported by the device',
    'rows' => $plan,
], JSON_PRETTY_PRINT));
echo "Backup written: {$path}\n\n";

$applied = 0;
DB::transaction(function () use ($plan, &$applied) {
    foreach ($plan as $p) {
        DB::table('employee_device_maps')
            ->where('id', $p['map_id'])
            ->where('device_uid', $p['old_uid'])   // optimistic: only if unchanged
            ->update(['device_uid' => $p['new_uid'], 'updated_at' => now()]);
        $applied++;
    }
});

echo "Applied {$applied} corrections.\n";
