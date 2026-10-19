<?php

/*
 * WRITES TO THE DEVICE: YES — two C3 panels, adding ONE user row each.
 *
 * Puts PIN 781054761 / card 7458281 / "Abdul Haq" onto Glass Door (#13) and
 * Entrance (#14). IT Room (#15) is NOT touched: the hardware read on
 * 2026-10-05 10:45 already found him there at uid 1.
 *
 * WHAT IT SENDS, per panel, via the app's own C3 write path:
 *   1. saveUser()  -> Personnel::add(pin, name)  : one `user` row. UID is NOT
 *      sent (the panel upserts on Pin). Then one `userauthorize` row per door
 *      that most people on that panel hold, read from the panel itself.
 *   2. setUserCard() -> Personnel::update(pin, null, card) : rewrites the `user`
 *      row with CardNo, because saveUser() passes no card and writes CardNo=0.
 *
 * WHAT MAKES THIS SAFE — the checks are in Personnel, not in this file:
 *   assertPinFree()  refuses if Pin is already used in `user`, `userauthorize`
 *                    OR `templatev10`. So this can only ever fill an empty slot;
 *                    it cannot overwrite a person, and cannot orphan templates.
 *   assertCardFree() reads every user row and refuses if card 7458281 is held
 *                    by a different Pin — one card, one person.
 *   assertFields()   reads the row back and refuses unless Name and CardNo match.
 *   No UID is ever sent, so UID 296 (Bikash, pin 675) cannot be clobbered.
 *   No fingerprint/template row is written, so no enrolment is destroyed.
 *
 * UNDO: Personnel::delete(pin) on each panel, i.e. C3Driver::deleteUser().
 * Both panels are single-user additions; nothing pre-existing is modified.
 *
 * EXPECT THIS TO BE REVERTED. ZKAccess owns both panels and demonstrably
 * removes users it does not know about: the app pushed this same PIN on
 * 2026-09-27 (roster claims entry#1038 / entry#1358, uid 296) and it was gone
 * by 2026-10-05. ZKAccess is the durable writer there.
 */

require '/var/www/zkt/vendor/autoload.php';
$app = require_once '/var/www/zkt/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Device;
use App\Services\Devices\DeviceDriverManager;
use Illuminate\Support\Facades\DB;

$PIN = '781054761';
$CARD = 7458281;
$NAME = 'Abdul Haq';

$manager = app(DeviceDriverManager::class);
$results = [];

foreach ([13 => 'Glass Door', 14 => 'Entrance'] as $id => $label) {
    $dev = Device::find($id);
    echo "\n=========== #{$id} {$label} ({$dev->ip_address}, {$dev->protocol}) ===========\n";

    // ---- pre-flight: read the panel before touching it -----------------------
    $users = $manager->readUsers($dev, false);
    echo "  read back {$dev->ip_address}: " . count($users) . " users\n";

    $pinHeld = null;
    $cardHeldBy = null;
    foreach ($users as $u) {
        if ((string) $u->userId === $PIN) {
            $pinHeld = $u;
        }
        if ((int) ($u->cardNumber ?? 0) === $CARD) {
            $cardHeldBy = $u;
        }
    }

    if ($cardHeldBy !== null && (string) $cardHeldBy->userId !== $PIN) {
        printf("  ABORT: card %d is already held by PIN %s (%s) on this panel.\n",
            $CARD, $cardHeldBy->userId, $cardHeldBy->name ?? '?');
        $results[$id] = 'ABORTED card taken';
        continue;
    }

    if ($pinHeld !== null) {
        printf("  PIN %s already present (uid=%s name=%s card=%s) — not adding, just ensuring the card.\n",
            $PIN, $pinHeld->uid ?? '?', $pinHeld->name ?? '?', $pinHeld->card ?? 0);
    } else {
        echo "  PIN {$PIN} is free on this panel — safe to add.\n";

        // Guard: refuse if the slot is occupied at write time. Personnel also
        // checks, but this turns a race into a clear message instead of a throw.
        $guard = function ($occupant) use ($PIN, $id) {
            if ($occupant !== null) {
                throw new RuntimeException(
                    "#{$id}: PIN {$PIN} became occupied (uid {$occupant->uid}, {$occupant->name}) — abandoning the write."
                );
            }
        };

        try {
            $u = $manager->saveUser($dev, $PIN, $NAME, $guard);
            printf("  saved: pin=%s uid=%s name=%s card=%s\n", $u->userId, $u->uid ?? '?', $u->name ?? '?', $u->cardNumber ?? '?');
        } catch (\Throwable $e) {
            printf("  SAVE FAILED: %s\n", $e->getMessage());
            $results[$id] = 'FAILED save: ' . $e->getMessage();
            continue;
        }
    }

    // ---- set the card ---------------------------------------------------------
    try {
        $u = $manager->setUserCard($dev, $PIN, $CARD);
        printf("  card set: pin=%s uid=%s name=%s card=%s\n", $u->userId, $u->uid ?? '?', $u->name ?? '?', $u->cardNumber ?? '?');
        $results[$id] = 'ok';
    } catch (\Throwable $e) {
        printf("  CARD FAILED: %s\n", $e->getMessage());
        $results[$id] = 'FAILED card: ' . $e->getMessage();
    }
}

// ---- independent verification: read both panels fresh ------------------------
echo "\n\n=========== VERIFY (fresh read) ===========\n";
foreach ([13 => 'Glass Door', 14 => 'Entrance'] as $id => $label) {
    $dev = Device::find($id);
    $users = $manager->readUsers($dev, false);
    $found = null;
    foreach ($users as $u) {
        if ((string) $u->userId === $PIN) {
            $found = $u;
        }
    }
    printf("#%-3d %-12s users=%-5d ", $id, $label, count($users));
    if ($found === null) {
        echo "PIN {$PIN} => ABSENT\n";
        continue;
    }
    printf("PIN %s => PRESENT uid=%-5s name=%-12s card=%-9s %s\n",
        $PIN, $found->uid ?? '?', $found->name ?? '?', $found->cardNumber ?? '?',
        ((int) ($found->cardNumber ?? 0) === $CARD && (string) ($found->name ?? '') === $NAME) ? 'MATCH' : '*** MISMATCH ***');
}

// ---- reconcile the app's own records ----------------------------------------
echo "\n=========== reconcile app records ===========\n";
$e = DB::table('employees')->where('id', 1428)->first();
printf("employee #%d %s card=%s\n", $e->id, $e->name, $e->card_number);

foreach ([13, 14] as $id) {
    $dev = Device::find($id);
    $users = $manager->readUsers($dev, false);
    $uid = null;
    foreach ($users as $u) {
        if ((string) $u->userId === $PIN) {
            $uid = (int) $u->uid;
        }
    }
    if ($uid === null) {
        printf("#%-3d %-12s not on panel — map left closed\n", $id, $dev->name);
        continue;
    }

    $map = DB::table('employee_device_maps')
        ->where('employee_id', 1428)->where('device_id', $id)
        ->orderByDesc('id')->first();

    if ($map === null) {
        printf("#%-3d %-12s no map row to reopen\n", $id, $dev->name);
        continue;
    }

    DB::table('employee_device_maps')->where('id', $map->id)->update([
        'valid_to' => null,
        'closed_reason' => null,
        'device_uid' => $uid,
        'card_number' => (string) $CARD,
        'synced_at' => now(),
        'updated_at' => now(),
    ]);
    printf("#%-3d %-12s map#%d reopened  uid=%-5s card=%s\n", $id, $dev->name, $map->id, $uid, $CARD);
}

echo "\nlive maps for #1428 (this is what 'Enrolled on' shows):\n";
foreach (DB::table('employee_device_maps')->where('employee_id', 1428)->whereNull('valid_to')->get() as $m) {
    $d = DB::table('devices')->where('id', $m->device_id)->first();
    printf("  %-12s pin=%-11s uid=%-5s card=%s\n", $d->name, $m->device_user_id, $m->device_uid, $m->card_number);
}

echo "\n=== summary ===\n";
foreach ($results as $id => $r) {
    printf("  #%d %-12s %s\n", $id, Device::find($id)->name, $r);
}
