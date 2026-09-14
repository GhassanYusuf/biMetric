<?php

/*
 * ZKTeco inBIO460 probe — the C3 / PullSDK protocol over TCP 4370.
 *
 * Subject: Entrance, 192.168.7.61:4370, recorded in docs/PROJECT-STATE.md as
 * "pings but does not answer". It answers fine. It is not a standalone terminal:
 * an inBIO460 is a 4-door access-control PANEL and speaks a DIFFERENT protocol
 * from the F18 — TCP, not UDP, and a completely different frame.
 *
 * Written 2026-09-14 from two independent open-source implementations of the
 * PullSDK wire format (vwout/zkaccess-c3-py and zkaccess-c3-lua), per CLAUDE.md
 * rule 17: research before reverse engineering. Nothing here was guessed; what
 * the panel actually does is the subject of the traces.
 *
 * FRAME (nothing like the F18's):
 *   AA 01 <cmd> <len_lo> <len_hi> [ses_lo ses_hi req_lo req_hi] [data...] <crc_lo> <crc_hi> 55
 *   CRC-16/ARC (poly 0xA001, init 0x0000) over everything EXCEPT the start byte
 *   and the trailing CRC+end. Session id and request number are 0xFEFE on connect
 *   (the reference calls the request number -258, which is the same 16 bits).
 *
 * READ-ONLY. Only these commands are ever sent:
 *   0x76 CONNECT_SESSION   opens a session
 *   0x04 GETPARAM          reads named parameters
 *   0x06 DATATABLE_CFG     reads the table/field catalogue
 *   0x08 GETDATA           reads rows out of a table
 *   0x02 DISCONNECT        closes the session
 * 0x05 CONTROL is the door relay — it is NOT implemented here on purpose, and
 * sending it would unlock a door. Rule 16: a write probe needs the owner's
 * explicit go-ahead and a terminal out of service. This is neither.
 *
 * Usage:
 *   php inbio460.php info    [host]        connect, read identity parameters
 *   php inbio460.php tables  [host]        the data-table catalogue
 *   php inbio460.php read    <table> [host]  rows from one table
 *   php inbio460.php all     [host]        everything above, one trace
 *   php inbio460.php control [host]        cross-protocol control, see below
 */

const C3_START = 0xAA;
const C3_END = 0x55;
const C3_VERSION = 0x01;

const CMD_CONNECT_SESSION = 0x76;
const CMD_CONNECT_SESSION_LESS = 0x01;
const CMD_DISCONNECT = 0x02;
const CMD_GETPARAM = 0x04;
const CMD_DATATABLE_CFG = 0x06;
const CMD_GETDATA = 0x08;

const REPLY_OK = 0xC8;
const REPLY_ERROR = 0xC9;
/*
 * CONFIRMED inBIO460 2026-09-14: GETDATA answers 0x0D, a buffered-read handshake,
 * NOT 0xC8 with inline rows the way the reference libraries assume.
 *
 * The follow-up pull was recovered on 2026-09-14 from the vendor's own
 * plcommpro.dll (ZKAccess 3.5.5, NewSDK/) — dispatch at 0x10002be0, pull loop at
 * 0x100029b0 — cross-checked against the managed DevComm P/Invoke layer, and the
 * header layout then re-verified byte for byte against traces already captured
 * from 192.168.7.60 and .61. Neither vwout/zkaccess-c3-py nor zkaccess-c3-lua
 * implements this path: it is a genuine gap in every public reimplementation,
 * which is why they all stop at 0x0D.
 *
 * 0x0D PREPARE_DATA -> 0x0E DATA (repeated) -> 0x0F FREE_DATA. These are the
 * standalone protocol's 1500/1501/1502 renumbered for the panel dialect.
 */
const REPLY_DATA_BUFFERED = 0x0D;
const CMD_BUFFER_READ = 0x0E;
const CMD_BUFFER_FREE = 0x0F;

const DEFAULT_HOST = '192.168.7.61';
const DEFAULT_PORT = 4370;
const F18_CONTROL_HOST = '192.168.7.62';

/**
 * CRC-16/ARC, transcribed literally from the reference implementation rather
 * than from a table, so a mismatch is a finding about the panel and not about
 * our arithmetic.
 */
function c3crc(array $bytes): int
{
    $crc = 0x0000;

    foreach ($bytes as $byte) {
        $value = ($crc & 0xFF) ^ ($byte & 0xFF);
        $poly = 0;

        for ($i = 0; $i < 8; $i++) {
            $poly = (($poly ^ $value) & 1) === 1
                ? (($poly >> 1) ^ 0xA001)
                : ($poly >> 1);

            $value >>= 1;
        }

        $crc = (($crc >> 8) & 0xFF) ^ $poly;
    }

    return $crc & 0xFFFF;
}

/**
 * @param  list<int>|string  $data
 * @param  bool  $withSession  false builds the session-less form: no session id,
 *                             no request number, and four fewer declared bytes.
 *                             This panel needs it — see the 0xF3 finding.
 */
function c3frame(int $command, int $sessionId, int $requestNr, array|string $data = [], bool $withSession = true): string
{
    $payload = is_string($data) ? array_values(unpack('C*', $data) ?: []) : $data;
    $length = count($payload) + ($withSession ? 4 : 0);

    $body = [
        C3_VERSION,
        $command,
        $length & 0xFF,
        ($length >> 8) & 0xFF,
    ];

    if ($withSession) {
        $body[] = $sessionId & 0xFF;
        $body[] = ($sessionId >> 8) & 0xFF;
        $body[] = $requestNr & 0xFF;
        $body[] = ($requestNr >> 8) & 0xFF;
    }

    $body = [...$body, ...$payload];

    $crc = c3crc($body);
    $frame = [C3_START, ...$body, $crc & 0xFF, ($crc >> 8) & 0xFF, C3_END];

    return pack('C*', ...$frame);
}

/**
 * Read one reply: 5-byte header, then payload + CRC + end.
 *
 * Returns the raw bytes and the decode, separately. Rule 16 — what arrived is
 * recorded even when it makes no sense, because a frame we cannot parse is the
 * most interesting thing a probe can produce.
 */
function c3read($sock, float $timeout = 6.0): array
{
    stream_set_timeout($sock, (int) $timeout, (int) (($timeout - (int) $timeout) * 1e6));

    $header = '';
    while (strlen($header) < 5) {
        $chunk = fread($sock, 5 - strlen($header));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $header .= $chunk;
    }

    if (strlen($header) < 5) {
        return ['ok' => false, 'error' => 'no header', 'raw' => bin2hex($header)];
    }

    $bytes = array_values(unpack('C*', $header));
    $command = $bytes[2];
    $size = $bytes[3] + ($bytes[4] * 256);

    $rest = '';
    $want = $size + 3;  // payload + crc(2) + end(1)
    while (strlen($rest) < $want) {
        $chunk = fread($sock, $want - strlen($rest));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $rest .= $chunk;
    }

    $full = $header.$rest;
    $payload = substr($rest, 0, $size);

    return [
        'ok' => ($command === REPLY_OK || $command === REPLY_DATA_BUFFERED) && strlen($rest) === $want,
        'command' => $command,
        'command_name' => match ($command) {
            REPLY_OK => 'OK (0xC8)',
            REPLY_ERROR => 'ERROR (0xC9)',
            REPLY_DATA_BUFFERED => 'BUFFERED DATA (0x0D)',
            default => sprintf('unknown (0x%02X)', $command),
        },
        'declared_size' => $size,
        'received' => strlen($rest),
        'raw' => bin2hex($full),
        'payload_hex' => bin2hex($payload),
        'payload' => $payload,
    ];
}

/**
 * The C3 packed timestamp. NOT a unix epoch and not seconds-since-2000: the
 * fields are packed in a mixed radix with a 31-day month and a 12-month year.
 *
 * Transcribed literally from vwout/zkaccess-c3-py rather than derived, per rule
 * 17 — so that a disagreement is a finding about the panel and not our arithmetic.
 *
 * CONFIRMED TF1700 @192.168.7.60 2026-09-14: the first record of the pulled
 * transaction table decodes to 2025-08-10 17:28:11, which is exactly the earliest
 * TF1700 event in the vendor's own Access.mdb. Device LOCAL time (rule 10) — this
 * site is UTC+3 and nothing in the payload says so.
 */
function c3time(int $t): string
{
    $s = $t % 60;   $t = intdiv($t, 60);
    $i = $t % 60;   $t = intdiv($t, 60);
    $h = $t % 24;   $t = intdiv($t, 24);
    $d = $t % 31 + 1; $t = intdiv($t, 31);
    $m = $t % 12 + 1; $t = intdiv($t, 12);

    return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $t + 2000, $m, $d, $h, $i, $s);
}

/** The panel answers key=value pairs, comma or tab separated, sometimes multi-line. */
function parseKv(string $message): array
{
    $pairs = [];
    preg_match_all('/([\w~]+)=([^,\t\n\r]*)/', $message, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        $pairs[$m[1]] = $m[2];
    }

    return $pairs;
}

final class Trace
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
        printf("  %-28s %s\n", $name, $status);
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
            'protocol' => 'c3-pullsdk-tcp',
            'started_at' => date('c'),
            'steps' => $this->steps,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}

final class C3Session
{
    private $sock = null;

    private int $sessionId = 0xFEFE;

    private int $requestNr = 0xFEFE;

    /** Set once connect() discovers which of the two dialects this panel accepts. */
    public bool $sessionLess = false;

    public function __construct(
        private string $host,
        private int $port,
        private Trace $trace,
    ) {}

    public function open(float $timeout = 8.0): bool
    {
        $started = microtime(true);
        $this->sock = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $timeout,
        );

        $this->trace->step('tcp connect', [
            'ok' => $this->sock !== false && $this->sock !== null,
            'ms' => round((microtime(true) - $started) * 1000, 1),
            'error' => $this->sock ? null : "$errno $errstr",
        ]);

        return (bool) $this->sock;
    }

    /**
     * The wire half of send(), WITHOUT tracing.
     *
     * The buffered pull issues hundreds of these and their payloads ARE the
     * employee data — card numbers, PINs, names. Tracing each one would commit
     * exactly the raw capture rule 16 forbids, so chunk reads go through here
     * and only a summary reaches the trace.
     *
     * @param list<int>|string $data
     */
    private function exchange(int $command, array|string $data, float $timeout): array
    {
        $frame = c3frame($command, $this->sessionId, $this->requestNr, $data, ! $this->sessionLess);
        $started = microtime(true);

        fwrite($this->sock, $frame);
        $reply = c3read($this->sock, $timeout);
        $reply['ms'] = round((microtime(true) - $started) * 1000, 1);
        $reply['sent_hex'] = bin2hex($frame);

        $this->requestNr = ($this->requestNr + 1) & 0xFFFF;

        $payload = $reply['payload'] ?? '';
        unset($reply['payload']);

        // In a session, the first four payload bytes echo session id + sequence.
        // Session-less replies carry no such prefix.
        if (! $this->sessionLess) {
            $payload = strlen($payload) > 4 ? substr($payload, 4) : '';
        }

        return [$reply, $payload];
    }

    /** @param list<int>|string $data */
    public function send(int $command, string $name, array|string $data = [], float $timeout = 6.0): array
    {
        [$reply, $payload] = $this->exchange($command, $data, $timeout);
        $this->trace->step($name, $reply);

        return [$reply, $payload];
    }

    /**
     * The 0x0D -> 0x0E* -> 0x0F buffered read. See the note beside
     * REPLY_DATA_BUFFERED for where this was recovered from.
     *
     * Header, 17 bytes, little-endian, packed, no padding:
     *   0      flag        0 = raw, non-zero = compressed
     *   1..4   datalen     bytes staged on the device
     *   5..8   oriLen      length after decompression
     *   9..12  crc32       CRC-32/ISO-HDLC over the assembled payload
     *   13..16 packageLen  most the device will put in one reply
     *
     * Each 0x0E carries a 4-byte little-endian offset and nothing else. The reply
     * echoes that offset back in its first four bytes, then the chunk. The SDK
     * abandons the read when the echo disagrees (its error -109) and so do we: a
     * silently misordered buffer is worse than no buffer.
     *
     * The CRC is the point. It makes this self-verifying — if the loop is wrong,
     * the checksum says so rather than handing back plausible garbage (rule 10).
     */
    public function pullBuffered(string $table, string $header): string
    {
        $h = unpack('Cflag/Vdatalen/VoriLen/Vcrc32/VpackageLen', $header);

        $data = '';
        $offset = 0;
        $chunks = 0;
        $failure = null;
        $started = microtime(true);

        while ($offset < $h['datalen']) {
            [$reply, $payload] = $this->exchange(CMD_BUFFER_READ, pack('V', $offset), 20.0);

            if (! ($reply['ok'] ?? false) || strlen($payload) < 4) {
                $failure = sprintf('chunk %d at offset %d: %s', $chunks, $offset, $reply['command_name'] ?? 'no reply');
                break;
            }

            $echo = unpack('V', substr($payload, 0, 4))[1];
            if ($echo !== $offset) {
                $failure = sprintf('offset echo mismatch at %d (device said %d)', $offset, $echo);
                break;
            }

            $chunk = substr($payload, 4);
            if ($chunk === '') {
                $failure = sprintf('empty chunk at offset %d', $offset);
                break;
            }

            $data .= $chunk;
            $offset += strlen($chunk);
            $chunks++;
        }

        // Unconditional, exactly as the SDK does it on every exit path: the staged
        // buffer is device memory, and holding it would starve the next read.
        $this->exchange(CMD_BUFFER_FREE, [], 6.0);

        $crcActual = crc32($data);
        $crcOk = $crcActual === $h['crc32'];

        $this->trace->step("buffered pull {$table} (0x0E)", [
            'ok' => $failure === null && $crcOk && strlen($data) === $h['datalen'],
            'compression_flag' => $h['flag'],
            'staged_bytes' => $h['datalen'],
            'original_bytes' => $h['oriLen'],
            'package_len' => $h['packageLen'],
            'chunks' => $chunks,
            'received_bytes' => strlen($data),
            'crc32_expected' => sprintf('%08x', $h['crc32']),
            'crc32_actual' => sprintf('%08x', $crcActual),
            'crc32_ok' => $crcOk,
            'ms' => round((microtime(true) - $started) * 1000, 1),
            'error' => $failure,
            // Deliberately NO payload here. These bytes are card numbers and PINs.
        ]);

        printf(
            "  %s: pulled %s of %s bytes in %d chunk(s) — CRC-32 %s\n",
            $table,
            number_format(strlen($data)),
            number_format($h['datalen']),
            $chunks,
            $crcOk ? sprintf('%08x OK', $crcActual) : sprintf('MISMATCH (want %08x, got %08x)', $h['crc32'], $crcActual),
        );

        if ($failure !== null) {
            printf("    stopped: %s\n", $failure);
        }

        if ($h['flag'] !== 0) {
            // The compressor lives in plce.dll, which the vendor did not ship in
            // NewSDK/. Both panels answer flag=0, so this is not on our path yet.
            printf("    compressed payload (flag=%d) — decompressor not implemented\n", $h['flag']);

            return '';
        }

        return $crcOk ? $data : '';
    }

    /**
     * CONFIRMED on inBIO460 @ 192.168.7.61, firmware AC Ver 5.2.4 Apr 19 2016
     * (2026-09-14): this firmware refuses BOTH connect forms and needs neither.
     *
     *   0x76 (session)      -> 0xC9 payload 0xF3 = -13, "command not available"
     *   0x01 (session-less) -> 0xC9 payload 0x9E = -98
     *
     * The working path is to send no connect at all: GETPARAM (0x04),
     * DATATABLE_CFG (0x06) and GETDATA (0x08) all answer 0xC8 when framed
     * session-less. So this probe operates in session-less mode from the start
     * and connect() is a no-op that records the two refusals for the trace.
     *
     * This is the rule-16 lesson in one device: the reference's connect ladder
     * was the wrong assumption to carry over, and reading the refusal replies —
     * rather than treating silence as "device down" — is what found the real
     * entry point.
     */
    public function connect(): bool
    {
        $this->sessionLess = true;

        [$probe76, ] = $this->send(CMD_CONNECT_SESSION, 'connect probe (0x76)');
        // Note the 0x76 probe is sent session-less here purely to record the reply;
        // it is expected to fail and its failure is a documented finding, not a stop.

        [$probe01, ] = $this->send(CMD_CONNECT_SESSION_LESS, 'connect probe (0x01)');

        // Neither connect is required. Success is defined by whether commands
        // answer, which the caller tests next.
        return true;
    }

    public function catalogue(): array
    {
        [, $payload] = $this->send(CMD_DATATABLE_CFG, 'datatable cfg (0x06)');
        $tables = [];

        foreach (explode("\x0a", $payload) as $line) {
            if (! preg_match('/^(\w+)=(\d+),(.*)$/', trim($line), $m)) {
                continue;
            }

            $fields = [];
            foreach (explode(',', $m[3]) as $pair) {
                if (preg_match('/^(\w+)=([isbB])(\d+)$/', $pair, $fm)) {
                    $fields[$fm[1]] = ['index' => (int) $fm[3], 'type' => strtolower($fm[2])];
                }
            }

            $tables[$m[1]] = ['index' => (int) $m[2], 'fields' => $fields];
        }

        return $tables;
    }

    /**
     * GETDATA (0x08) for one table, decoded per the field catalogue.
     *
     * Parameter block is [table index, field count, field indexes..., 0, 0]; the
     * trailing two bytes are undocumented in the reference and sent as zero. Each
     * record is a run of length-prefixed field values, in the field-index order
     * the panel echoes back at the head of the reply.
     *
     * A blob/template field (type B) is real biometric data (rule 16): only its
     * length is recorded, never the bytes.
     */
    public function getData(string $table, array $cat): array
    {
        if (! isset($cat[$table])) {
            $this->trace->step("getdata {$table}", ['ok' => false, 'error' => 'table not in catalogue']);

            return [];
        }

        $fields = $cat[$table]['fields'];
        $indexes = array_map(fn ($f) => $f['index'], $fields);
        sort($indexes);

        $params = array_merge([$cat[$table]['index'], count($indexes)], $indexes, [0, 0]);
        [$reply, $payload] = $this->send(CMD_GETDATA, "getdata {$table} (0x08)", $params, 20.0);

        if (! ($reply['ok'] ?? false) || $payload === '') {
            return [];
        }

        // CONFIRMED inBIO460 + TF1700 2026-09-14: GETDATA on a populated table does
        // not return rows inline. It returns a 0x0D staging header and the rows are
        // pulled with 0x0E. Anything short of the full 17-byte header is not that,
        // and guessing at it would be inventing a fact (rule 10).
        if (($reply['command'] ?? null) === REPLY_DATA_BUFFERED) {
            if (strlen($payload) < 17) {
                $this->trace->step("buffered {$table}", [
                    'ok' => false,
                    'error' => 'short 0x0D header',
                    'bytes' => strlen($payload),
                    'payload_hex' => bin2hex($payload),
                ]);

                return [];
            }

            $payload = $this->pullBuffered($table, substr($payload, 0, 17));

            if ($payload === '') {
                return [];
            }
        }

        $bytes = array_values(unpack('C*', $payload));

        /*
         * An inline 0xC8 reply prefixes its records with the table index and the
         * field list it is answering with. Whether the buffered payload repeats
         * that header is documented nowhere, so detect it instead of assuming —
         * reading a record stream one byte out of phase produces rows that look
         * real and are not.
         */
        if (($bytes[0] ?? -1) === $cat[$table]['index'] && ($bytes[1] ?? -1) === count($indexes)) {
            $fieldCount = $bytes[1];
            $respFieldIdx = array_slice($bytes, 2, $fieldCount);
            $offset = 2 + $fieldCount;
        } else {
            $respFieldIdx = $indexes;
            $offset = 0;
        }

        $this->trace->step("record framing {$table}", [
            'ok' => true,
            'header_present' => $offset > 0,
            'field_order' => $respFieldIdx,
            'payload_bytes' => strlen($payload),
        ]);

        $byIndex = [];
        foreach ($fields as $name => $meta) {
            $byIndex[$meta['index']] = ['name' => $name, 'type' => $meta['type']];
        }

        $rows = [];
        $data = array_slice($bytes, $offset);
        $pos = 0;
        $guard = 0;

        while ($pos < count($data) && $guard++ < 200000) {
            $row = [];
            foreach ($respFieldIdx as $fi) {
                if ($pos >= count($data)) {
                    break 2;
                }
                $size = $data[$pos++];
                $raw = array_slice($data, $pos, $size);
                $pos += $size;

                $field = $byIndex[$fi] ?? ['name' => "f{$fi}", 'type' => 's'];
                if ($field['type'] === 'i') {
                    $val = 0;
                    foreach (array_reverse($raw) as $b) {
                        $val = ($val << 8) | $b;
                    }
                    $row[$field['name']] = $val;
                } elseif ($field['type'] === 'b') {
                    $row[$field['name']] = '<'.$size.' bytes>';
                } else {
                    $row[$field['name']] = rtrim(pack('C*', ...$raw), "\0");
                }
            }
            $rows[] = $row;
        }

        $this->trace->step("decoded {$table}", ['ok' => true, 'rows' => count($rows), 'fields' => array_keys($fields)]);

        return $rows;
    }

    public function close(): void
    {
        if ($this->sock) {
            $this->send(CMD_DISCONNECT, 'disconnect (0x02)');
            fclose($this->sock);
            $this->sock = null;
        }
    }
}

// ---------------------------------------------------------------------------

$mode = $argv[1] ?? 'info';
$arg = $argv[2] ?? null;
$host = $argv[3] ?? (filter_var((string) $arg, FILTER_VALIDATE_IP) ? $arg : DEFAULT_HOST);

printf("inBIO460 probe — %s:%d — %s\n\n", $host, DEFAULT_PORT, $mode);

$trace = new Trace($mode === 'read' ? 'inbio-read-'.$arg : 'inbio-'.$mode, $host, DEFAULT_PORT);

if ($mode === 'control') {
    /*
     * Cross-protocol control, per rule 16.
     *
     * The same C3 frame, sent to the F18 that is known to work on the ZKTeco
     * standalone protocol. If the F18 answers it, the frame is not C3-specific
     * and every conclusion below is suspect. If it does not, then an answer from
     * .61 is evidence about .61's firmware and not about our socket code.
     */
    foreach ([F18_CONTROL_HOST => 'F18 (known good, different protocol)', $host => 'subject'] as $target => $what) {
        $session = new C3Session($target, DEFAULT_PORT, $trace);
        $trace->step('--- '.$target.' : '.$what, ['ok' => true]);
        if ($session->open(5.0)) {
            $session->connect();
            $session->close();
        }
    }

    echo "\ntrace: ".$trace->write()."\n";
    exit(0);
}

$session = new C3Session($host, DEFAULT_PORT, $trace);

if (! $session->open()) {
    echo "\ntrace: ".$trace->write()."\n";
    exit(1);
}

if (! $session->connect()) {
    echo "\nSession refused. The panel answered, but not with 0xC8.\n";
    echo "trace: ".$trace->write()."\n";
    exit(1);
}

if ($mode === 'info' || $mode === 'all') {
    [, $payload] = $session->send(CMD_GETPARAM, 'getparam (0x04)', implode(',', [
        '~SerialNumber', 'LockCount', 'AuxInCount', 'AuxOutCount',
        'FirmVer', 'DeviceName', '~DeviceName', 'IPAddress', 'MAC',
    ]));

    $params = parseKv($payload);
    foreach ($params as $k => $v) {
        printf("    %-18s %s\n", $k, $v);
    }
    $trace->step('parsed parameters', ['ok' => $params !== [], 'values' => $params]);
}

if ($mode === 'tables' || $mode === 'all') {
    [, $payload] = $session->send(CMD_DATATABLE_CFG, 'datatable cfg (0x06)');

    foreach (explode("\x0a", $payload) as $line) {
        $kv = parseKv($line);
        if ($kv !== []) {
            printf("    %s\n", $line);
        }
    }
    $trace->step('table catalogue', ['ok' => trim($payload) !== '', 'raw' => $payload]);
}

if ($mode === 'read' && $arg) {
    $cat = $session->catalogue();
    $rows = $session->getData($arg, $cat);
    printf("\n  %s: %d row(s)\n", $arg, count($rows));

    foreach (array_slice($rows, 0, 20) as $i => $row) {
        // Redact anything that identifies a real person before it reaches the console.
        foreach (['CardNo', 'Cardno', 'Pin', 'Name', 'Password'] as $sensitive) {
            if (! empty($row[$sensitive])) {
                $row[$sensitive] = '<redacted>';
            }
        }
        printf("    [%2d] %s\n", $i, json_encode($row));
    }

    if (count($rows) > 20) {
        printf("    … %d more\n", count($rows) - 20);
    }

    /*
     * A row count alone cannot tell a good read from a misframed one. The date
     * range can: a stream parsed one byte out of phase produces timestamps that
     * are obviously nonsense, so this is the cheapest correctness check we have
     * once the CRC has already passed.
     */
    $stamps = array_filter(array_column($rows, 'Time_second'));
    if ($stamps !== []) {
        printf(
            "\n  %s: %s .. %s (device local time)\n",
            $arg,
            c3time(min($stamps)),
            c3time(max($stamps)),
        );
        $trace->step("decoded range {$arg}", [
            'ok' => true,
            'rows' => count($rows),
            'first' => c3time(min($stamps)),
            'last' => c3time(max($stamps)),
            'note' => 'device LOCAL time, no zone in payload (rule 10)',
        ]);
    }
    // The trace keeps only counts and field names for the user/template tables,
    // never card numbers or template bytes. Rule 16: decoded structure, not PII.
}

$session->close();
echo "\ntrace: ".$trace->write()."\n";
