<?php

/*
 * Fold #1727 "Abdul" into #1428 "Abdul Haq".
 *
 * DEVICE BLAST RADIUS: none. WRITES TO THE DEVICE: never — this script opens no
 * terminal and sends no packet. It writes SQL only:
 *
 *   1. EmployeeMergeService::merge(1428, [1727]) — DB only, no device contact.
 *      Moves 2 device maps, 9 attendance rows, 2 roster claims onto #1428,
 *      deletes #1727. mappings_closed = 0: the two records share no device,
 *      so nothing competes.
 *   2. Set employees.card_number = 7458281 on #1428. Verified present on
 *      IT Room hardware (uid 1) and held by no other employee.
 *   3. Close the two C3 maps, because a hardware read on 2026-10-05 10:45
 *      proved PIN 781054761 is ABSENT from Glass Door and Entrance (317 users
 *      each). Keeping them live would claim an enrolment that does not exist.
 *      The rows are retained with valid_to set, not deleted — punches made
 *      under that PIN still resolve to this human.
 *
 * UNDO: restore storage/app/private/uid-repairs/pre-merge-abdul-*.json, which
 * holds every row this touches, and re-create employee #1727. Audit entry
 * employee.merged records the same thing in reverse.
 */

require '/var/www/zkt/vendor/autoload.php';
$app = require_once '/var/www/zkt/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Employees\EmployeeMergeService;
use Illuminate\Support\Facades\DB;

$KEEP = 1428;
$MERGE = 1727;
$CARD = '7458281';

echo "=== STEP 1: merge #{$MERGE} into #{$KEEP} ===\n";
$result = app(EmployeeMergeService::class)->merge($KEEP, [$MERGE]);
printf("kept=%d merged=%d\n", $result['keep'], $result['merged']);
foreach ($result['rows'] as $t => $n) {
    printf("  %-26s %d\n", $t, $n);
}

echo "\n=== STEP 2: set the card on the employee record ===\n";
$e = DB::table('employees')->where('id', $KEEP)->first();
if ($e->card_number === null) {
    DB::table('employees')->where('id', $KEEP)->update(['card_number' => $CARD, 'updated_at' => now()]);
    printf("  #%d card_number NULL -> %s\n", $KEEP, $CARD);
} else {
    printf("  #%d card_number already %s, left alone\n", $KEEP, $e->card_number);
}

echo "\n=== STEP 3: close maps the hardware does not have ===\n";
$phantom = DB::table('employee_device_maps')
    ->where('employee_id', $KEEP)
    ->whereNull('valid_to')
    ->whereIn('device_id', [13, 14])
    ->get(['id', 'device_id', 'device_user_id']);

foreach ($phantom as $m) {
    DB::table('employee_device_maps')->where('id', $m->id)->update([
        'valid_to' => now(),
        'closed_reason' => 'PIN ' . $m->device_user_id . ' verified absent from this panel on 2026-10-05 '
            . '(ZKAccess manages it); enrolment did not survive',
        'updated_at' => now(),
    ]);
    printf("  closed map#%d dev#%d pin=%s\n", $m->id, $m->device_id, $m->device_user_id);
}
if ($phantom->isEmpty()) {
    echo "  nothing to close\n";
}

echo "\n=== VERIFY ===\n";
$keeper = DB::table('employees')->where('id', $KEEP)->first();
printf("employee #%d  %-12s  code=%-12s card=%s\n", $keeper->id, $keeper->name, $keeper->employee_code, $keeper->card_number);
printf("employee #%d still exists: %s\n", $MERGE,
    DB::table('employees')->where('id', $MERGE)->exists() ? 'YES (BAD)' : 'no');

echo "\nlive maps for #{$KEEP} (this is what 'Enrolled on' will show):\n";
$live = DB::table('employee_device_maps')->where('employee_id', $KEEP)->whereNull('valid_to')->get();
foreach ($live as $m) {
    $d = DB::table('devices')->where('id', $m->device_id)->first();
    printf("  dev#%-3d %-12s pin=%-11s uid=%-4s card=%s\n",
        $m->device_id, $d->name ?? '?', $m->device_user_id, $m->device_uid, var_export($m->card_number, true));
}
if ($live->isEmpty()) {
    echo "  (none)\n";
}

echo "\nclosed maps retained:\n";
foreach (DB::table('employee_device_maps')->where('employee_id', $KEEP)->whereNotNull('valid_to')->get() as $m) {
    $d = DB::table('devices')->where('id', $m->device_id)->first();
    printf("  dev#%-3d %-12s pin=%-11s %s\n", $m->device_id, $d->name ?? '?', $m->device_user_id, $m->closed_reason);
}

printf("\nattendance now on #%d: %d rows\n", $KEEP, DB::table('attendance_logs')->where('employee_id', $KEEP)->count());
printf("rows still pointing at #%d: %d\n", $MERGE,
    DB::table('employee_device_maps')->where('employee_id', $MERGE)->count()
    + DB::table('attendance_logs')->where('employee_id', $MERGE)->count());

echo "\n=== audit entry ===\n";
$log = DB::table('audit_logs')->where('action', 'employee.merged')->orderByDesc('id')->first();
if ($log) {
    printf("audit #%d %s kept=%s merged=%s\n", $log->id, $log->created_at,
        json_encode($log->changes['kept'] ?? null), json_encode($log->changes['merged'] ?? null));
} else {
    echo "  none found\n";
}
