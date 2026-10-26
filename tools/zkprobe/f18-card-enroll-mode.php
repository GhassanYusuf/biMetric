<?php

/*
 * Does the F18 store a tapped card on the user ITSELF when put in the card-enrol mode
 * that EnrollmentService::captureCard() asks for?
 *
 * BLAST RADIUS: WRITES TO THE DEVICE — ONE TEST USER ONLY. On the terminal named it:
 *   1. holds the queue's own per-device lock for the run (rule 11);
 *   2. reads the test user's record (card, name, privilege) and keeps it in the trace;
 *   3. sends what captureCard() sends, in the same order, MINUS its writeUser():
 *      CANCEL_CAPTURE, REG_EVENT EF_ALL, START_ENROLL(pin, slot 11), START_VERIFY if
 *      that was ACKed; logs every push while the owner taps a card; then CANCEL_CAPTURE,
 *      REG_EVENT 0. This app writes no user, card or template;
 *   4. reads the test user's record again, at once and after 5 s.
 * The only thing that can change is what the TERMINAL does on its own in that mode: it
 * may store the tapped card on the test user, which is the question.
 * TO UNDO: if the test user ends with a wrong card, clear it from the employee form or
 * type the right number (typed card writes are CONFIRMED on the F18).
 *
 * Needs the owner's go-ahead for each run (rule 16). Given 2026-10-26 for IT ROOM
 * (F18/ID, 192.168.7.62), Test User 981122445, casebook DC-033.
 *
 * WHAT IT SETTLES: whether card.enroll can work on the F18 by reading the record after
 * the terminal's own enrol mode, since no realtime push carries a card number
 * (traces-2026-10-26/f18-card-event-01.json). A finding here is about the F18 only.
 *
 * WHAT REACHES THE TRACE: the test user's card before and after, every push's header,
 * and payloads only when they are 5 bytes or less or contain the test card or PIN.
 *
 * --no-verify leaves out START_VERIFY (run 03): runs 01-02 sent it, as captureCard() does,
 * and the terminal's screen showed "Verify: Badge ... Failed to verify", i.e. it was in
 * verify mode, not enrol mode, when the card was tapped.
 *
 * Usage:  php tools/zkprobe/f18-card-enroll-mode.php <device-id> <pin> <card> [seconds] [--no-verify]
 */

require __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/Probe.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Contracts\BiometricDeviceDriver;
use App\Jobs\ProcessDeviceCommand;
use App\Models\Device;
use App\Support\Tenancy;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Promoseven\Biometric\Protocol\Command;
use Promoseven\Biometric\Protocol\Packet;

$device = Device::withoutGlobalScopes()->find((int) ($argv[1] ?? 0));
$pin = (string) ($argv[2] ?? '');
$card = (int) ($argv[3] ?? 0);
$seconds = (int) ($argv[4] ?? 60);
$noVerify = in_array('--no-verify', $argv, true);
if ($device === null || $pin === '' || $card <= 0) {
    fwrite(STDERR, "Usage: php tools/zkprobe/f18-card-enroll-mode.php <device-id> <pin> <card> [seconds]\n");
    exit(2);
}
Tenancy::set($device->tenant_id);
$driver = app(BiometricDeviceDriver::class);

$trace = [
    'label' => 'f18-card-enroll-mode',
    'host' => $device->ip_address,
    'started_at' => gmdate('c'),
    'device' => ['id' => $device->id, 'name' => $device->name, 'model' => $device->model],
    'question' => "Does START_ENROLL slot 11 make the terminal store a tapped card on the user itself?",
    'test_pin' => $pin,
    'test_card' => $card,
    'start_verify_sent' => ! $noVerify,
    'steps' => [],
    'events' => [],
];
$step = function (string $what, array $data = []) use (&$trace) {
    $trace['steps'][] = ['at' => gmdate('c'), 'step' => $what] + $data;
    fwrite(STDOUT, "  {$what} ".json_encode($data)."\n");
};
$record = function () use ($driver, $device, $pin) {
    foreach ($driver->readUsers($device, false) as $u) {
        if ($u->userId === $pin) {
            return ['name' => $u->name, 'card' => $u->cardNumber, 'privilege' => $u->privilege];
        }
    }

    return null;
};

$lock = Cache::lock((new WithoutOverlapping("device:{$device->id}"))->getLockKey(new ProcessDeviceCommand(0)), 600);
if (! $lock->get()) {
    fwrite(STDERR, "A queued command holds {$device->name}'s lock; try again in a minute.\n");
    exit(1);
}

try {
    $before = $record();
    $step('record before', ['record' => $before]);
    if ($before === null) {
        throw new RuntimeException("{$pin} is not on {$device->name}.");
    }

    $p = new Probe($device->ip_address, (int) $device->port);
    $p->open();
    if (! $p->connect((int) ($device->comm_key ?? 0))) {
        throw new RuntimeException('CONNECT refused');
    }

    $reply = fn (array $r) => $r === [] ? null : ['command' => $r['command'], 'data' => bin2hex($r['data'] ?? '')];
    $step('CANCEL_CAPTURE', ['reply' => $reply($p->cmd(Command::CANCEL_CAPTURE))]);
    $step('REG_EVENT EF_ALL', ['reply' => $reply($p->cmd(Command::REG_EVENT, pack('V', Command::EF_ALL)))]);
    $enroll = $p->cmd(Command::START_ENROLL, Packet::buildEnrollRequest($pin, 11));
    $step('START_ENROLL slot 11', ['reply' => $reply($enroll), 'ack_ok' => ($enroll['command'] ?? null) === Command::ACK_OK]);
    if (! $noVerify && ($enroll['command'] ?? null) === Command::ACK_OK) {
        $step('START_VERIFY', ['reply' => $reply($p->cmd(Command::START_VERIFY))]);
    }

    fwrite(STDOUT, "\n>>> TAP CARD {$card} ON {$device->name} NOW, ONCE (listening {$seconds}s) <<<\n\n");
    $p->setPollTimeout(0.5);
    $t0 = microtime(true);
    while (microtime(true) - $t0 < $seconds) {
        $d = $p->readRaw();
        if ($d === '') {
            continue;
        }
        $pkt = Packet::parse($d);
        if ($pkt['command'] === Command::REG_EVENT) {
            $p->writeRaw(Packet::buildRealtimeAck($pkt['sessionId']));
        }
        $data = $pkt['data'];
        $hits = array_filter([
            'card_le' => strpos($data, pack('V', $card)),
            'card_ascii' => strpos($data, (string) $card),
            'pin_ascii' => strpos($data, $pin),
        ], fn ($v) => $v !== false);
        $keep = $hits !== [] || strlen($data) <= 5;
        $e = ['t' => round(microtime(true) - $t0, 3), 'command' => $pkt['command'], 'event' => sprintf('0x%04x', $pkt['sessionId']),
            'length' => strlen($data), 'hits' => $hits, 'data_hex' => $keep ? bin2hex($data) : '[redacted]'];
        $trace['events'][] = $e;
        fwrite(STDOUT, sprintf("%7.3fs cmd=%d event=%s len=%d hits=%s %s\n", $e['t'], $e['command'], $e['event'], $e['length'], json_encode($hits), $e['data_hex']));
    }

    $step('CANCEL_CAPTURE', ['reply' => $reply($p->cmd(Command::CANCEL_CAPTURE))]);
    $p->cmd(Command::REG_EVENT, pack('V', 0));
    $p->disconnect();
    $p->close();

    $after = $record();
    $step('record after', ['record' => $after]);
    sleep(5);
    $later = $record();
    $step('record after 5 s', ['record' => $later]);
    $trace['verdict'] = ($later['card'] ?? null) === $card
        ? 'the terminal stored the tapped card itself'
        : (($later['card'] ?? null) === ($before['card'] ?? null) ? 'the record did not change' : 'the record changed to something else');
    $step('verdict', ['verdict' => $trace['verdict']]);
} catch (Throwable $e) {
    $step('FAILED', ['error' => get_class($e).': '.$e->getMessage()]);
} finally {
    $lock->release();
    $trace['finished_at'] = gmdate('c');
    $dir = __DIR__.'/traces-'.gmdate('Y-m-d');
    @mkdir($dir, 0775, true);
    $n = 1;
    while (file_exists(sprintf('%s/f18-card-enroll-mode-%02d.json', $dir, $n))) {
        $n++;
    }
    $file = sprintf('%s/f18-card-enroll-mode-%02d.json', $dir, $n);
    file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    fwrite(STDOUT, "trace: {$file}\n");
}
