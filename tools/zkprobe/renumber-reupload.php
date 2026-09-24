<?php

/*
 * Can a person's user ID be changed on the F18 by delete + re-upload, keeping every
 * fingerprint?
 *
 * BLAST RADIUS: WRITES TO THE DEVICE. For ONE named test user only, it sends:
 *   DISABLE_DEVICE / ENABLE_DEVICE   around each write, as every write here does
 *   DELETE_USER (their uid)          removes their record, card and templates
 *   FREE_DATA, PREPARE_DATA, DATA    stages the upload buffer (record + templates)
 *   SAVE_USERTEMPS (110)             commits it under the NEW user id, same uid
 *   REFRESH_DATA                     makes the device commit it
 * plus the reads that verify each step (users table, template table).
 *
 * HOW TO UNDO:
 *   - A failed move restores itself: UserService::renumberByReupload() re-uploads the
 *     original record and templates and verifies that before it throws.
 *   - A successful move is undone by running this again with the ids swapped, or with
 *     --and-back, which does exactly that in the same run.
 *   - If the library's restore ALSO fails, ZktecoDriver::renumberUser() kept an
 *     encrypted snapshot of the record and every template under
 *     storage/app/private/renumber-snapshots/ and the error names the file.
 *
 * RUN ONLY WITH THE OWNER'S EXPLICIT GO-AHEAD (CLAUDE.md rule 16), on a test user
 * created for the purpose — never on a real employee. The script refuses to run
 * without --owner-approved, and refuses unless the name ON THE DEVICE equals
 * --test-user, so a mistyped id cannot land on somebody else.
 *
 * ---------------------------------------------------------------------------------
 * THE QUESTION (2026-09-24)
 * ---------------------------------------------------------------------------------
 * The F18 refuses in-place renumbering (USER_WRQ, same uid, new id → ACK_ERROR,
 * 2026-09-23, trace traces-2026-09-23/renumber-itroom-01.json). The owner asked
 * that a changed Personnel ID reach the terminal without re-enrolling anyone.
 *
 * WHAT RESEARCH SETTLED FIRST (rule 17)
 *   fananimi/pyzk, commit f29709c17bb8f1bbb5382d2670b493207cb35ff1:
 *     zk/base.py  HR_save_usertemplates, _send_with_buffer, delete_user
 *     zk/const.py _CMD_SAVE_USERTEMPS = 110, CMD_PREPARE_DATA/DATA/FREE_DATA 1500-1502
 *     zk/user.py  repack73 (0x02 + the 72-byte record)
 *   The buffer layout is transcribed literally in Packet::buildUserTemplatesUpload()
 *   and checked byte-for-byte against pyzk's own struct output in
 *   tests/Unit/UserReuploadTest.php.
 * NOT SETTLED, and what this run must answer:
 *   1. Does the F18 accept SAVE_USERTEMPS at all?
 *   2. Does it store the templates byte-identical (sha1 before == after)?
 *   3. pyzk writes 0x10 + slot in the template table and drops the `valid` byte —
 *      does `valid` come back as it was?
 *   4. Does the enrolled finger still VERIFY at the terminal afterwards? Only a
 *      person can answer this one: the script asks, and records the answer.
 *
 * CONTROL: the F18 is both the subject and the reference model; there is no second
 * model under test. The control here is everybody ELSE on the terminal: their user
 * records and template digests are read before and after, and must be unchanged.
 * A finding here says nothing about the F22 or Camelot (rule 15).
 *
 * PII: the trace keeps the test user's own record fields and, for everyone, only
 * template SHA-1 digests and sizes — never template bytes, never other people's
 * names or cards.
 *
 * Usage:
 *   php tools/zkprobe/renumber-reupload.php <device-id|ip> <from-id> <to-id> \
 *       --test-user=<name on device> --owner-approved [--and-back]
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Support\Tenancy;
use Promoseven\Biometric\Client\Device as ProtocolDevice;
use Promoseven\Biometric\Codec\TemplateCodec;
use Promoseven\Biometric\Codec\UserCodec;

Tenancy::spanAllTenants();

$args = array_values(array_filter(array_slice($argv, 1), fn ($a) => ! str_starts_with($a, '--')));
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

[$deviceId, $from, $to] = $args + [null, null, null];
$testUser = $opts['test-user'] ?? null;

if (! $deviceId || ! $from || ! $to || ! is_string($testUser) || $testUser === '') {
    fwrite(STDERR, "Usage: php tools/zkprobe/renumber-reupload.php <device-id> <from-id> <to-id> --test-user=<name> --owner-approved [--and-back]\n");
    exit(2);
}
if (! isset($opts['owner-approved'])) {
    fwrite(STDERR, "Refusing: this WRITES TO THE DEVICE. Re-run with --owner-approved once the owner has said so (rule 16).\n");
    exit(2);
}

// A database id or the terminal's IP address.
// The trace is the evidence (rule 16). Prove it can be written BEFORE any device write,
// so a run can never change a terminal and then lose the record of what it did.
$dir = __DIR__ . '/traces-' . gmdate('Y-m-d');
if (! is_dir($dir) && ! @mkdir($dir, 0775, true) || ! is_writable($dir)) {
    fwrite(STDERR, "Refusing: cannot write traces to {$dir} as " . get_current_user() . ". Create it and give this user write access first.\n");
    exit(2);
}

$device = Device::query()->where('id', ctype_digit($deviceId) ? (int) $deviceId : 0)->orWhere('ip_address', $deviceId)->firstOrFail();
$driver = app(App\Contracts\BiometricDeviceDriver::class);

/** Everyone's record fields and template digests — the before/after picture. */
$snapshot = function () use ($driver, $device): array {
    return $driver->run($device, function (ProtocolDevice $d) {
        $users = UserCodec::decode($d->client()->readUsersRaw());
        $rawTemplates = $d->client()->readTemplatesRaw();
        $templates = TemplateCodec::decodeWithBlobs($rawTemplates);

        return [
            'users' => $users,
            'templates_decoded' => $templates !== [] || TemplateCodec::isEmptyTable($rawTemplates),
            'templates' => array_map(fn ($t) => [
                'uid' => $t['uid'], 'fid' => $t['fid'], 'valid' => $t['valid'],
                'size' => strlen($t['template']), 'sha1' => sha1($t['template']),
            ], $templates),
        ];
    });
};

$trace = [
    'label' => 'renumber-reupload',
    'host' => $device->ip_address,
    'started_at' => now()->toIso8601String(),
    'device' => ['id' => $device->id, 'name' => $device->name, 'model' => $device->model],
    'question' => 'Does delete + SAVE_USERTEMPS move a user to a new id with every fingerprint intact?',
    'test_user' => $testUser,
    'runs' => [],
];

$moves = [[$from, $to]];
if (isset($opts['and-back'])) {
    $moves[] = [$to, $from];
}

foreach ($moves as $i => [$a, $b]) {
    $before = $snapshot();
    $me = UserCodec::find($before['users'], $a);

    if ($me === null || $me['name'] !== $testUser) {
        fwrite(STDERR, "Refusing: user {$a} on {$device->name} is " . ($me === null ? 'not there' : "named '{$me['name']}'") . ", not the test user '{$testUser}'.\n");
        $trace['runs'][] = ['from' => $a, 'to' => $b, 'refused' => 'not the test user'];
        break;
    }

    $uid = (int) $me['uid'];
    $mine = fn (array $s, int $u) => array_values(array_filter($s['templates'], fn ($t) => $t['uid'] === $u));
    $others = fn (array $s, int $u) => [
        'users' => array_values(array_map(fn ($x) => [$x['uid'] ?? null, $x['user_id'] ?? null, $x['card_number'] ?? null], array_filter($s['users'], fn ($x) => ($x['uid'] ?? null) !== $u))),
        'templates' => array_values(array_filter($s['templates'], fn ($t) => $t['uid'] !== $u)),
    ];

    $run = [
        'from' => $a,
        'to' => $b,
        'uid' => $uid,
        'record_before' => ['name' => $me['name'], 'privilege' => $me['privilege'], 'card' => $me['card_number']],
        'templates_before' => $mine($before, $uid),
    ];

    fwrite(STDERR, "\n=== Move {$a} -> {$b} on {$device->name} (uid {$uid}, " . count($run['templates_before']) . " template(s))\n");
    $t0 = microtime(true);

    try {
        $user = $driver->renumberUser($device, $a, $b);
        $run['result'] = 'moved';
        $run['returned'] = ['uid' => $user->uid, 'user_id' => $user->userId, 'card' => $user->cardNumber, 'slots' => $user->fingerSlots];
    } catch (Throwable $e) {
        $run['result'] = 'failed';
        $run['error'] = ['class' => get_class($e), 'message' => $e->getMessage(), 'context' => method_exists($e, 'context') ? $e->context() : null];
    }
    $run['seconds'] = round(microtime(true) - $t0, 2);

    $after = $snapshot();
    $moved = UserCodec::find($after['users'], $run['result'] === 'moved' ? $b : $a);
    $afterUid = $moved === null ? null : (int) $moved['uid'];
    $run['record_after'] = $moved === null ? null : ['uid' => $afterUid, 'user_id' => $moved['user_id'], 'name' => $moved['name'], 'privilege' => $moved['privilege'], 'card' => $moved['card_number']];
    $run['templates_after'] = $afterUid === null ? [] : $mine($after, $afterUid);

    $shaBefore = array_column($run['templates_before'], 'sha1', 'fid');
    $shaAfter = array_column($run['templates_after'], 'sha1', 'fid');
    ksort($shaBefore);
    ksort($shaAfter);
    $run['findings'] = [
        'templates_byte_identical' => $shaBefore === $shaAfter,
        'valid_bytes_before' => array_column($run['templates_before'], 'valid', 'fid'),
        'valid_bytes_after' => array_column($run['templates_after'], 'valid', 'fid'),
        'others_unchanged' => $others($before, $uid) == $others($after, $afterUid ?? $uid),
    ];

    if ($run['result'] === 'moved') {
        fwrite(STDOUT, "Moved. Ask the test user to place an enrolled finger on {$device->name} now.\nDid it verify? [y/n/skip] ");
        $answer = strtolower(trim((string) fgets(STDIN)));
        $run['findings']['finger_verified_at_terminal'] = match ($answer) { 'y' => true, 'n' => false, default => null };
    }

    fwrite(STDERR, json_encode($run['findings'], JSON_UNESCAPED_SLASHES) . "\n");
    $trace['runs'][] = $run;

    if ($run['result'] !== 'moved' || ! $run['findings']['others_unchanged']) {
        break; // never stack a second write on an unexplained first one
    }
}

$trace['finished_at'] = now()->toIso8601String();

$n = 1;
while (file_exists($file = sprintf('%s/renumber-reupload-%02d.json', $dir, $n))) {
    $n++;
}
file_put_contents($file, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
fwrite(STDOUT, "\nTrace: {$file}\n");
