<?php

/*
 * What are the datagrams that break a UDP windowed read over the internet? (casebook DC-047.)
 *
 * attendance-transport.php showed gems workshop (F18, 95.84.64.159:4371) reading its punch table
 * whole and clean twice, then 819 / 16,276 on a read that stalled — the slip in window 2 and every
 * later window shifted. The library's UDP reassembly (BulkReader::readWindow, UdpTransport::
 * readRawPayload) strips the header of every datagram without looking at it. This script does the
 * same windowed read the library does — 16 KB windows, 10 empty 0.2 s polls before a window counts
 * as stalled, drain then re-request the same offset up to 4 times — but logs the HEADER of every
 * datagram (command, session, reply id, payload length, arrival time) and checks each finished
 * window's alignment. It repeats the read until one has a stall, or the run limit.
 *
 * BLAST RADIUS: READ-ONLY. CONNECT (+AUTH if asked), PREPARE_BUFFER of the punch table
 * (ATTLOG_RRQ), READ_BUFFER by offset, FREE_DATA (releases the copy the device staged for us),
 * EXIT. Nothing is written to the terminal or the database. Holds the queue's per-device lock
 * (rule 11).
 *
 * The trace holds headers, lengths, timings and per-window alignment counts only — no payload
 * bytes, no user ids, no punch times.
 *
 * Usage:  php tools/zkprobe/datagram-headers.php <device-id> [max-reads=6] [--head]
 *   --head prints the table's first and last 48 bytes to the terminal (not the trace) to compare a
 *   clean read with a shifted one; they hold user ids and times, so they are never committed.
 *   --wait-late: a window request unanswered within the timeout is NOT sent again; the probe waits
 *   up to 20 s more for that request's own late answer (DC-047: a repeated request shifts the rest).
 */

require __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/Probe.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ProcessDeviceCommand;
use App\Models\Device;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Promoseven\Biometric\Protocol\Command;

$device = Device::withoutGlobalScopes()->find((int) ($argv[1] ?? 0));
$maxReads = (int) (isset($argv[2]) && ctype_digit($argv[2]) ? $argv[2] : 6);
if ($device === null) {
    fwrite(STDERR, "Usage: php tools/zkprobe/datagram-headers.php <device-id> [max-reads]\n");
    exit(2);
}

const WIN = 16384;
const EMPTY_POLLS = 10;
const RETRIES = 4;

/**
 * How many of the 40-byte records that start inside this window look like a punch, and if not,
 * by how many bytes the records sit shifted. The staged punch table is a 4-byte length followed by
 * 40-byte records, so records begin at absolute offsets 4 + 40k. (Traces 01-04 of 2026-11-15 were
 * written before this accounted for the 4 bytes: their "alignment" fields are wrong and every
 * window shows as misaligned. The headers in them are unaffected.)
 */
function looksLikePunchAt(string $win, int $first): array
{
    $ok = 0;
    $bad = 0;
    for ($i = $first; $i + 40 <= strlen($win); $i += 40) {
        $uid = rtrim(substr($win, $i + 2, 24), "\0");
        ($uid !== '' && ctype_digit($uid)) ? $ok++ : $bad++;
    }

    return [$ok, $bad];
}

function alignment(string $win, int $start): array
{
    $first = ((4 - $start) % 40 + 40) % 40;
    [$ok, $bad] = looksLikePunchAt($win, $first);
    $out = ['ok' => $ok, 'bad' => $bad];
    if ($bad > $ok) {
        $best = [0, -1];
        for ($k = 0; $k < 40; $k++) {
            [$o] = looksLikePunchAt($win, $k);
            if ($o > $best[1]) {
                $best = [$k, $o];
            }
        }
        $out['shift'] = (($best[0] - $first) % 40 + 40) % 40; // bytes later than expected
        $out['ok_at_shift'] = $best[1];
    }

    return $out;
}

$trace = [
    'label' => 'datagram-headers',
    'host' => "{$device->ip_address}:{$device->port}",
    'device' => ['id' => $device->id, 'name' => $device->name, 'model' => $device->model],
    'started_at' => gmdate('c'),
    'question' => 'Which datagrams arrive during a window of a UDP windowed read, and which ones shift the data?',
    'reads' => [],
];

$lock = Cache::lock((new WithoutOverlapping("device:{$device->id}"))->getLockKey(new ProcessDeviceCommand(0)), 1800);
if (! $lock->get()) {
    fwrite(STDERR, "queue lock busy\n");
    exit(1);
}

try {
    for ($run = 1; $run <= $maxReads; $run++) {
        $p = new Probe($device->ip_address, (int) $device->port);
        $p->open();
        $read = ['run' => $run, 'started_at' => gmdate('c'), 'windows' => []];
        if (! $p->connect((int) ($device->comm_key ?: 0))) {
            $read['error'] = 'connect failed';
            $trace['reads'][] = $read;
            $p->close();
            echo "read {$run}: connect failed\n";
            continue;
        }
        $r = $p->cmd(Command::PREPARE_BUFFER, pack('C', 1).pack('v', Command::ATTLOG_RRQ).pack('V', 0).pack('V', 0));
        if (($r['command'] ?? 0) !== Command::ACK_OK || strlen($r['data'] ?? '') < 5) {
            $read['error'] = 'stage refused: '.Command::name($r['command'] ?? 0);
            $trace['reads'][] = $read;
            $p->disconnect();
            $p->close();
            echo "read {$run}: stage refused\n";
            continue;
        }
        $size = unpack('V', substr($r['data'], 1, 4))[1];
        $read['size'] = $size;
        $t0 = microtime(true);
        $at = fn () => round((microtime(true) - $t0) * 1000);
        $offset = 0;
        $stalls = 0;
        $badWindows = 0;
        $n = 0;

        while ($offset < $size) {
            $want = min(WIN, $size - $offset);
            $n++;
            $win = ['w' => $n, 'start' => $offset, 'want' => $want, 'attempts' => []];
            $data = null;
            for ($attempt = 0; $attempt <= RETRIES && $data === null; $attempt++) {
                $a = ['attempt' => $attempt, 'drained' => [], 'packets' => []];
                if ($attempt > 0) {
                    // The library drains stale datagrams before each retry; log what it drains.
                    for ($i = 0; $i < 64 && ($d = $p->readPacket(0.2)) !== null; $i++) {
                        $a['drained'][] = [$at(), $d['command'], $d['sessionId'], $d['replyId'], $d['len']];
                    }
                }
                $reply = $p->cmd(Command::READ_BUFFER, pack('VV', $offset, $want));
                $a['request_reply_id'] = $p->replyId();
                // --wait-late: an unanswered request is not sent again; wait for ITS late answer.
                if ($reply === [] && in_array('--wait-late', $argv, true)) {
                    $waitStart = microtime(true);
                    while (microtime(true) - $waitStart < 20.0) {
                        $late = $p->readPacket(0.5);
                        if ($late !== null && $late['replyId'] === $a['request_reply_id'] && $late['command'] === Command::PREPARE_DATA) {
                            $reply = ['command' => $late['command'], 'sessionId' => $late['sessionId'], 'replyId' => $late['replyId'], 'data' => $late['data']];
                            $a['waited_late_ms'] = round((microtime(true) - $waitStart) * 1000);
                            break;
                        }
                        if ($late !== null) {
                            $a['drained'][] = [$at(), $late['command'], $late['sessionId'], $late['replyId'], $late['len']];
                        }
                    }
                }
                $a['reply'] = $reply === [] ? null
                    : [$at(), $reply['command'], $reply['sessionId'], $reply['replyId'], strlen($reply['data'])];
                $code = $reply['command'] ?? 0;
                if ($code === Command::DATA) {
                    if (strlen($reply['data']) >= $want) {
                        $data = substr($reply['data'], 0, $want);
                    }
                } elseif ($code === Command::PREPARE_DATA) {
                    $buf = '';
                    $empty = 0;
                    while (strlen($buf) < $want && $empty < EMPTY_POLLS) {
                        $d = $p->readPacket(0.2);
                        if ($d === null) {
                            $empty++;
                            continue;
                        }
                        $empty = 0;
                        $a['packets'][] = [$at(), $d['command'], $d['sessionId'], $d['replyId'], $d['len']];
                        $buf .= $d['data']; // exactly what the library does: every payload, in arrival order
                    }
                    if (strlen($buf) >= $want) {
                        $a['overflow'] = strlen($buf) - $want;
                        $data = substr($buf, 0, $want);
                        $tail = $p->readPacket(0.2); // the window's acknowledgement, as the library reads it
                        $a['tail'] = $tail === null ? null : [$at(), $tail['command'], $tail['sessionId'], $tail['replyId'], $tail['len']];
                    } else {
                        $a['stalled_with'] = strlen($buf);
                        $stalls++;
                    }
                }
                $win['attempts'][] = $a;
            }
            if ($data === null) {
                $win['gave_up'] = true;
                $read['windows'][] = $win;
                break;
            }
            $win['alignment'] = alignment($data, $offset);
            // --head: the first and last 48 bytes of the table to the terminal only, never the trace.
            if (in_array('--head', $argv, true) && ($offset === 0 || $offset + $want >= $size)) {
                fwrite(STDERR, sprintf("  read %d window %d %s: %s\n", $run, $n, $offset === 0 ? 'first 48' : 'last 48',
                    implode(' ', str_split(bin2hex($offset === 0 ? substr($data, 0, 48) : substr($data, -48)), 8))));
            }
            if ($win['alignment']['bad'] > $win['alignment']['ok']) {
                $badWindows++;
            }
            $read['windows'][] = $win;
            $offset += $want;
        }
        $p->cmd(Command::FREE_DATA);
        $p->disconnect();
        $p->close();

        $read += ['ms' => $at(), 'got' => $offset, 'stalls' => $stalls, 'bad_windows' => $badWindows, 'windows_total' => $n];
        $trace['reads'][] = $read;
        printf("read %d: %d of %d bytes, %d windows, %d stalls, %d misaligned windows, %d ms\n",
            $run, $offset, $size, $n, $stalls, $badWindows, $read['ms']);
        if ($badWindows > 0 && ! in_array('--wait-late', $argv, true)) {
            break; // caught a shifted read; that is what this run is for
        }
        sleep(3);
    }
} finally {
    $lock->release();
}

$trace['finished_at'] = gmdate('c');
$dir = __DIR__.'/traces-'.gmdate('Y-m-d');
@mkdir($dir, 0775, true);
$k = 1;
while (file_exists(sprintf('%s/datagram-headers-%02d.json', $dir, $k))) {
    $k++;
}
$file = sprintf('%s/datagram-headers-%02d.json', $dir, $k);
file_put_contents($file, json_encode($trace, JSON_UNESCAPED_SLASHES)."\n");
echo "trace: {$file}\n";
