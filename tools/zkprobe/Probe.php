<?php

/*
 * Instrumented ZKTeco bulk-read probe — shared by harness.php and experiment.php.
 *
 * Extracted from harness.php on 2026-09-14 so the Camelot experiments could add
 * knobs (pacing, session reset, pre-drain) without forking the instrumentation.
 * The measurement behaviour is unchanged: every datagram is timestamped and the
 * kernel UDP counters are sampled either side of every run, because byte counts
 * alone cannot tell these three apart:
 *
 *   1. the kernel dropped datagrams     -> RcvbufErrors climbs, arrivals are gappy
 *   2. the device stopped sending       -> RcvbufErrors flat, arrivals just end
 *   3. the network lost them in transit -> RcvbufErrors flat, arrivals gappy
 *
 * READ-ONLY. ATTLOG_RRQ / PREPARE_BUFFER / READ_BUFFER never mutate the device.
 * FREE_DATA only releases a staged copy the device made for us.
 */

require_once __DIR__.'/../../vendor/autoload.php';

use Promoseven\Biometric\Protocol\Command;
use Promoseven\Biometric\Protocol\Packet;

const RCVBUF_REQUEST = 4 * 1024 * 1024;
const WINDOW_BYTES = 16 * 1024;
const POLL_SECONDS = 0.2;
const MAX_EMPTY_POLLS = 20;

/** Kernel UDP counters for this network namespace. */
function udpCounters(): array
{
    $lines = explode("\n", (string) @file_get_contents('/proc/net/snmp'));
    foreach ($lines as $i => $line) {
        if (str_starts_with($line, 'Udp:') && isset($lines[$i + 1]) && str_starts_with($lines[$i + 1], 'Udp:')) {
            $keys = preg_split('/\s+/', trim(substr($line, 4)));
            $vals = preg_split('/\s+/', trim(substr($lines[$i + 1], 4)));

            return array_combine($keys, array_map('intval', $vals));
        }
    }

    return [];
}

/** Bytes sitting unread in this socket's receive queue, straight from /proc. */
function rxQueueDepth(int $localPort): ?int
{
    $hex = strtoupper(str_pad(dechex($localPort), 4, '0', STR_PAD_LEFT));
    foreach (explode("\n", (string) @file_get_contents('/proc/net/udp')) as $line) {
        $f = preg_split('/\s+/', trim($line));
        if (count($f) > 4 && str_ends_with($f[1] ?? '', ':'.$hex)) {
            return hexdec(explode(':', $f[4])[1] ?? '0');
        }
    }

    return null;
}

/** Counters that changed between two samples. */
function counterDelta(array $before, array $after): array
{
    $delta = [];
    foreach ($after as $k => $v) {
        if (($before[$k] ?? 0) !== $v) {
            $delta[$k] = $v - ($before[$k] ?? 0);
        }
    }

    return $delta;
}

final class Probe
{
    private $sock;

    private $raw;

    private int $sessionId = 0;

    private int $replyId = 0;

    private int $localPort = 0;

    public int $granted = 0;

    /** @var list<array> every datagram that arrived, with a timestamp */
    public array $arrivals = [];

    /** Sessions opened on this probe — >1 means a mid-read reset happened. */
    public int $sessions = 0;

    public function __construct(private readonly string $host, private readonly int $port = 4370) {}

    public function open(): void
    {
        $errno = 0;
        $errstr = '';
        $this->sock = @stream_socket_client("udp://{$this->host}:{$this->port}", $errno, $errstr, 8.0);
        if ($this->sock === false) {
            throw new RuntimeException("connect failed: $errstr");
        }
        stream_set_timeout($this->sock, 8, 0);
        $this->raw = socket_import_stream($this->sock);
        @socket_set_option($this->raw, SOL_SOCKET, SO_RCVBUF, RCVBUF_REQUEST);
        $this->granted = (int) @socket_get_option($this->raw, SOL_SOCKET, SO_RCVBUF);
        $name = stream_socket_get_name($this->sock, false);
        $this->localPort = (int) substr($name, strrpos($name, ':') + 1);
        $this->sessionId = 0;
        $this->replyId = 0;
    }

    public function close(): void
    {
        if ($this->sock) {
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    public function localPort(): int
    {
        return $this->localPort;
    }

    /** Poll timeout for the raw reads used by the abort/flush experiment. */
    public function setPollTimeout(float $seconds): void
    {
        stream_set_timeout($this->sock, (int) $seconds, (int) (fmod($seconds, 1.0) * 1e6));
    }

    /** One raw datagram, or '' on timeout. Used when we are only counting bytes. */
    public function readRaw(int $max = 8192): string
    {
        $d = @fread($this->sock, $max);

        return ($d === false) ? '' : $d;
    }

    /** One request, one reply. Returns the parsed packet, or [] on timeout. */
    public function cmd(int $command, string $data = ''): array
    {
        [$pkt, $this->replyId] = Packet::build($command, $this->sessionId, $this->replyId, $data);
        @fwrite($this->sock, $pkt);
        $d = @fread($this->sock, 8192);

        return ($d === false || $d === '') ? [] : Packet::parse($d);
    }

    /**
     * Handshake, including the AUTH step.
     *
     * Camelot answers CONNECT with ACK_UNAUTH (2005) even though its comm key is
     * 0 — the device wants the exchange performed, not a non-zero secret. Treating
     * 2005 as a failure is what made this device look unreachable.
     */
    public function connect(int $commKey = 0): bool
    {
        $r = $this->cmd(Command::CONNECT);
        $code = $r['command'] ?? 0;
        if ($code === Command::ACK_OK) {
            $this->sessionId = $r['sessionId'];
            $this->sessions++;

            return true;
        }
        if ($code !== Command::ACK_UNAUTH) {
            return false;
        }

        $this->sessionId = $r['sessionId'];
        $r = $this->cmd(Command::AUTH, Packet::makeCommKey($commKey, $this->sessionId));
        if (($r['command'] ?? 0) !== Command::ACK_OK) {
            return false;
        }
        $this->sessions++;

        return true;
    }

    public function disconnect(): void
    {
        $this->cmd(Command::EXIT);
    }

    /** Tear the session and socket down and open a brand new one. */
    public function reconnect(int $commKey = 0): bool
    {
        $this->disconnect();
        $this->close();
        $this->open();

        return $this->connect($commKey);
    }

    /**
     * Read and discard whatever the device is still sending until it has been
     * quiet for $quietSeconds, or $maxSeconds elapses.
     *
     * This exists because an aborted read leaves the device streaming the rest of
     * the table at a socket we have closed (observed as NoPorts climbing). A new
     * request issued into that stream races it.
     */
    public function drainToSilence(float $quietSeconds = 2.0, float $maxSeconds = 120.0): array
    {
        stream_set_timeout($this->sock, 0, (int) (POLL_SECONDS * 1e6));
        $t0 = microtime(true);
        $bytes = 0;
        $datagrams = 0;
        $lastData = microtime(true);

        while (microtime(true) - $t0 < $maxSeconds) {
            $d = @fread($this->sock, 8192);
            if ($d === '' || $d === false) {
                if (microtime(true) - $lastData >= $quietSeconds) {
                    break;
                }

                continue;
            }
            $bytes += strlen($d);
            $datagrams++;
            $lastData = microtime(true);
        }
        stream_set_timeout($this->sock, 8, 0);

        return [
            'ms' => round((microtime(true) - $t0) * 1000),
            'bytes' => $bytes,
            'datagrams' => $datagrams,
        ];
    }

    /**
     * Drains datagrams until $want payload bytes have arrived or the device goes
     * quiet. Records an entry per datagram: monotonic offset, size, queue depth.
     *
     * Returns the payload plus the per-drain stats the degradation analysis needs
     * (time to first byte separates "device is slow to start" from "device is
     * pacing its sends slower").
     */
    private function drain(int $want, int $maxEmpty): array
    {
        stream_set_timeout($this->sock, 0, (int) (POLL_SECONDS * 1e6));
        $buf = '';
        $empty = 0;
        $datagrams = 0;
        $ttfb = null;
        $t0 = microtime(true);

        while (strlen($buf) < $want && $empty < $maxEmpty) {
            $d = @fread($this->sock, 8192);
            if ($d === '' || $d === false) {
                $empty++;
                $this->arrivals[] = [
                    't' => round((microtime(true) - $t0) * 1000, 3),
                    'bytes' => 0,
                    'empty_poll' => $empty,
                    'rxq' => rxQueueDepth($this->localPort),
                ];

                continue;
            }
            $empty = 0;
            $datagrams++;
            $ttfb ??= round((microtime(true) - $t0) * 1000, 3);
            $payload = strlen($d) >= 8 ? substr($d, 8) : '';
            $buf .= $payload;
            $this->arrivals[] = [
                't' => round((microtime(true) - $t0) * 1000, 3),
                'bytes' => strlen($payload),
                'datagram' => strlen($d),
                'cum' => strlen($buf),
                'rxq' => rxQueueDepth($this->localPort),
            ];
        }
        stream_set_timeout($this->sock, 8, 0);

        return [
            'buf' => $buf,
            'ttfb' => $ttfb,
            'datagrams' => $datagrams,
            'empty_polls' => $empty,
        ];
    }

    /** Free-running push read: ask once, then race the burst. */
    public function pushRead(): array
    {
        $r = $this->cmd(Command::ATTLOG_RRQ);
        $code = $r['command'] ?? 0;
        if ($code !== Command::PREPARE_DATA) {
            return ['ok' => false, 'why' => 'no PREPARE_DATA, got '.Command::name($code)];
        }
        $size = unpack('V', substr($r['data'], 0, 4))[1];
        $t0 = microtime(true);
        $d = $this->drain($size, MAX_EMPTY_POLLS);

        return [
            'ok' => strlen($d['buf']) >= $size,
            'size' => $size,
            'got' => strlen($d['buf']),
            'ms' => round((microtime(true) - $t0) * 1000),
        ];
    }

    /**
     * Ask the device to stage a table and report its size, then release it.
     * Cheap: no bulk transfer, so it can be run without poisoning the device.
     */
    public function stageSize(int $table = Command::ATTLOG_RRQ): array
    {
        $r = $this->cmd(Command::PREPARE_BUFFER, pack('C', 1).pack('v', $table).pack('V', 0).pack('V', 0));
        $code = $r['command'] ?? 0;
        if ($code !== Command::ACK_OK || strlen($r['data'] ?? '') < 5) {
            return ['ok' => false, 'why' => 'staging refused: '.Command::name($code), 'code' => $code];
        }
        $size = unpack('V', substr($r['data'], 1, 4))[1];
        $this->cmd(Command::FREE_DATA);

        return ['ok' => true, 'size' => $size];
    }

    /**
     * Flow-controlled windowed read: one explicitly requested window at a time.
     *
     * Knobs, all defaulting to the plain 2026-09-13 behaviour:
     *   pace_ms      sleep this long between windows
     *   reset_every  reconnect and re-stage every N windows, resuming at the
     *                offset already read
     *   drain_first  drain to silence before staging, in case a previous read
     *                left the device streaming
     *   max_windows  stop after N windows (a partial read, on purpose)
     *   window_bytes window size, default 16 KB
     *   delay_first  sleep this many seconds AFTER staging and before the first
     *                window — separates "degrades with windows read" from
     *                "degrades with time spent holding the staged buffer"
     */
    public function windowedRead(array $opts = []): array
    {
        $paceMs = (int) ($opts['pace_ms'] ?? 0);
        $resetEvery = (int) ($opts['reset_every'] ?? 0);
        $maxWindows = (int) ($opts['max_windows'] ?? 0);
        $emptyPolls = (int) ($opts['empty_polls'] ?? 10);
        $windowBytes = (int) ($opts['window_bytes'] ?? WINDOW_BYTES);

        $pre = null;
        if ($opts['drain_first'] ?? false) {
            $pre = $this->drainToSilence();
        }

        $staged = $this->stagedSize();
        if (! $staged['ok']) {
            return $staged + ['windows' => [], 'pre_drain' => $pre];
        }
        $size = $staged['size'];

        $delayFirst = (float) ($opts['delay_first'] ?? 0);
        if ($delayFirst > 0) {
            usleep((int) ($delayFirst * 1e6));
        }

        $t0 = microtime(true);
        $buf = '';
        $windows = [];
        $n = 0;

        while (strlen($buf) < $size) {
            if ($maxWindows > 0 && $n >= $maxWindows) {
                break;
            }
            if ($resetEvery > 0 && $n > 0 && $n % $resetEvery === 0) {
                $rt0 = microtime(true);
                // Release the staged copy BEFORE dropping the session. Without
                // this the device is left holding a 1.66 MB staged buffer per
                // reset, which is itself a cause of slowdown — measured
                // 2026-09-14, where an un-freed reset made every window slower
                // and the device eventually stopped answering.
                $this->cmd(Command::FREE_DATA);
                $ok = $this->reconnect();
                $restaged = $ok ? $this->stagedSize() : ['ok' => false, 'why' => 'reconnect failed'];
                $windows[] = [
                    'reset_at_window' => $n,
                    'offset' => strlen($buf),
                    'ok' => $restaged['ok'],
                    'why' => $restaged['why'] ?? null,
                    'ms' => round((microtime(true) - $rt0) * 1000, 1),
                ];
                if (! $restaged['ok']) {
                    break;
                }
            }
            if ($paceMs > 0 && $n > 0) {
                usleep($paceMs * 1000);
            }

            $want = min($windowBytes, $size - strlen($buf));
            $start = strlen($buf);
            $wt0 = microtime(true);
            $r = $this->cmd(Command::READ_BUFFER, pack('VV', $start, $want));
            $wcode = $r['command'] ?? 0;
            $n++;

            if ($wcode === Command::DATA) {
                $buf .= substr($r['data'], 0, $want);
                $windows[] = ['w' => $n, 'start' => $start, 'want' => $want, 'got' => $want, 'inline' => true,
                    'ms' => round((microtime(true) - $wt0) * 1000, 1)];

                continue;
            }
            if ($wcode !== Command::PREPARE_DATA) {
                $windows[] = ['w' => $n, 'start' => $start, 'want' => $want, 'got' => 0,
                    'err' => Command::name($wcode), 'ms' => round((microtime(true) - $wt0) * 1000, 1)];
                break;
            }
            $d = $this->drain($want, $emptyPolls);
            $win = $d['buf'];
            $windows[] = ['w' => $n, 'start' => $start, 'want' => $want, 'got' => strlen($win),
                'ttfb' => $d['ttfb'], 'datagrams' => $d['datagrams'],
                'ms' => round((microtime(true) - $wt0) * 1000, 1)];
            if (strlen($win) < $want) {
                break;
            }
            $buf .= substr($win, 0, $want);
            // consume this window's trailing ack
            stream_set_timeout($this->sock, 0, (int) (POLL_SECONDS * 1e6));
            @fread($this->sock, 8192);
            stream_set_timeout($this->sock, 8, 0);
        }
        $this->cmd(Command::FREE_DATA);

        return [
            'ok' => strlen($buf) >= $size,
            'size' => $size,
            'got' => strlen($buf),
            'ms' => round((microtime(true) - $t0) * 1000),
            'windows' => $windows,
            'sessions' => $this->sessions,
            'pre_drain' => $pre,
        ];
    }

    /**
     * Stage once, then read ONE window at each given offset, in the order given.
     *
     * This separates the two explanations for the linear slowdown that a
     * sequential read cannot tell apart, because in a sequential read the offset
     * and the cumulative bytes read rise together:
     *
     *   offset-driven     the device walks the staged buffer from the start to
     *                     reach the offset, so a high offset is slow even when
     *                     read first, and the total cost of a table is quadratic
     *   cumulative-driven the device degrades as it does work, so a high offset
     *                     read first is FAST, and re-staging may reset it
     *
     * Offsets are read cold and out of order on purpose. Anything sequential
     * would reintroduce exactly the confound this exists to remove.
     */
    public function probeOffsets(array $offsets, int $want = WINDOW_BYTES, int $emptyPolls = 40): array
    {
        $staged = $this->stagedSize();
        if (! $staged['ok']) {
            return $staged;
        }
        $size = $staged['size'];
        $out = [];

        foreach ($offsets as $offset) {
            if ($offset + $want > $size) {
                continue;
            }
            $t0 = microtime(true);
            $r = $this->cmd(Command::READ_BUFFER, pack('VV', $offset, $want));
            $code = $r['command'] ?? 0;

            if ($code === Command::DATA) {
                $out[] = ['offset' => $offset, 'got' => min(strlen($r['data']), $want), 'inline' => true,
                    'ms' => round((microtime(true) - $t0) * 1000, 1)];

                continue;
            }
            if ($code !== Command::PREPARE_DATA) {
                $out[] = ['offset' => $offset, 'got' => 0, 'err' => Command::name($code),
                    'ms' => round((microtime(true) - $t0) * 1000, 1)];

                continue;
            }
            $d = $this->drain($want, $emptyPolls);
            $out[] = [
                'offset' => $offset,
                'got' => strlen($d['buf']),
                'ttfb' => $d['ttfb'],
                'datagrams' => $d['datagrams'],
                'ms' => round((microtime(true) - $t0) * 1000, 1),
            ];
            // consume this window's trailing ack
            stream_set_timeout($this->sock, 0, (int) (POLL_SECONDS * 1e6));
            @fread($this->sock, 8192);
            stream_set_timeout($this->sock, 8, 0);
        }
        $this->cmd(Command::FREE_DATA);

        return ['ok' => true, 'size' => $size, 'probes' => $out];
    }

    /** PREPARE_BUFFER without the FREE_DATA — the staging must survive the read. */
    private function stagedSize(): array
    {
        $r = $this->cmd(Command::PREPARE_BUFFER, pack('C', 1).pack('v', Command::ATTLOG_RRQ).pack('V', 0).pack('V', 0));
        $code = $r['command'] ?? 0;
        if ($code !== Command::ACK_OK || strlen($r['data'] ?? '') < 5) {
            return ['ok' => false, 'why' => 'staging refused: '.Command::name($code), 'code' => $code];
        }

        return ['ok' => true, 'size' => unpack('V', substr($r['data'], 1, 4))[1]];
    }
}
