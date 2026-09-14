<?php

/*
 * Instrumented ZKTeco bulk-read harness.
 *
 * Purpose: capture WHAT ACTUALLY HAPPENS ON THE WIRE during a bulk read, in
 * enough detail to tell the three failure causes apart:
 *
 *   1. the kernel dropped datagrams   -> RcvbufErrors climbs, arrivals are gappy
 *   2. the device stopped sending     -> RcvbufErrors flat, arrivals just end
 *   3. the network lost them in transit -> RcvbufErrors flat, arrivals gappy
 *
 * Byte counts alone cannot distinguish these, which is why every datagram is
 * timestamped and every kernel counter is sampled either side.
 *
 * READ-ONLY. ATTLOG_RRQ / PREPARE_BUFFER / READ_BUFFER never mutate the device.
 * FREE_DATA only releases a staged copy the device made for us.
 *
 * Usage:
 *   php harness.php <host> <mode> <runs> <label>
 *     mode = push | windowed | both
 */

require __DIR__.'/../../vendor/autoload.php';

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

            return true;
        }
        if ($code !== Command::ACK_UNAUTH) {
            return false;
        }

        $this->sessionId = $r['sessionId'];
        $r = $this->cmd(Command::AUTH, Packet::makeCommKey($commKey, $this->sessionId));

        return ($r['command'] ?? 0) === Command::ACK_OK;
    }

    public function disconnect(): void
    {
        $this->cmd(Command::EXIT);
    }

    /**
     * Drains datagrams until $want payload bytes have arrived or the device goes
     * quiet. Records an entry per datagram: monotonic offset, size, queue depth.
     */
    private function drain(int $want, int $maxEmpty): string
    {
        stream_set_timeout($this->sock, 0, (int) (POLL_SECONDS * 1e6));
        $buf = '';
        $empty = 0;
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

        return $buf;
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
        $buf = $this->drain($size, MAX_EMPTY_POLLS);

        return [
            'ok' => strlen($buf) >= $size,
            'size' => $size,
            'got' => strlen($buf),
            'ms' => round((microtime(true) - $t0) * 1000),
        ];
    }

    /** Flow-controlled windowed read: one explicitly requested window at a time. */
    public function windowedRead(): array
    {
        $r = $this->cmd(Command::PREPARE_BUFFER, pack('C', 1).pack('v', Command::ATTLOG_RRQ).pack('V', 0).pack('V', 0));
        $code = $r['command'] ?? 0;
        if ($code !== Command::ACK_OK || strlen($r['data'] ?? '') < 5) {
            return ['ok' => false, 'why' => 'staging refused: '.Command::name($code), 'code' => $code];
        }
        $size = unpack('V', substr($r['data'], 1, 4))[1];

        $t0 = microtime(true);
        $buf = '';
        $windows = [];
        while (strlen($buf) < $size) {
            $want = min(WINDOW_BYTES, $size - strlen($buf));
            $start = strlen($buf);
            $wt0 = microtime(true);
            $r = $this->cmd(Command::READ_BUFFER, pack('VV', $start, $want));
            $wcode = $r['command'] ?? 0;

            if ($wcode === Command::DATA) {
                $buf .= substr($r['data'], 0, $want);
                $windows[] = ['start' => $start, 'want' => $want, 'got' => $want, 'inline' => true,
                    'ms' => round((microtime(true) - $wt0) * 1000, 1)];

                continue;
            }
            if ($wcode !== Command::PREPARE_DATA) {
                $windows[] = ['start' => $start, 'want' => $want, 'got' => 0, 'err' => Command::name($wcode)];
                break;
            }
            $win = $this->drain($want, 10);
            $windows[] = ['start' => $start, 'want' => $want, 'got' => strlen($win),
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
        ];
    }
}

// ---------------------------------------------------------------------------

$host = $argv[1] ?? '87.237.196.60';
$mode = $argv[2] ?? 'both';
$runs = (int) ($argv[3] ?? 5);
$label = $argv[4] ?? 'run';

$outDir = __DIR__.'/traces';
@mkdir($outDir, 0775, true);

$modes = $mode === 'both' ? ['push', 'windowed'] : [$mode];

foreach (range(1, $runs) as $i) {
    foreach ($modes as $m) {
        $probe = new Probe($host);
        $before = udpCounters();
        $wall = microtime(true);

        try {
            $probe->open();
            if (! $probe->connect()) {
                echo "$label run$i $m: HANDSHAKE FAILED\n";
                $probe->close();

                continue;
            }
            $res = $m === 'push' ? $probe->pushRead() : $probe->windowedRead();
            $probe->disconnect();
        } catch (Throwable $e) {
            $res = ['ok' => false, 'why' => $e->getMessage()];
        }
        $probe->close();
        $after = udpCounters();

        $delta = [];
        foreach ($after as $k => $v) {
            if (($before[$k] ?? 0) !== $v) {
                $delta[$k] = $v - ($before[$k] ?? 0);
            }
        }

        $trace = [
            'label' => $label,
            'host' => $host,
            'mode' => $m,
            'run' => $i,
            'started_at' => date('c', (int) $wall),
            'granted_rcvbuf' => $probe->granted,
            'result' => $res,
            'udp_counter_delta' => $delta,
            'arrivals' => $probe->arrivals,
        ];

        $file = sprintf('%s/%s-%s-%02d.json', $outDir, $label, $m, $i);
        file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT));

        printf(
            "%-10s run%-3d %-9s %-7s %7d/%-7d %6dms  rcvbuf_err=%-4s arrivals=%d  %s\n",
            $label, $i, $m,
            ($res['ok'] ?? false) ? 'OK' : 'FAIL',
            $res['got'] ?? 0, $res['size'] ?? 0, $res['ms'] ?? 0,
            $delta['RcvbufErrors'] ?? 0,
            count($probe->arrivals),
            $res['why'] ?? ''
        );
    }
}
