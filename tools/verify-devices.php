<?php

/*
 * Device operation verification — READ-ONLY, PERSISTS NOTHING.
 *
 * Exercises every driver operation the Devices screen offers, against real
 * hardware, and reports precisely what each one returned.
 *
 * BLAST RADIUS: READ-ONLY.
 *   - driver->ping()           GET_PARAM / handshake. Calls the DRIVER directly,
 *                              NOT DeviceHealthService, so no health row is written.
 *   - driver->info()           identity read.
 *   - driver->capabilities()   cached flags, no network.
 *   - driver->readUsers()      the roster. Reads the user table; writes nothing.
 *   - driver->readAttendance() the FULL attendance table off the device.
 *
 * It deliberately does NOT call AttendanceSyncService or DeviceUserSyncService.
 * Those are the paths that persist — attendance_logs, employees, watermarks — and
 * the owner asked for no sync. This proves the communication without touching a
 * single row.
 *
 * Usage: php artisan tinker --execute="\$ids=[13,14]; require 'tools/verify-devices.php';"
 */

use Promoseven\Biometric\Capability\Capability;

$driver = app(\App\Services\Devices\DeviceDriverManager::class);
$ids = $ids ?? [];
$summary = [];

foreach ($ids as $id) {
    $d = \App\Models\Device::withoutGlobalScopes()->find($id);
    if (! $d) { printf("!! device id=%s not found\n", $id); continue; }

    \App\Support\Tenancy::set($d->tenant_id);
    $row = ['id' => $d->id, 'name' => $d->name, 'ip' => $d->ip_address, 'proto' => $d->protocol];
    printf("\n%s\n[%s] %s  %s  (%s / %s)\n%s\n",
        str_repeat('=', 72), $d->id, $d->name, $d->ip_address, $d->protocol, $d->model ?: 'no model', str_repeat('-', 72));

    // --- driver selection -------------------------------------------------
    try {
        $row['driver'] = (new \ReflectionClass($driver->driverFor($d)))->getShortName();
        printf("  driver        %s\n", $row['driver']);
    } catch (\Throwable $e) {
        $row['driver'] = 'ERROR'; printf("  driver        ERROR %s\n", $e->getMessage());
        $summary[] = $row; \App\Support\Tenancy::clear(); continue;
    }

    // --- ping -------------------------------------------------------------
    $t = microtime(true);
    try {
        $p = $driver->ping($d);
        $row['ping'] = 'ok';
        printf("  ping          ok    %.0f ms   %s\n", $p['latency_ms'] ?? 0, $p['note'] ?? '');
    } catch (\Throwable $e) {
        $row['ping'] = 'FAIL'; $row['ping_err'] = $e->getMessage();
        printf("  ping          FAIL  %s: %s\n", (new \ReflectionClass($e))->getShortName(), $e->getMessage());
    }

    // --- info -------------------------------------------------------------
    try {
        $i = $driver->info($d);
        $row['info'] = 'ok';
        printf("  info          ok    sn=%s fw=%s\n", $i['serial'] ?? '-', $i['firmware'] ?? '-');
    } catch (\Throwable $e) {
        $row['info'] = 'FAIL'; $row['info_err'] = $e->getMessage();
        printf("  info          FAIL  %s: %s\n", (new \ReflectionClass($e))->getShortName(), $e->getMessage());
    }

    // --- capabilities -----------------------------------------------------
    $caps = $driver->capabilities($d);
    $row['cap_users'] = $caps->level(Capability::USER_READ);
    $row['cap_att'] = $caps->level(Capability::ATTENDANCE_READ);
    printf("  capabilities  user.read=%s  attendance.read=%s\n", $row['cap_users'], $row['cap_att']);

    // --- read users (Import staff's device half) ---------------------------
    if ($caps->supports(Capability::USER_READ)) {
        $t = microtime(true);
        try {
            $users = $driver->readUsers($d);
            $named = count(array_filter($users, fn ($u) => trim($u->name) !== ''));
            $carded = count(array_filter($users, fn ($u) => $u->cardNumber !== null));
            $row['users'] = count($users);
            printf("  readUsers     ok    %s user(s) in %.1fs  (%s named, %s with a card)\n",
                number_format(count($users)), microtime(true) - $t, number_format($named), number_format($carded));
        } catch (\Throwable $e) {
            $row['users'] = 'FAIL'; $row['users_err'] = $e->getMessage();
            printf("  readUsers     FAIL  %s: %s\n", (new \ReflectionClass($e))->getShortName(), $e->getMessage());
        }
    } else {
        $row['users'] = 'hidden'; printf("  readUsers     hidden (capability %s — UI does not offer it)\n", $row['cap_users']);
    }

    // --- full attendance read (the device half of "Full re-read") ----------
    if ($caps->supports(Capability::ATTENDANCE_READ)) {
        $t = microtime(true);
        try {
            $recs = $driver->readAttendance($d);
            $undecoded = count(array_filter($recs, fn ($r) => ! isset($r['timestamp'])));
            $row['punches'] = count($recs); $row['undecoded'] = $undecoded;
            $stamps = array_filter(array_map(fn ($r) => $r['timestamp'] ?? null, $recs));
            printf("  readAttendance ok   %s record(s) in %.1fs  (%s undecodable)\n",
                number_format(count($recs)), microtime(true) - $t, number_format($undecoded));
            if ($stamps) {
                usort($stamps, fn ($a, $b) => $a <=> $b);
                printf("                      %s .. %s\n", reset($stamps)->format('Y-m-d H:i'), end($stamps)->format('Y-m-d H:i'));
            }
        } catch (\Throwable $e) {
            $row['punches'] = 'FAIL'; $row['punches_err'] = $e->getMessage();
            printf("  readAttendance FAIL %s: %s\n", (new \ReflectionClass($e))->getShortName(), $e->getMessage());
        }
    } else {
        $row['punches'] = 'hidden'; printf("  readAttendance hidden (capability %s)\n", $row['cap_att']);
    }

    $summary[] = $row;
    \App\Support\Tenancy::clear();
}

echo "\n", str_repeat('=', 72), "\nSUMMARY\n", str_repeat('=', 72), "\n";
printf("%-4s %-14s %-16s %-9s %-6s %-6s %-9s %-9s\n", 'id', 'name', 'ip', 'proto', 'ping', 'info', 'users', 'punches');
foreach ($summary as $r) {
    printf("%-4s %-14s %-16s %-9s %-6s %-6s %-9s %-9s\n",
        $r['id'], substr($r['name'], 0, 14), $r['ip'], $r['proto'],
        $r['ping'] ?? '-', $r['info'] ?? '-',
        is_int($r['users'] ?? null) ? number_format($r['users']) : ($r['users'] ?? '-'),
        is_int($r['punches'] ?? null) ? number_format($r['punches']) : ($r['punches'] ?? '-'));
}
$fails = array_filter($summary, fn ($r) => in_array('FAIL', $r, true));
printf("\n%d device(s) checked, %d with a failure.\n", count($summary), count($fails));
foreach ($fails as $r) {
    foreach (['ping_err', 'info_err', 'users_err', 'punches_err'] as $k) {
        if (isset($r[$k])) printf("  [%s] %s: %s\n", $r['id'], str_replace('_err', '', $k), $r[$k]);
    }
}
