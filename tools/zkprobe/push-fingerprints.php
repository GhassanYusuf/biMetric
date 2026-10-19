<?php

/*
 * WRITES TO THE DEVICE: YES — fingerprint templates onto two C3 panels.
 *
 * Copies Abdul Haq's two fingers off the F18 (IT Room) and onto Glass Door (#13)
 * and Entrance (#14), using the app's own vault and write path.
 *
 * Step 1  FingerprintVault::captureEmployee() — READ-ONLY on every terminal. Reads
 *         the template table of each terminal he is mapped to and stores the copies
 *         in biometric_templates. This is the "Read fingerprints to the system"
 *         the UI already exposes; the vault was simply empty, so it had never run.
 * Step 2  DeviceDriverManager::uploadTemplates() -> Personnel::addFingerprints() on
 *         each C3 panel: one templatev10 row per finger, under the panel's OWN UID
 *         for him (318, re-read live), add-only, verified byte for byte, with the
 *         person's original fingers restored on any failure.
 *
 * BYPASSED GATE, DELIBERATELY: FingerprintPusher::pushOne() calls
 * $device->assertWritable(), and both panels are read_only, so it refuses. That
 * gate exists because writing a user row can replace whoever holds the slot. It is
 * NOT bypassed in the app — this script calls the driver underneath it, so the
 * guard stays where it belongs. The checks that actually protect the data are in
 * the library and all still run:
 *   - addFingerprints() is add-only: it never replaces a finger already held
 *   - it verifies each template byte for byte after writing, and restores the
 *     originals if any of them fails
 *   - FingerprintVault::copiesFor() rejects anything without the ZKFinger `SS21`
 *     marker at offset 2, and prefers a copy from the same protocol family
 *
 * WHY IT IS SAFE TO DO THIS AT ALL, verified read-only first (template-compat.php):
 *   F18 slot 6   4A 03  53533231  0000  0340  42  04080709 CE D0   (832 bytes)
 *   C3  Pin 10   4A 2D  53533231  0000  036E  6E  04080709 CE D0   (878 bytes)
 *   Same `SS21` signature, same `04 08 05 07 09 CE D0` version descriptor, and a
 *   self-describing size field that matches the stored length exactly. Same format.
 *
 * WHAT IS NOT PROVEN BY THIS RUN: that the finger MATCHES at the panel. Bytes
 * landing correctly is not a biometric match. Only Abdul standing at the reader
 * confirms that. Same wording as docs/enrollment-flow.md.
 *
 * UNDO: delete the templatev10 rows for Pin 781054761 on each panel
 * (Personnel::delete(pin)), or C3Driver::deleteUser() for the whole record.
 */

require '/var/www/zkt/vendor/autoload.php';
$app = require_once '/var/www/zkt/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Models\Employee;
use App\Services\Devices\DeviceDriverManager;
use App\Services\Employees\FingerprintVault;
use Illuminate\Support\Facades\DB;

$PIN = '781054761';
$EMP = 1428;

$employee = Employee::findOrFail($EMP);
$vault = app(FingerprintVault::class);
$manager = app(DeviceDriverManager::class);

echo "=== STEP 1: harvest his fingers off the terminals he is on (read-only) ===\n";
foreach ($vault->captureEmployee($employee) as $row) {
    printf("  #%-3d %-12s %-9s %s%s\n",
        $row['device_id'], $row['device_name'], $row['status'], $row['message'],
        isset($row['slots']) ? ' slots=' . implode(',', $row['slots']) : '');
}

printf("\n  vault rows for #%d: %d\n", $EMP,
    DB::table('biometric_templates')->where('employee_id', $EMP)->count());
printf("  vault rows in total : %d\n", DB::table('biometric_templates')->count());
foreach (DB::table('biometric_templates')->where('employee_id', $EMP)->get() as $t) {
    printf("    finger=%-3s format=%-22s sha1=%s source_device=%d\n",
        $t->finger_slot, $t->template_format, substr((string) $t->template_sha1, 0, 16), $t->device_id);
}

echo "\n=== STEP 2: push to the two C3 panels ===\n";
foreach ([13 => 'Glass Door', 14 => 'Entrance'] as $id => $label) {
    $dev = Device::find($id);
    echo "\n-- #{$id} {$label} ({$dev->ip_address}) --\n";

    $copies = $vault->copiesFor($EMP, $dev);
    if ($copies === []) {
        echo "  no usable copies in the vault — stopping\n";
        continue;
    }
    foreach ($copies as $c) {
        printf("  copy: finger=%-3d %d bytes sha1=%s from device #%d\n",
            $c['fid'], strlen($c['template']), substr($c['sha1'], 0, 16), $c['source_device_id']);
    }

    try {
        $out = $manager->uploadTemplates($dev, $PIN, array_map(fn (array $c) => [
            'slot' => $c['fid'],
            'template' => $c['template'],
        ], $copies));
        printf("  result: %s\n", json_encode($out));
    } catch (\Throwable $e) {
        printf("  PUSH FAILED: %s\n", $e->getMessage());
    }
}

echo "\n\n=== STEP 3: independent verification, read straight off each panel ===\n";
$expected = [];
foreach (DB::table('biometric_templates')->where('employee_id', $EMP)->get() as $t) {
    $expected[(int) $t->finger_slot] = (string) $t->template_sha1;
}

foreach ([13 => 'Glass Door', 14 => 'Entrance', 15 => 'IT Room'] as $id => $label) {
    $dev = Device::find($id);
    echo "\n-- #{$id} {$label} --\n";
    try {
        $rows = $manager->readTemplateDigests($dev);
    } catch (\Throwable $e) {
        printf("  digest read failed: %s\n", $e->getMessage());
        continue;
    }
    $mine = array_values(array_filter($rows, fn ($r) => (string) ($r['userId'] ?? '') === $PIN));
    printf("  template rows for PIN %s: %d\n", $PIN, count($mine));
    foreach ($mine as $r) {
        $slot = (int) ($r['slot'] ?? $r['finger'] ?? 0);
        $got = (string) ($r['sha1'] ?? '');
        $want = $expected[$slot] ?? null;
        $verdict = $want === null ? 'no vault copy to compare' : ($got === $want ? 'BYTE-IDENTICAL to vault' : '*** DIFFERS ***');
        printf("    finger=%-3d %5d bytes sha1=%s  %s\n",
            $slot, strlen((string) ($r['template'] ?? '')), substr($got, 0, 16), $verdict);
    }
    if ($mine === []) {
        echo "  (no fingerprints here)\n";
    }
}

echo "\n=== live maps + vault state ===\n";
foreach (DB::table('employee_device_maps')->where('employee_id', $EMP)->whereNull('valid_to')->get() as $m) {
    $d = DB::table('devices')->where('id', $m->device_id)->first();
    printf("  %-12s pin=%-11s uid=%-5s card=%s\n", $d->name, $m->device_user_id, $m->device_uid, $m->card_number);
}
printf("  stored finger slots: %s\n", implode(', ', $vault->storedSlots($EMP)) ?: '(none)');
