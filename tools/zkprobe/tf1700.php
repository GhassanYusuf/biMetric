<?php

/*
 * ZKTeco TF1700 probe — the STANDALONE binary protocol carried over TCP 4370.
 *
 * Subject: Glass Door, 192.168.7.60:4370, recorded in docs/PROJECT-STATE.md as
 * "Never answered. Pings; protocol silent." That entry is about to be corrected:
 * the owner identified the hardware as a TF1700, and a TF1700 is a standalone
 * fingerprint access-control reader — the same protocol family as the F18, NOT a
 * C3/PullSDK panel (the MAC OUI 00:17:61 is ZKTeco's company-wide OUI and says
 * nothing about the family; that inference in PROJECT-STATE was weak).
 *
 * What makes it look silent is the transport, not the protocol:
 *
 *   UDP 4370 -> ICMP port unreachable. The port is genuinely not open.
 *   TCP 4370 -> accepts the connection.
 *
 * The library speaks this protocol over UDP only (src/Transport/UdpTransport.php),
 * so every attempt so far has been the right words down the wrong pipe. Over TCP
 * the same packets are wrapped in the 8-byte "top" frame that Packet::wrapTcp()
 * has always built and nothing has ever used:
 *
 *   50 50 82 7D <uint32 length> | <cmd(2) checksum(2) session(2) reply(2)> <payload>
 *
 * Note: this file names a model because rule 15 requires model-named files, but
 * nothing in it is TF1700-specific. It is the standalone protocol over TCP, and
 * the control run proves that by speaking the identical code to the F18.
 *
 * References (rule 17 — research before reverse-engineering):
 *   https://github.com/fananimi/pyzk               — create_tcp_top / test_tcp_top
 *   https://github.com/adrobinoga/zk-protocol      — the standalone command table
 * Both document the TCP wrapper as magic 0x5050 0x7D82, uint32 payload length.
 *
 * READ-ONLY. Every command sent here is a read or a session verb:
 *   1000 CONNECT          opens a session
 *   1102 AUTH             answers ACK_UNAUTH; sends the scrambled comm key, no write
 *   1100 VERSION          firmware string
 *     11 DEVICE           named parameter read (~DeviceName, ~SerialNumber, …)
 *    201 GET_TIME         device clock
 *     50 GET_FREE_SIZES   record counters
 *   1503 PREPARE_BUFFER   asks the device to STAGE a table and report its size
 *   1502 FREE_DATA        releases that staged copy
 *   1001 EXIT             closes the session
 * Deliberately NOT sent: 1003 DISABLE_DEVICE (takes the terminal out of service
 * for whoever is standing at it), 14/15 CLEAR_*, 8 USER_WRQ, 18 DELETE_USER,
 * 202 SET_TIME, 1004 RESTART. Nothing here changes a byte on the device.
 *
 * Usage:
 *   php tf1700.php ports   [host]   TCP/UDP reachability on 4370, nothing else
 *   php tf1700.php info    [host]   connect, identity, counters
 *   php tf1700.php tables  [host]   staged sizes for attendance/users/templates
 *   php tf1700.php all     [host]   all of the above in one trace
 *   php tf1700.php control          the SAME code against the F18 on .62 (rule 16)
 */

require_once __DIR__.'/../../vendor/autoload.php';

use Promoseven\Biometric\Protocol\Command;
use Promoseven\Biometric\Protocol\Packet;

const DEFAULT_HOST = '192.168.7.60';
const DEFAULT_PORT = 4370;
const F18_CONTROL_HOST = '192.168.7.62';

/*
 * The TCP "top" frame, transcribed literally from pyzk's __create_tcp_top /
 * __test_tcp_top and its const.py — NOT taken from this library's own
 * Packet::wrapTcp(), which is dead code that has never spoken to a device.
 *
 * That distinction earned its keep on the first run: Packet::wrapTcp() writes
 * ZERO for the second word, so the device saw 50 50 00 00 and answered nothing
 * at all for the full 8-second timeout. Two words, both magic:
 *
 *   MACHINE_PREPARE_DATA_1 = 20560 = 0x5050
 *   MACHINE_PREPARE_DATA_2 = 32130 = 0x7D82
 *
 * Transcribing rather than reusing is why "no reply" was read as our bug and not
 * as the device being dead — which is exactly how .60 came to be recorded as
 * silent for weeks.
 */
const TCP_MAGIC_1 = 0x5050;
const TCP_MAGIC_2 = 0x7D82;

function tcpTop(string $packet): string
{
    return pack('vvV', TCP_MAGIC_1, TCP_MAGIC_2, strlen($packet)).$packet;
}

/** Self-describing trace file, per rule 16. Same shape as inbio460.php's. */
final class TcpTrace
{
    public array $steps = [];

    public function __construct(
        public string $label,
        public string $host,
        public int $port,
    ) {}

    public function step(string $name, array $detail): void
    {
        $this->steps[] = ['step' => $name] + $detail;
        $status = ($detail['ok'] ?? false) ? 'ok' : 'FAIL';
        printf("  %-30s %s\n", $name, $status);
    }

    public function write(): string
    {
        $dir = __DIR__.'/traces-'.date('Y-m-d');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $n = 1;
        while (file_exists($path = sprintf('%s/%s-%02d.json', $dir, $this->label, $n))) {
            $n++;
        }

        file_put_contents($path, json_encode([
            'label' => $this->label,
            'host' => $this->host,
            'port' => $this->port,
            'protocol' => 'zk-standalone-over-tcp',
            'started_at' => date('c'),
            'steps' => $this->steps,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}

/**
 * One session of the standalone protocol over a TCP stream.
 *
 * Deliberately does NOT use the library: the point of a probe is to find out what
 * the device does, and borrowing the library's assumptions is how a device gets
 * measured through them. Packet is reused because the framing and the checksum
 * are already proven against three terminals — a mismatch here would be a finding
 * about the device, not about our arithmetic (rule 17, step 3).
 */
final class TcpSession
{
    private $sock = null;

    private int $sessionId = 0;

    private int $replyId = 0;

    public function __construct(
        private string $host,
        private int $port,
        private TcpTrace $trace,
        private float $timeout = 8.0,
    ) {}

    public function open(): bool
    {
        $started = microtime(true);
        $this->sock = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
        );

        $ms = round((microtime(true) - $started) * 1000, 1);

        if (! $this->sock) {
            $this->trace->step('tcp connect', ['ok' => false, 'errno' => $errno, 'error' => $errstr, 'ms' => $ms]);

            return false;
        }

        stream_set_timeout($this->sock, (int) $this->timeout);
        $this->trace->step('tcp connect', ['ok' => true, 'ms' => $ms]);

        return true;
    }

    public function close(): void
    {
        if ($this->sock) {
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    /**
     * Sends one command inside the TCP top frame and reads one reply.
     *
     * @return array{ok:bool,reply:?int,reply_name:?string,session:?int,data:string,ms:float,error?:string}
     */
    public function cmd(int $command, string $data = ''): array
    {
        [$packet, $this->replyId] = Packet::build($command, $this->sessionId, $this->replyId, $data);

        $started = microtime(true);
        @fwrite($this->sock, tcpTop($packet));

        $reply = $this->readFramed();
        $ms = round((microtime(true) - $started) * 1000, 1);

        if ($reply === null) {
            return ['ok' => false, 'reply' => null, 'reply_name' => null, 'session' => null, 'data' => '', 'ms' => $ms, 'error' => 'no reply'];
        }

        $parsed = Packet::parse($reply);

        return [
            'ok' => in_array($parsed['command'], Command::ACCEPTED, true),
            'reply' => $parsed['command'],
            'reply_name' => Command::name($parsed['command']),
            'session' => $parsed['sessionId'],
            'data' => $parsed['data'],
            'ms' => $ms,
        ];
    }

    /**
     * Reads one complete top-framed packet.
     *
     * The length in the header is authoritative — a TCP stream will happily hand
     * back a short read, and treating one fread() as one packet is the classic way
     * to "discover" a truncated protocol that is not truncated at all.
     */
    private function readFramed(): ?string
    {
        $header = $this->readExactly(8);
        if ($header === null) {
            return null;
        }

        $top = unpack('vmagic1/vmagic2/Vlength', $header);

        if ($top['magic1'] !== TCP_MAGIC_1 || $top['magic2'] !== TCP_MAGIC_2) {
            // Worth recording rather than swallowing: a device that answers with a
            // different frame is answering something, just not this protocol.
            $this->trace->step('bad tcp top frame', [
                'ok' => false,
                'magic' => sprintf('0x%04X 0x%04X', $top['magic1'], $top['magic2']),
                'expected' => sprintf('0x%04X 0x%04X', TCP_MAGIC_1, TCP_MAGIC_2),
            ]);

            return null;
        }

        return $this->readExactly($top['length']);
    }

    private function readExactly(int $want): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $want) {
            $chunk = @fread($this->sock, $want - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                if ($meta['timed_out'] ?? false) {
                    return null;
                }

                return $buffer === '' ? null : $buffer;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * CONNECT, and the AUTH exchange when the device asks for it.
     *
     * Camelot taught this: ACK_UNAUTH (2005) does not mean "wrong key", it means
     * "perform the AUTH exchange" — and it does so even when the key is 0.
     */
    public function connect(int $commKey = 0): bool
    {
        $res = $this->cmd(Command::CONNECT);
        $this->sessionId = $res['session'] ?? 0;

        $this->trace->step('connect', [
            'ok' => $res['ok'],
            'reply' => $res['reply_name'],
            'code' => $res['reply'],
            'session' => $this->sessionId,
            'ms' => $res['ms'],
        ] + (isset($res['error']) ? ['error' => $res['error']] : []));

        if ($res['reply'] === Command::ACK_UNAUTH) {
            $auth = $this->cmd(Command::AUTH, Packet::makeCommKey($commKey, $this->sessionId));
            $this->trace->step('auth (comm key '.$commKey.')', [
                'ok' => $auth['ok'],
                'reply' => $auth['reply_name'],
                'code' => $auth['reply'],
                'ms' => $auth['ms'],
            ]);

            return $auth['ok'];
        }

        return $res['ok'];
    }

    public function disconnect(): void
    {
        if ($this->sock && $this->sessionId) {
            $this->cmd(Command::EXIT);
        }
        $this->close();
    }

    /** A named parameter read — the device answers "~DeviceName=TF1700". */
    public function param(string $name): ?string
    {
        $res = $this->cmd(Command::DEVICE, $name."\0");
        if (! $res['ok']) {
            return null;
        }

        $value = rtrim($res['data'], "\0");

        return str_contains($value, '=') ? substr($value, strpos($value, '=') + 1) : $value;
    }

    /**
     * Asks the device to stage a table and report its size, then releases it.
     *
     * PREPARE_BUFFER is a read: the device makes its own copy and tells us how
     * big it is. FREE_DATA drops that copy. Neither touches stored records — this
     * is the same negotiation every attendance read already performs.
     */
    public function stagedSize(int $table, string $payload = ''): array
    {
        $request = chr(1).chr(0).pack('v', $table).$payload;
        $res = $this->cmd(Command::PREPARE_BUFFER, $request);

        if (! $res['ok'] || strlen($res['data']) < 5) {
            return ['ok' => false, 'reply' => $res['reply_name'], 'code' => $res['reply'], 'ms' => $res['ms']];
        }

        $size = unpack('Vsize', substr($res['data'], 1, 4))['size'];
        $this->cmd(Command::FREE_DATA);

        return ['ok' => true, 'size' => $size, 'ms' => $res['ms']];
    }

    public function sessionId(): int
    {
        return $this->sessionId;
    }
}

/** TCP and UDP reachability on 4370, recorded as evidence rather than assumed. */
function probePorts(string $host, TcpTrace $trace): void
{
    $tcp = @stream_socket_client("tcp://{$host}:".DEFAULT_PORT, $errno, $errstr, 3);
    $trace->step('tcp/4370 reachable', [
        'ok' => (bool) $tcp,
        'errno' => $errno,
        'error' => $errstr ?: null,
    ]);
    if ($tcp) {
        fclose($tcp);
    }

    // A UDP "connection" never fails at connect time; the refusal arrives as an
    // ICMP port-unreachable on the first read. Send a real CONNECT so a device
    // that DOES speak UDP answers instead of looking dead.
    $udp = @stream_socket_client("udp://{$host}:".DEFAULT_PORT, $uerrno, $uerrstr, 3);
    $udpAnswered = false;
    $udpError = 'no reply within 3s';

    if ($udp) {
        stream_set_timeout($udp, 3);
        [$packet] = Packet::build(Command::CONNECT, 0, 0);
        @fwrite($udp, $packet);
        $reply = @fread($udp, 1024);
        if ($reply !== false && $reply !== '') {
            $udpAnswered = true;
        } else {
            $meta = stream_get_meta_data($udp);
            $udpError = ($meta['timed_out'] ?? false) ? 'timed out' : 'ICMP port unreachable / closed';
        }
        fclose($udp);
    }

    $trace->step('udp/4370 answers CONNECT', [
        'ok' => $udpAnswered,
        'error' => $udpAnswered ? null : $udpError,
    ]);
}

/** Identity, clock and counters. */
function probeInfo(TcpSession $session, TcpTrace $trace): void
{
    $version = $session->cmd(Command::VERSION);
    $trace->step('version', [
        'ok' => $version['ok'],
        'firmware' => rtrim($version['data'], "\0") ?: null,
        'reply' => $version['reply_name'],
        'ms' => $version['ms'],
    ]);

    foreach (['~DeviceName', '~SerialNumber', '~ZKFPVersion', '~Platform', '~OS', 'DeviceID', 'MAC', '~ExtendFmt', 'FaceFunOn'] as $name) {
        $value = $session->param($name);
        $trace->step('param '.$name, ['ok' => $value !== null && $value !== '', 'value' => $value]);
    }

    $time = $session->cmd(Command::GET_TIME);
    $trace->step('device time', [
        'ok' => $time['ok'] && strlen($time['data']) >= 4,
        'encoded' => $time['ok'] && strlen($time['data']) >= 4
            ? unpack('Vt', substr($time['data'], 0, 4))['t']
            : null,
        'ms' => $time['ms'],
    ]);

    $sizes = $session->cmd(Command::GET_FREE_SIZES);
    $counters = null;
    if ($sizes['ok'] && strlen($sizes['data']) >= 80) {
        $fields = array_values(unpack('V20', substr($sizes['data'], 0, 80)));
        // Offsets per the documented PREPARE_DATA/GET_FREE_SIZES layout: the
        // record counters sit at word indices 4, 6, 8 and the capacities at
        // 14, 15, 16 (pyzk read_sizes()).
        $counters = [
            'users' => $fields[4],
            'fingers' => $fields[6],
            'records' => $fields[8],
            'user_capacity' => $fields[14],
            'finger_capacity' => $fields[15],
            'record_capacity' => $fields[16],
        ];
    }
    $trace->step('free sizes', ['ok' => $counters !== null, 'bytes' => strlen($sizes['data']), 'counters' => $counters]);
}

/** What the device is willing to stage, per table. Support is per table (F22). */
function probeTables(TcpSession $session, TcpTrace $trace): void
{
    $tables = [
        'attendance' => [Command::ATTLOG_RRQ, ''],
        'users' => [Command::USERTEMP_RRQ, ''],
        'templates' => [Command::DB_RRQ, pack('v', Command::FCT_FINGERTMP)],
    ];

    foreach ($tables as $label => [$table, $payload]) {
        $res = $session->stagedSize($table, $payload);
        $trace->step('stage '.$label, $res);
    }
}

// ---------------------------------------------------------------------------

$mode = $argv[1] ?? 'all';
$host = $argv[2] ?? DEFAULT_HOST;

if ($mode === 'control') {
    $mode = 'all';
    $host = F18_CONTROL_HOST;
    echo "CONTROL RUN — same code, F18 on {$host} (rule 16)\n";
}

$trace = new TcpTrace('tf1700-'.$mode.'-'.str_replace('.', '_', $host), $host, DEFAULT_PORT);

echo "TF1700 probe — {$mode} — {$host}:".DEFAULT_PORT." (READ-ONLY)\n";

if ($mode === 'ports' || $mode === 'all') {
    probePorts($host, $trace);
}

if ($mode !== 'ports') {
    $session = new TcpSession($host, DEFAULT_PORT, $trace);

    if (! $session->open()) {
        echo "\ntrace: ".$trace->write()."\n";
        exit(1);
    }

    if (! $session->connect(0)) {
        echo "\nDevice refused the session. Trace holds the reply code.\n";
        echo "trace: ".$trace->write()."\n";
        $session->close();
        exit(1);
    }

    if ($mode === 'info' || $mode === 'all') {
        probeInfo($session, $trace);
    }

    if ($mode === 'tables' || $mode === 'all') {
        probeTables($session, $trace);
    }

    $session->disconnect();
}

echo "\ntrace: ".$trace->write()."\n";
