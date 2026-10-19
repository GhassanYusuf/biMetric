<?php

/*
 * READ-ONLY. PRE-MERGE AUDIT for Abdul Haq: fold #1727 "Abdul" into #1428 "Abdul Haq".
 *
 * Contacts devices only through DeviceDriverManager::readUsers() and selects from
 * SQL. Writes no row and deletes nothing; the only file it creates is its own
 * JSON snapshot, which is the rollback material.
 */

require '/var/www/zkt/vendor/autoload.php';
$app = require_once '/var/www/zkt/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Services\Devices\DeviceDriverManager;
use Illuminate\Support\Facades\DB;

$KEEP = 1428;
$MERGE = [1727];

echo "=== employees ===\n";
foreach (DB::table('employees')->whereIn('id', [$KEEP, ...$MERGE])->get() as $e) {
    printf("#%d  %-14s  code=%-14s card=%-10s dept=%s  tenant=%d  created=%s\n",
        $e->id, $e->name, $e->employee_code,
        var_export($e->card_number, true), var_export($e->department, true),
        $e->tenant_id, $e->created_at);
}

echo "\n=== rows that the merge will re-point or move ===\n";
foreach (['employee_device_maps', 'attendance_logs', 'biometric_templates', 'access_cards',
          'enrollment_sessions', 'attendance_waivers'] as $t) {
    $k = DB::table($t)->where('employee_id', $KEEP)->count();
    $m = DB::table($t)->whereIn('employee_id', $MERGE)->count();
    printf("%-24s keep=%-5d moving=%-5d\n", $t, $k, $m);
}
$r = DB::table('device_roster_entries')->whereIn('claimed_employee_id', $MERGE)->count();
printf("%-24s keep=%-5d moving=%-5d\n", 'device_roster_entries(claims)',
    DB::table('device_roster_entries')->where('claimed_employee_id', $KEEP)->count(), $r);

echo "\n=== live device mappings, both employees ===\n";
$maps = DB::table('employee_device_maps')
    ->whereIn('employee_id', [$KEEP, ...$MERGE])
    ->whereNull('valid_to')
    ->orderBy('device_id')->orderBy('id')->get();
foreach ($maps as $m) {
    $d = DB::table('devices')->where('id', $m->device_id)->first();
    printf("map#%-6d emp#%-6d dev#%-3d %-14s %-10s pin=%-11s uid=%-5s card=%-10s synced=%s\n",
        $m->id, $m->employee_id, $m->device_id, $d->name ?? '?', $d->protocol ?? '?',
        $m->device_user_id, $m->device_uid, var_export($m->card_number, true), $m->synced_at);
}

echo "\n=== devices ===\n";
foreach (DB::table('devices')->orderBy('id')->get() as $d) {
    printf("#%-3d %-14s %-10s %-16s active=%d adopted=%-9s writable=%d\n",
        $d->id, $d->name, $d->protocol, $d->ip_address, $d->is_active, $d->adoption_status,
        $d->adoption_status === 'adopted' ? 1 : 0);
}

echo "\n=== HARDWARE re-check: is PIN 781054761 actually on the panels? ===\n";
$pin = '781054761';
$manager = app(DeviceDriverManager::class);
$panelState = [];
foreach ([13, 14, 15] as $id) {
    $dev = Device::find($id);
    try {
        $users = $manager->readUsers($dev, false);
        $hit = null;
        foreach ($users as $u) {
            if ((string) $u->userId === $pin) {
                $hit = $u;
                break;
            }
        }
        $panelState[$id] = $hit !== null;
        printf("#%-3d %-10s users=%-5d PIN %s => %s\n", $id, $dev->name, count($users), $pin,
            $hit ? 'PRESENT uid=' . $hit->uid : 'ABSENT');
    } catch (\Throwable $e) {
        $panelState[$id] = null;
        printf("#%-3d %-10s READ FAILED: %s\n", $id, $dev->name, $e->getMessage());
    }
}

echo "\n=== snapshot ===\n";
$ids = [$KEEP, ...$MERGE];
$snap = ['at' => now()->toIso8601String(), 'keep' => $KEEP, 'merge' => $MERGE,
         'pin_on_hardware' => $panelState, 'tables' => []];

$snap['tables']['employees'] = DB::table('employees')->whereIn('id', $ids)->get()->all();
foreach (['employee_device_maps', 'attendance_logs', 'biometric_templates', 'access_cards',
          'enrollment_sessions', 'attendance_waivers'] as $t) {
    $snap['tables'][$t] = DB::table($t)->whereIn('employee_id', $ids)->get()->all();
}
$snap['tables']['device_roster_entries'] = DB::table('device_roster_entries')
    ->whereIn('claimed_employee_id', $ids)->get()->all();
$snap['tables']['audit_logs'] = DB::table('audit_logs')
    ->where('auditable_type', 'like', '%Employee')
    ->whereIn('auditable_id', $ids)->get()->all();

$dir = '/var/www/zkt/storage/app/private/uid-repairs';
@mkdir($dir, 0775, true);
$path = $dir . '/pre-merge-abdul-' . date('Ymd-His') . '.json';
file_put_contents($path, json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "wrote $path\n";
$total = 0;
foreach ($snap['tables'] as $t => $rows) {
    printf("  %-24s %d rows\n", $t, count($rows));
    $total += count($rows);
}
echo "total rows captured: $total\n";
