<?php

/*
 * Camelot (87.237.196.60) bulk-read experiments — 2026-09-14.
 *
 * Continues the investigation paused on 2026-09-13. Two mechanisms were
 * identified from smoke traces and neither is the receive buffer:
 *
 *   1. the device's send pacing degrades ~5x over a session (62ms -> 302ms per
 *      16 KB window), with time-to-first-byte staying low throughout
 *   2. an aborted read leaves the device streaming to a closed port (NoPorts
 *      climbed 682 in one run), so the next attempt finds it busy
 *
 * These subcommands test each mechanism directly. All of them are READ-ONLY.
 *
 * Usage:
 *   php experiment.php info
 *   php experiment.php read <label> [pace=MS] [reset=N] [drain=1] [max=N]
 *                                   [window=BYTES] [runs=N] [gap=SECONDS]
 *   php experiment.php flush <label> [abort_after=SECONDS] [watch=SECONDS]
 *   php experiment.php recover <label> [gaps=5,30,60,120] [max=6]
 */

require_once __DIR__.'/Probe.php';

use Promoseven\Biometric\Protocol\Command;

const HOST = '87.237.196.60';

/**
 * Handshake with a short backoff.
 *
 * Camelot intermittently refuses a session opened seconds after the previous one
 * closed — observed 2026-09-14, and it recovers on its own within a few seconds.
 * Without this a transient refusal costs a whole experimental run.
 */
function connectWithRetry(Probe $p, int $attempts = 4, int $waitSeconds = 5): bool
{
    for ($i = 1; $i <= $attempts; $i++) {
        if ($p->connect()) {
            return true;
        }
        if ($i < $attempts) {
            printf("   handshake refused (attempt %d/%d), retrying in %ds\n", $i, $attempts, $waitSeconds);
            sleep($waitSeconds);
            $p->close();
            $p->open();
        }
    }

    return false;
}

$outDir = __DIR__.'/traces-'.date('Y-m-d');
@mkdir($outDir, 0775, true);

/** key=value command line arguments, after the subcommand and label. */
function opts(array $argv, int $from): array
{
    $o = [];
    foreach (array_slice($argv, $from) as $a) {
        if (str_contains($a, '=')) {
            [$k, $v] = explode('=', $a, 2);
            $o[$k] = $v;
        }
    }

    return $o;
}

function saveTrace(string $dir, string $name, array $trace): string
{
    $file = sprintf('%s/%s.json', $dir, $name);
    file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT));

    return $file;
}

/** Summarise a windowed read's per-window timings — the degradation curve. */
function windowCurve(array $windows): string
{
    $ms = [];
    foreach ($windows as $w) {
        if (isset($w['reset_at_window'])) {
            $ms[] = 'RESET';

            continue;
        }
        $ms[] = isset($w['err']) ? ('ERR:'.$w['err']) : (string) (int) ($w['ms'] ?? 0);
    }

    return implode(' ', $ms);
}

$cmd = $argv[1] ?? 'info';
$label = $argv[2] ?? $cmd;
$o = opts($argv, 3);

// ---------------------------------------------------------------------------
// info — handshake and table size only. No bulk transfer, so it cannot poison
// the device and is safe to run at any time.
// ---------------------------------------------------------------------------
if ($cmd === 'info') {
    $p = new Probe(HOST);
    $p->open();
    $t0 = microtime(true);
    if (! connectWithRetry($p)) {
        echo "HANDSHAKE FAILED\n";
        exit(1);
    }
    printf("handshake  OK in %d ms (sessions=%d, rcvbuf granted=%d)\n",
        round((microtime(true) - $t0) * 1000), $p->sessions, $p->granted);

    foreach ([['attendance', Command::ATTLOG_RRQ], ['users/templates', Command::USERTEMP_RRQ]] as [$name, $table]) {
        $t = microtime(true);
        $r = $p->stageSize($table);
        printf("%-16s %s  %s  (%d ms)\n", $name,
            $r['ok'] ? 'staged' : 'REFUSED',
            $r['ok'] ? number_format($r['size']).' bytes' : ($r['why'] ?? ''),
            round((microtime(true) - $t) * 1000));
    }
    $p->disconnect();
    $p->close();
    exit(0);
}

// ---------------------------------------------------------------------------
// read — an instrumented windowed read with the pacing / reset / pre-drain
// knobs. This is the experiment that tests mechanism 1.
// ---------------------------------------------------------------------------
if ($cmd === 'read') {
    $runs = (int) ($o['runs'] ?? 1);
    $gap = (int) ($o['gap'] ?? 0);

    foreach (range(1, $runs) as $i) {
        if ($i > 1 && $gap > 0) {
            printf("  … waiting %ds before run %d\n", $gap, $i);
            sleep($gap);
        }

        $p = new Probe(HOST);
        $before = udpCounters();
        $wall = microtime(true);

        try {
            $p->open();
            if (! connectWithRetry($p)) {
                echo "$label run$i: HANDSHAKE FAILED\n";
                $p->close();

                continue;
            }
            $res = $p->windowedRead([
                'pace_ms' => (int) ($o['pace'] ?? 0),
                'reset_every' => (int) ($o['reset'] ?? 0),
                'drain_first' => (bool) ($o['drain'] ?? false),
                'max_windows' => (int) ($o['max'] ?? 0),
                'window_bytes' => (int) ($o['window'] ?? WINDOW_BYTES),
                'delay_first' => (float) ($o['delay_first'] ?? 0),
                'empty_polls' => (int) ($o['empty_polls'] ?? 10),
            ]);
            $p->disconnect();
        } catch (Throwable $e) {
            $res = ['ok' => false, 'why' => $e->getMessage(), 'windows' => []];
        }
        $p->close();
        $delta = counterDelta($before, udpCounters());

        $trace = [
            'label' => $label,
            'host' => HOST,
            'mode' => 'windowed',
            'run' => $i,
            'opts' => $o,
            'started_at' => date('c', (int) $wall),
            'granted_rcvbuf' => $p->granted,
            'result' => $res,
            'udp_counter_delta' => $delta,
            'arrivals' => $p->arrivals,
        ];
        saveTrace($outDir, sprintf('%s-%02d', $label, $i), $trace);

        printf("%-22s run%-2d %-4s %9s/%-9s %6ds  windows=%-4d NoPorts=%-5s RcvbufErr=%-3s %s\n",
            $label, $i,
            ($res['ok'] ?? false) ? 'OK' : 'FAIL',
            number_format($res['got'] ?? 0), number_format($res['size'] ?? 0),
            round(($res['ms'] ?? 0) / 1000),
            count($res['windows'] ?? []),
            $delta['NoPorts'] ?? 0, $delta['RcvbufErrors'] ?? 0,
            $res['why'] ?? '');
        if (! empty($res['windows'])) {
            echo '   per-window ms: '.windowCurve($res['windows'])."\n";
        }
        if (! empty($res['pre_drain'])) {
            printf("   pre-drain discarded %d bytes in %d datagrams over %d ms\n",
                $res['pre_drain']['bytes'], $res['pre_drain']['datagrams'], $res['pre_drain']['ms']);
        }
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// offsets — is the slowdown driven by the offset asked for, or by the work done
// so far? Reads single windows at scattered offsets, cold and out of order.
//
//   offsets=high     descending, so the most expensive offset is read FIRST
//   offsets=low      ascending, the sequential order, as a comparison
// ---------------------------------------------------------------------------
if ($cmd === 'offsets') {
    $order = (string) ($o['order'] ?? 'desc');
    $count = (int) ($o['count'] ?? 8);

    $p = new Probe(HOST);
    $before = udpCounters();
    $p->open();
    if (! connectWithRetry($p)) {
        echo "HANDSHAKE FAILED\n";
        exit(1);
    }

    // Spread the probes evenly across the table, then read them in the chosen
    // order. Descending is the interesting one.
    $size = 1665284;
    $step = intdiv($size - WINDOW_BYTES, max(1, $count - 1));
    $offsets = [];
    for ($i = 0; $i < $count; $i++) {
        $offsets[] = $i * $step;
    }
    if ($order === 'desc') {
        $offsets = array_reverse($offsets);
    }

    $res = $p->probeOffsets($offsets);
    $p->disconnect();
    $p->close();
    $delta = counterDelta($before, udpCounters());

    saveTrace($outDir, $label, [
        'label' => $label, 'host' => HOST, 'mode' => 'offsets', 'order' => $order,
        'started_at' => date('c'), 'result' => $res,
        'udp_counter_delta' => $delta, 'arrivals' => $p->arrivals,
    ]);

    printf("order=%s  table=%s  NoPorts=%s\n", $order,
        number_format($res['size'] ?? 0), $delta['NoPorts'] ?? 0);
    printf("%-12s %10s %8s %8s %10s\n", 'offset', 'ms', 'got', 'dgrams', 'us/byte');
    foreach ($res['probes'] ?? [] as $pr) {
        printf("%-12s %10s %8s %8s %10s %s\n",
            number_format($pr['offset']), $pr['ms'], number_format($pr['got']),
            $pr['datagrams'] ?? '-',
            $pr['got'] ? round($pr['ms'] * 1000 / $pr['got'], 1) : '-',
            $pr['err'] ?? '');
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// flush — deliberately abort a read, then watch how long the device keeps
// sending. This is the experiment that tests mechanism 2, and it produces the
// number nobody has: the minimum safe gap between attempts.
//
// A push read is used on purpose: it is the one that makes the device stream
// the whole 1.66 MB table unprompted, which is what we want to time.
// ---------------------------------------------------------------------------
if ($cmd === 'flush') {
    $abortAfter = (float) ($o['abort_after'] ?? 3.0);
    $watch = (int) ($o['watch'] ?? 120);

    $p = new Probe(HOST);
    $p->open();
    if (! connectWithRetry($p)) {
        echo "HANDSHAKE FAILED\n";
        exit(1);
    }

    $r = $p->cmd(Command::ATTLOG_RRQ);
    $code = $r['command'] ?? 0;
    if ($code !== Command::PREPARE_DATA) {
        echo 'no PREPARE_DATA, got '.Command::name($code)."\n";
        exit(1);
    }
    $size = unpack('V', substr($r['data'], 0, 4))[1];
    printf("device is streaming %s bytes; aborting after %.1fs\n", number_format($size), $abortAfter);

    // Read for a moment, then drop the socket without FREE_DATA or EXIT — the
    // exact shape of a client that gave up.
    $p->setPollTimeout(0.2);
    $t0 = microtime(true);
    $got = 0;
    while (microtime(true) - $t0 < $abortAfter) {
        $got += strlen($p->readRaw());
    }
    $abortPort = $p->localPort();
    $p->close();
    printf("aborted after %s bytes; socket closed (port %d)\n", number_format($got), $abortPort);

    // Now watch NoPorts. Every datagram the device sends from here on lands at a
    // closed port and increments it.
    $samples = [];
    $prev = udpCounters();
    $quietSince = null;
    for ($s = 1; $s <= $watch; $s++) {
        sleep(1);
        $now = udpCounters();
        $d = ($now['NoPorts'] ?? 0) - ($prev['NoPorts'] ?? 0);
        $samples[] = ['t' => $s, 'noports' => $d];
        $prev = $now;
        if ($d === 0) {
            $quietSince ??= $s;
        } else {
            $quietSince = null;
        }
        printf("  t+%-3ds  NoPorts +%d%s\n", $s, $d, $d === 0 ? '' : '  ← still sending');
        if ($quietSince !== null && $s - $quietSince >= 4) {
            printf("quiet for 5s — device stopped sending at about t+%ds\n", $quietSince);
            break;
        }
    }

    saveTrace($outDir, $label, [
        'label' => $label, 'host' => HOST, 'mode' => 'flush',
        'started_at' => date('c'), 'table_size' => $size,
        'abort_after_s' => $abortAfter, 'bytes_before_abort' => $got,
        'samples' => $samples,
        'note' => 'NoPorts is namespace-wide; a quiet host makes it a good proxy but not proof.',
    ]);
    exit(0);
}

// ---------------------------------------------------------------------------
// recover — does the gap since the last attempt predict success? Single windowed
// reads separated by increasing pauses.
// ---------------------------------------------------------------------------
if ($cmd === 'recover') {
    $gaps = array_map('intval', explode(',', (string) ($o['gaps'] ?? '5,30,60,120')));
    $max = (int) ($o['max'] ?? 6);

    foreach ($gaps as $n => $gap) {
        printf("waiting %ds…\n", $gap);
        sleep($gap);

        $p = new Probe(HOST);
        $before = udpCounters();
        try {
            $p->open();
            if (! connectWithRetry($p)) {
                printf("gap=%-4ds HANDSHAKE FAILED\n", $gap);
                $p->close();

                continue;
            }
            $res = $p->windowedRead(['max_windows' => $max]);
            $p->disconnect();
        } catch (Throwable $e) {
            $res = ['ok' => false, 'why' => $e->getMessage(), 'windows' => []];
        }
        $p->close();
        $delta = counterDelta($before, udpCounters());

        saveTrace($outDir, sprintf('%s-gap%03d', $label, $gap), [
            'label' => $label, 'host' => HOST, 'mode' => 'recover', 'gap_s' => $gap,
            'started_at' => date('c'), 'result' => $res,
            'udp_counter_delta' => $delta, 'arrivals' => $p->arrivals,
        ]);

        $done = count(array_filter($res['windows'] ?? [], fn ($w) => ($w['got'] ?? 0) > 0));
        printf("gap=%-4ds  %d/%d windows  NoPorts=%-5s  curve: %s\n",
            $gap, $done, $max, $delta['NoPorts'] ?? 0, windowCurve($res['windows'] ?? []));
    }
    exit(0);
}

echo "unknown subcommand: $cmd\n";
exit(1);
