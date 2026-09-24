<?php

/*
 * Which realtime event carries a tapped card's NUMBER on the F18, and where in it?
 *
 * BLAST RADIUS: READ-ONLY. Sends exactly:
 *   CONNECT (+ AUTH if asked)  opens a session, as every read does
 *   REG_EVENT EF_ALL           subscribes this session to realtime pushes; changes
 *                              nothing stored, and ends with the session
 *   realtime ACKs              the acknowledgement the device expects per push
 *   REG_EVENT 0, EXIT          unsubscribe and close
 * No START_ENROLL, no capture mode, no user/card/template write. Tapping a card that
 * IS registered makes the terminal record an ordinary attendance punch, as it would
 * with no probe running; that is the device's normal behaviour, not ours.
 *
 * ---------------------------------------------------------------------------------
 * THE QUESTION (2026-09-23)
 * ---------------------------------------------------------------------------------
 * EnrollmentService::captureCard() took the FIRST realtime push of 4+ bytes as the
 * card. On IT Room it recorded 4294967295 (ffffffff) most of the time, 942683705
 * (bytes "9608", the start of PIN 960802797) twice, and the real 2862247 once.
 *
 * ---------------------------------------------------------------------------------
 * WHAT RESEARCH SETTLED FIRST (rule 17)
 * ---------------------------------------------------------------------------------
 * https://github.com/adrobinoga/zk-protocol/blob/master/sections/realtime.md
 *   EF_VERIFY payload = user sn (4 bytes) + 0x01, and is ffffffff when the user is
 *   unidentified. That alone explains the 4294967295s: a verify push, not a card.
 *   It documents NO event carrying a card number.
 * ZKTeco Standalone SDK manual (RegEvent / OnHIDNum), via search snippets only:
 *   "OnHIDNum" fires when a card is swiped with the card number; its mask is given
 *   as "4096 (1<<11)", which is self-contradictory (1<<11 is 2048). The manual pages
 *   themselves could not be fetched (DNS failure / 403 / 429 on 2026-09-23).
 * NOT SETTLED: the wire event code for a card push, and its payload layout. SDK
 * masks are not guaranteed to be the wire codes. That is this probe's job.
 *
 * ---------------------------------------------------------------------------------
 * METHOD: known plaintext
 * ---------------------------------------------------------------------------------
 * Tap card 2862247 (0x002BACA7, bytes a7 ac 2b 00 little-endian) while this listens.
 * Every push is logged with its header (command, sessionId, replyId: per the
 * references the event code rides in one of these) and its payload, and each payload
 * is searched for the card's bytes. The push that contains them, and at what offset,
 * is the answer.
 *
 * CONTROL: the F18 is the model that works and the subject here; there is no second
 * model under test. A finding here says nothing about the F22 (rule 15).
 *
 * PII: other people may clock in during the window. A payload is kept in full only
 * when it contains the probe card's bytes or the test PIN; every other payload is
 * reduced to its length and event code.
 *
 * Usage:  php tools/zkprobe/f18-card-event.php [host] [seconds] [card] [pin]
 */

require_once __DIR__.'/Probe.php';

use Promoseven\Biometric\Protocol\Command;
use Promoseven\Biometric\Protocol\Packet;

$host = $argv[1] ?? '192.168.7.62';
$seconds = (int) ($argv[2] ?? 60);
$card = (int) ($argv[3] ?? 2862247);
$pin = (string) ($argv[4] ?? '960802797');

$cardLe = pack('V', $card);
$cardBe = pack('N', $card);
$cardAscii = (string) $card;

$trace = [
    'label' => 'f18-card-event',
    'host' => $host,
    'started_at' => gmdate('c'),
    'question' => 'Which realtime push carries a tapped card number, and at what offset?',
    'probe_card' => ['decimal' => $card, 'hex_le' => bin2hex($cardLe)],
    'probe_pin' => $pin,
    'listen_seconds' => $seconds,
    'events' => [],
];

$p = new Probe($host);
$p->open();

if (! $p->connect()) {
    fwrite(STDERR, "CONNECT refused by {$host}\n");
    exit(1);
}

$sub = $p->cmd(Command::REG_EVENT, pack('V', Command::EF_ALL));
$trace['subscribe_reply'] = $sub === [] ? null : ['command' => $sub['command'], 'data' => bin2hex($sub['data'])];

$p->setPollTimeout(0.5);
fwrite(STDOUT, "Subscribed. TAP CARD {$card} ON {$host} NOW (listening {$seconds}s)...\n");

$t0 = microtime(true);
while (microtime(true) - $t0 < $seconds) {
    $d = $p->readRaw();
    if ($d === '') {
        continue;
    }

    $at = round(microtime(true) - $t0, 3);
    $pkt = Packet::parse($d);
    $data = $pkt['data'];

    // Every push must be ACKed or the device repeats it (same as DeviceClient).
    if ($pkt['command'] === Command::REG_EVENT) {
        $p->writeRaw(Packet::buildRealtimeAck($pkt['sessionId']));
    }

    $hits = [];
    foreach (['card_le' => $cardLe, 'card_be' => $cardBe, 'card_ascii' => $cardAscii, 'pin_ascii' => $pin] as $k => $needle) {
        $pos = strpos($data, $needle);
        if ($pos !== false) {
            $hits[$k] = $pos;
        }
    }

    $keep = isset($hits['card_le']) || isset($hits['card_be']) || isset($hits['card_ascii']) || isset($hits['pin_ascii'])
        || $data === "\xff\xff\xff\xff" || strlen($data) <= 5;

    $event = [
        't' => $at,
        'command' => $pkt['command'],
        'sessionId' => $pkt['sessionId'],
        'sessionId_hex' => sprintf('0x%04x', $pkt['sessionId']),
        'replyId' => $pkt['replyId'],
        'length' => strlen($data),
        'data_hex' => $keep ? bin2hex($data) : '[redacted: no probe card or test PIN in it]',
        'first4_le' => strlen($data) >= 4 ? unpack('V', substr($data, 0, 4))[1] : null,
        'hits' => $hits,
    ];
    $trace['events'][] = $event;

    fwrite(STDOUT, sprintf(
        "%7.3fs cmd=%d event=%s len=%d first4=%s hits=%s %s\n",
        $at, $event['command'], $event['sessionId_hex'], $event['length'],
        $event['first4_le'] ?? '-', json_encode($hits), $keep ? $event['data_hex'] : '',
    ));
}

$p->cmd(Command::REG_EVENT, pack('V', 0));
$p->disconnect();
$p->close();

$trace['finished_at'] = gmdate('c');
$trace['found_card_in'] = array_values(array_filter($trace['events'], fn ($e) => isset($e['hits']['card_le']) || isset($e['hits']['card_be']) || isset($e['hits']['card_ascii'])));

$dir = __DIR__.'/traces-'.gmdate('Y-m-d');
@mkdir($dir);
$n = 1;
while (file_exists(sprintf('%s/f18-card-event-%02d.json', $dir, $n))) {
    $n++;
}
$file = sprintf('%s/f18-card-event-%02d.json', $dir, $n);
file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

fwrite(STDOUT, "\n".count($trace['events'])." push(es), ".count($trace['found_card_in'])." containing the card. Trace: {$file}\n");
