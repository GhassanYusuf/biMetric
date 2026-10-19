# Credential preservation — the procedure behind CLAUDE.md rule 19

Owner's rule, 2026-10-11: the system keeps every person's **card number and
fingerprints**, takes the copy **before** anything on a terminal is destroyed, and
**writes it back** when the person is put on a terminal again, using each terminal's
own method.

**Why it exists.** Akram (employee 1434) was removed from IT Room and added back from
the system. He came back with 0 fingerprints and no card. His finger had existed only
on that terminal; the removal deleted it without a copy, the re-add wrote nothing back,
and the read afterwards also wiped the card off his employee record. A fingerprint
cannot be re-collected without the person standing at a terminal, and a card number
nobody wrote down has to be found by tapping the card again. Neither is acceptable as
the price of removing and re-adding someone.

---

## Where the system's copy lives

| Credential | Copy | Notes |
|---|---|---|
| Fingerprint | `biometric_templates.template` (encrypted), via `FingerprintVault` | One row per employee × terminal × finger. Rows **with** a template are the copy; rows without are bare slot markers that follow the terminal. |
| Card, per terminal | `access_cards` (history: `active`, `replaced`, `revoked`, `lost`) | What each terminal held and why it left. `revoke_reason` = `AccessCard::REASON_REMOVED_WITH_USER` or `REASON_CLEARED` decides whether it goes back. |
| Card, the person's | `employees.card_number` | The system's record. Filled from a terminal when empty; **never** cleared or overwritten by a terminal read. |
| Terminal privilege (admin) | `employee_device_maps.device_privilege` | Read off the terminal before removal; put back when the record is re-created (`privilegeToRestore`, 2026-10-11 — Ali is an admin on two terminals). |

What a terminal holds **right now** is a different question, answered by the mapping
(`employee_device_maps.fingerprint_slots`, `.card_number`). Never answer it from the
copy.

---

## The procedure

### 1. Capture — continuously

- **Every roster read** (`SYNC_ENROLLMENT`, `DeviceUserSyncService::syncEnrollmentState`)
  stores everyone's fingers (`FingerprintVault::capture`), records every card in
  `access_cards`, and copies a card onto `employees.card_number` when that is empty.
- **Nightly** at 02:30 UTC, a roster read is queued on every active terminal
  (`fingerprint-copies-nightly` in `routes/console.php`). **This only runs if
  `schedule:run` is in cron** — see `docs/PROJECT-STATE.md`.
- **Every per-person read** (enrollment dialog, post-push read-back) refreshes that
  person's copy.
- On demand: `php artisan device:read-templates [device]`, or "Read fingerprints to
  system" on a person.

### 2. Save before destroying — always, or refuse

`DeviceUserSyncService::removeEmployee()` calls `saveCredentialsBeforeRemoval()`
**before** `deleteUser()`:

1. Read the terminal's own user record → record the card (`recordCard`), and put it on
   `employees.card_number` if that has none.
2. Read the template table → store the person's fingers (`FingerprintVault::capture`).
3. If either read fails, **nothing is deleted** — the API answers 409 with the reason
   ("their card and fingerprints could not be saved to the system first").
4. After the delete: the active card row becomes `revoked` /
   `REASON_REMOVED_WITH_USER`; only bare slot markers are deleted; the copies stay.

**The keypad password** (2026-10-12) is a credential like the card. Captured on every
roster read and before every removal into `employees.device_password` when that is
empty (`capturePassword`, digits 1–8, where the model's `password.read` allows); never
overwrites the system's own. Written back on a push only where `password.write` is
CONFIRMED (`passwordToWrite`); elsewhere a push KEEPS the terminal's. Library releases
before 2.13.0 erased it on every standalone write — that is fixed. Known gap: clearing a
password in the system does not clear it on a terminal.

**A resignation** (2026-10-12) is one more way into that same delete, not a new one. A
resignation date entered in the employee form moves every terminal this app can write to
off the form's terminal list, so the save asks the usual red confirmation and calls
`removeEmployee()` per terminal. Through the integration API, setting `resigned_at`
queues `REMOVE_USER` on every live mapping (`EmployeeOffboarding::takeOffTerminals()`),
which the command handler runs through `removeEmployee()` too. The record and the copies
stay. A resigned employee is never pushed (both push endpoints answer 409, the queued
pusher skips them), so the restore in step 3 happens only once the date is cleared and
they are added back. A declined or failed removal leaves them on that terminal; the form
then opens with it already moved off, so the next save offers it again.

**A record linked to no employee** (a leftover test record holding someone's number)
is removed only by `DeviceUserSyncService::releaseUnlinkedNumber()` — the red push
row's buttons (2026-10-11). Its record and every fingerprint on it are saved encrypted
to `storage/app/private/removed-records/` first, or nothing is deleted. Refused for any
number an employee was ever mapped to. Two answers:

- **"Same person"** — the owner's procedure (2026-10-11, "ali-it" was ALI MOHAMED):
  1. copy the duplicate's fingerprints into the **person's account** in the system
     (`FingerprintVault::adoptCopies`);
  2. credit the punches under its number to the person, through a closed mapping that
     also keeps its privilege;
  3. delete the duplicate from the terminal and read back;
  4. push the person's data from the system — name and user ID as stored, card, every
     stored finger, privilege — so the terminal holds ONE record for them.
- **"Remove it"** — not this person: saved, removed, number freed for them.

**A temporary user** (`withTemporaryUser`, "zkt-test") is the third and last deleter: the
fingerprint-write test creates it when nobody real can safely receive a test finger, and
deletes it seconds later. It holds nothing to save.

Deleting one finger, clearing a card, a Personnel ID move — every other destructive
action keeps the copy too. A stored template is removed only with its employee or its
terminal (database cascade).

### 3. Restore on every add — automatically

Every way a person is put on a terminal ends in `DeviceUserSyncService::pushEmployee()`
(the push dialog, the single-terminal push, the queued `PUSH_USER`). After the user
record is written, `restoreCredentials()`:

1. **Card** — `cardToRestore()` picks:
   - the card **this terminal** last held for them, if it left with their record or is
     still recorded active (people can badge with different cards at different
     terminals — Ghassan's Camelot card differs from his record card);
   - otherwise, with no history on this terminal, `employees.card_number`;
   - **never** a card an operator cleared on purpose, one reported lost, or the
     firmware's 0xFFFFFFFF "no card" marker.

   Written with `EnrollmentOrchestratorService::assignCard()` only when the terminal's
   record has no card, refused if another person on that terminal holds the number,
   and confirmed by reading the record back.
2. **Fingers** — `FingerprintPusher::restoreOnto()` writes every stored finger the
   terminal lacks, add-only, confirmed by reading the table back.
3. The person also gets back **their own former user ID** on that terminal when it was
   only ever theirs (`DeviceIdentityResolver::claim`), so their old punches and new
   ones share a number.

Each outcome is a sentence in the push result (`written[].restored`), shown under the
terminal's row. A credential that could not be written does **not** fail the push —
the person is on the terminal; the sentence says what is missing.

### Methods per terminal family

| Family | Card write | Fingerprint write | Status |
|---|---|---|---|
| ZKTeco F18 (IT Room, Camelot) | `UserService::setCard` | `TemplateService::addTemplates` (`SAVE_USERTEMPS`) | Card CONFIRMED (2026-09-23). Fingerprint write + read-back CONFIRMED on IT Room 2026-10-11; a real finger match not yet observed → `implemented`. |
| ZKTeco F22 (On The Road) | inherits F18 | inherits F18 | **Never run on this model** (rule 15). |
| C3 panels (Glass Door, Entrance) | not offered — panel users are managed in ZKAccess | `Personnel::addFingerprints` (`templatev10` rows) | Fingerprint write `implemented`. |
| CSL | — | — | Not supported; the result says so. |

A terminal without the capability is reported as such ("cannot take a fingerprint
from this app"), never as success.

---

## When it goes wrong — what the operator does

| Situation | What to do |
|---|---|
| Removal refused: "could not be saved to the system first" | The terminal did not answer the read. Try again when it is reachable. Do not delete the person at the terminal's keypad instead — that is exactly the loss this prevents. |
| Re-add says "Card #… not written: … already on X" | Another person on that terminal holds the number. Decide who it belongs to; clear it from the wrong one, then push again. |
| Re-add says "stored fingerprints were not written" | Read the message: no copy (the person must enrol at a terminal once), or the terminal refused. Use "Push fingerprints" to retry. |
| A person's card is on a terminal but not in the system | The next roster read records it. To do it now: queue "Read users" on that terminal. |
| A card was lost | Mark it lost (`access_cards.status = lost`) — it is then never written back anywhere. |

---

## Rebuilding a whole terminal from the system (2026-10-11)

"Rebuild from system…" on the device page (`DeviceRebuildService`, casebook DC-017)
applies this procedure to every person on one terminal at once:

1. **Preview, read-only** — every person: already right, or what will change (user ID
   → employee code, name, card, missing fingers), or why they are left alone; every
   record linked to no employee.
2. **Download the punches** (`sync_attendance`); the rebuild refuses to change anything
   until that has succeeded after the request.
3. **Back up the whole terminal** — every record and template, encrypted, to
   `storage/app/private/device-backups/` — and store everyone's fingers and cards in
   their accounts (a roster read).
4. **One queued command per person who needs a change**, through the same push as an
   employee save, so each model uses its own method. People already right are not
   touched — the doors keep working.
5. **Records linked to no employee are kept** unless removal is ticked; each removal is
   saved first and its number stays reserved.
6. **Read-back** of the whole terminal.

It is not a literal wipe: the end state is the same, without emptying a door panel and
without destroying anything only the terminal held.

---

## Checked mechanically

`tests/Unit/CredentialPreservationTest.php`: only `removeEmployee()` deletes a terminal
user; it saves card and fingers before the delete; `pushEmployee()` restores both; no
code deletes a stored template outside the marker scope; a terminal with no card does
not clear the record card. `tests/Feature/Devices/FingerprintPushTest.php` covers the
scenarios (remove → re-add, terminal-only card, per-terminal card, cleared/lost cards,
return to own user ID).

What the test cannot check: that a **new** destructive path (a wipe, a bulk delete, a
device reset) goes through the save step. That part is discipline.
