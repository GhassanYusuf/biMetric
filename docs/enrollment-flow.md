# Enrollment flow

## Why this is a session and not a request

A fingerprint capture beeps the terminal and then waits — up to 30 seconds — for a
person to walk over and present a finger. A card capture waits 20.

The original implementation did this synchronously. Two things followed: a web
worker was held for the whole wait, and closing the browser destroyed every trace
that an enrollment had been attempted. Somebody could stand at a terminal, watch
nothing happen, and there would be no record to look at afterwards.

So a capture is now a **durable session** driven by a queued command, and the
device's own progress trail is written through as it arrives.

## The shape of it

```
POST /api/employees/{employee}/devices/{device}/enrollment/fingerprint
     {finger_slot: 6}
        │  capability gate: fingerprint.enroll usable → else 422
        │  one active session per employee+device → else 409
        ▼
device_commands (enroll_fingerprint)  +  enrollment_sessions (pending)
        ▼
ProcessDeviceCommand → EnrollmentOrchestratorService
        ├── users()->ensureExists()      ← creates the record if missing
        ├── enrollment()->captureFingerprint($userId, $slot, $onProgress)
        │        │
        │        └── each device event → appendEvents() → MQTT publish
        │               beeped → waiting_for_finger → enrolled | timeout
        ├── on success: refresh template slots into employee_device_maps
        └── transition session to success | timeout | failed
```

Progress is published on `tenant/{tenantId}/module/enrollment/{sessionId}`.

## Session states

`pending → sent → waiting → success | timeout | failed | cancelled`

The terminal four are `EnrollmentSession::TERMINAL`. Note the names: **`pending`**
not "queued", **`success`** not "enrolled", **`timeout`** not "timed_out". The UI
relabels them for operators but the stored values are these.

`waiting` is entered by the *first device event*, not by the job starting:

```php
if ($event === 'beeped' && $session->status === EnrollmentSession::SENT) {
    $session->transitionTo(EnrollmentSession::WAITING);
}
```

That distinction is deliberate. `sent` means we asked; `waiting` means the
terminal actually beeped. If a session never leaves `sent`, the command never
reached the device — a completely different problem from a person not turning up.

## The event trail is the only evidence

Five event strings exist, and no others:

| Event | Means |
|---|---|
| `beeped` | the trigger landed on the device |
| `waiting_for_finger` | listening for a fingerprint |
| `waiting_for_card` | listening for a card |
| `enrolled` | the capture succeeded |
| `timeout` | nobody presented in time |

A real trail from this system:

```json
["beeped","waiting_for_finger","timeout","beeped","waiting_for_finger","timeout"]
```

That is **one session that retried**, which is why events are appended in order
rather than deduplicated. Reading it: the terminal did beep (twice), so the
command reached the hardware; nobody presented a finger either time.

Events are written through immediately rather than buffered, so an operator sees
"the device beeped" before the capture finishes and the trail survives a worker
that dies mid-capture.

### The duplication bug

The progress reporter persists each event *and* an earlier version also appended
`$result->events` at the end — producing every event twice. The append was
removed; the reporter is the single writer.

## Timeout is not a device fault

`timeout` means a person did not turn up. The UI colours it amber, never red, and
says so. Sending operators to diagnose hardware that is working perfectly is worse
than saying nothing.

## One active session per employee per device

Enforced in `DeviceEnrollmentController::activeSessionFor()`, answered as **409**
with the blocking session id. Two concurrent captures on one terminal would
interleave their device events and neither trail would be trustworthy.

Sessions past their grace window are reaped rather than blocking new work
(`hasExpired()`), so a crashed worker cannot lock an employee out permanently.

## Cancel runs synchronously

Everything else is queued; `POST /api/enrollment-sessions/{session}/cancel` is not.

It is a fast operation, and leaving a device in capture mode while a cancel sat in
a queue would keep it refusing normal punches. Releasing the terminal is the
urgent part.

## The employee does not need to exist on the device first

The record is created if it is missing, so adding an employee and triggering a capture
is one step. This used to be a two-step dance that failed in the middle with an
unhelpful error — the reported symptom was *"I added a new employee and the fingerprint
trigger didn't work"*.

### But it is a WRITE, and it is guarded (changed 2026-09-15)

It used to call `ensureExists($employee->employee_code, …)` directly. On a terminal that
already had somebody at that number, it found the **stranger's** record and enrolled a
fingerprint onto it. Silently.

It now goes through `BiometricDeviceDriver::saveUser(…, onlyIfMissing: true)` with the
same three guards as an explicit push:

1. the device must be **adopted** — its existing roster read and every entry decided;
2. the PIN comes from the **stored mapping**, never from `employee_code`;
3. the slot is **read before it is touched**, in the same session, and the write is
   abandoned if it holds anybody else.

An employee with no identity on that terminal is refused rather than allocated one
implicitly. See `docs/device-identity-and-adoption.md`.

## Editing an employee, and pushing that edit to the terminals

**Saving an employee changes the database and nothing else.** That is deliberate and
it is not going to change: a corrected spelling is not a hardware operation, and a
save that silently wrote to six terminals would be one typo away from rewriting six
records for somebody else.

**Changing the employee code does NOT move the terminal's user ID — the F18 refuses it.**
Built and tried on 2026-09-23 (below), and IT Room answered ACK_ERROR for every target id
with the record untouched, so the prompt is gated on `user.renumber` and no terminal
offers it today. What follows describes the path for firmware that does accept it.

**The path, when a terminal's capability allows it** (owner, 2026-09-23). Saving never touches a terminal. The push prompt after
saving lists what changes per terminal, and where a terminal's user ID differs from the
(digits-only) code, a second prompt offers *"Change user ID 960802797 → 205?"*.
Answering yes sends `to_employee_code: true` to `/propagate`, which for each terminal:

1. refuses the new ID if anyone else ever held it there, or it sits in that terminal's
   roster for someone else (`DeviceIdentityResolver::assertPinFreeFor` — the employee's
   own earlier PIN is allowed back, so typo fixes work);
2. moves the record in place (`DeviceUserSyncService::renumberEmployee` →
   `BiometricDeviceDriver::renumberUser` → `UserService::renumber`): same uid, card and
   fingers kept, verified by read-back including the template table;
3. closes the old mapping and opens a new one, so punches under the old PIN stay theirs
   and the old PIN stays reserved; the roster entry follows the record;
4. then pushes the name under the new ID.

If the move fails, nothing is written to that terminal and it is reported as failed. A
terminal whose capability says it cannot renumber (every F18 today) is never asked: the
name is pushed under its existing ID with a note saying the ID was kept. On
a terminal they are new to, the code is claimed as their PIN when it is free there.
Declining pushes the name under the existing ID. `user.renumber` is EXPERIMENTAL — see
`docs/device-capabilities.md`.

What the add/edit form does instead is ask. It lists the terminals the person is
**currently** enrolled on, pre-ticked, and the save is followed by:

```
POST /api/employees/{employee}/propagate   { "device_ids": [2, 5] }
```

which writes to each device **immediately, inside the request** (`EmployeeDevicePropagator`).

- **Synchronous by the owner's instruction (2026-09-15)**, a recorded exception to
  rule 11: the operator presses Push to learn whether the terminal took it, and a queue
  receipt is not that. Each device is attempted in its own try, so one unreachable
  terminal cannot cancel the others, and the result is split written / skipped / failed.
- **A device that cannot take a write is refused before anything is queued**, with
  the reason, because the operator is at the screen now — `Device::userWriteRefusal()`
  returns a sentence rather than a capability constant. The three refusals are: the
  device is disabled, its roster has not been read and claimed (so it is read-only —
  see the guard above), or its protocol cannot write users at all.
- **Only LIVE mappings are offered.** A closed mapping is a PIN the employee used to
  hold on that terminal, kept so their old punches still resolve to them. Pushing to
  it would write their name over whoever holds that slot today, so
  `EmployeeDevicePropagator::enrolledOn()` filters on `valid_to IS NULL` and the
  payload carries `is_live` per enrollment so the form cannot offer one either.
- **The form names the drift.** When a terminal holds a different name from ours, the
  checkbox says so — *holds "A. Rahman" — this will overwrite it* — because that is
  precisely what the push is for and precisely what it will do.

On a NEW employee the same section lists every writable terminal and starts
**unticked**: which terminals a joiner belongs on is a decision nobody else can make,
and "all of them" is not a safe default.

### Pushing without editing

The **Push** button on an employee row opens the same checklist without touching a
field, and posts to the same endpoint. It separates the terminals they are already on
(pre-ticked — re-pushing where they already are is a repair) from the writable ones
they are not (unticked — adding somebody to a new site is a different decision).

Both it and the add/edit form render one `DeviceTargets` component, so there is a
single place where "which terminals, and is this mapping live" is decided.

The single-device route (`POST /employees/{employee}/devices/{device}/push`) is
unchanged and still runs inline. It belongs to the enrollment dialog, which pushes as
the precondition for capturing a finger and needs the answer in the same breath.

## Deleting one finger

```
DELETE /api/employees/{employee}/devices/{device}/enrollment/fingerprints/{slot}
```

Queues `CMD_DELETE_USERTEMP` with `(uint16 uid, uint8 slot)`. This capability is
**`implemented`, not `confirmed`** — the payload layout is inferred and the
operation has never been verified on hardware. It is offered because it is the
only way to remove a single template, and labelled honestly.

Slots are 0–9. Anatomical names (`right index` = slot 6) come from
`handGeometry.js` and are **our convention**, not device data — the terminal only
knows slot numbers.

## Cards

One card field per user record, so there is no "add a second card". Clearing a
card writes 0.

`captureCard()` needs `uid`, `name` and `privilege` because it rewrites the whole
record — passing defaults would wipe the name and demote an admin. Prefer
`captureCardForUser($userId)`, which reads the current record first and preserves
everything.

Card `4294967295` (`0xFFFFFFFF`) appears on a real employee. It is flagged suspect
(`AccessCard::looksLikeSentinel()`) rather than trusted; we do not know whether the
device means "none", "error", or a genuine value.

Since 2026-09-23 the UI does not display it as a card number. The enrollment and
detail dialogs show **"Probably no card"** and name the value, as a state separate
from both a real card and "no card registered". The same value is refused as input
by the typed-card write below, so the app can never create one.

**Observed 2026-09-23, IT Room (F18, `192.168.7.62`), PIN 960802797:** the roster
read at 10:48 recorded card `2857525`. The mapping, refreshed at 11:09 after an
edit-form push, recorded `4294967295`. `UserService::save()` re-sends the card it
read in the same session, so the push should not have changed it. **Most likely
explained** later the same day: live card capture writes `0xFFFFFFFF` (see
docs/device-capabilities.md, "`card.enroll` — WITHDRAWN"), and a scan attempt in
that window would account for it.

### Unknown slots no longer erase known ones (2026-09-23)

`upsertMap()` copied `fingerSlots` into `employee_device_maps` even when it was null,
meaning either "the template table did not decode" or "templates were not asked for".
The re-read that follows every card scan therefore wiped ali-test2's known IT Room
slot `[5]` to unknown. It now writes slots only when they decoded, as
`readEnrollment()` always did. `readEnrollment()` ("Read from device") also now
updates the terminal's mapping row. Before, it updated only the flat employee
snapshot, so a successful read changed nothing in the dialogs, which read the row.
Tests are in `CardAssignmentTest`.

**Not explained:** why the template table failed to decode on some re-reads of IT
Room (F18) that day, when it decoded at 11:09. Every failing read followed a card
capture that had left the device in `REG_EVENT` streaming. That is a suspicion,
not a finding, and it needs a control (rule 16).

### Writing a card by number (2026-09-23)

`PUT /api/employees/{employee}/devices/{device}/card` with `{card_number: "2857525"}`,
behind the **Save card** field in the enrollment dialog. It exists because Scan card
means walking to the terminal to tap a card whose number is already printed on it.

- **App → contract, never `run()`.** `EnrollmentOrchestratorService::assignCard()` calls
  `BiometricDeviceDriver::setUserCard()`. `ZktecoDriver` maps it to the library's
  existing `UserService::setCard()`, which reads the record, keeps name and
  privilege, rewrites only the card and reads back. C3 and CSL throw
  `UnsupportedCommandException` (422).
- **Verified on read-back.** If the returned card is not the one sent, the request
  fails (502) and nothing is recorded. The ACK proves nothing on this family.
- **Refused (422)** for 0, for `0xFFFFFFFF` and above, for non-digits, and when
  another live mapping on the same terminal already holds the number.
- Only `card_number` is written to the mapping. The read-back record carries no
  fingerprint slots, and copying its null over them would turn "known" into
  "unknown".
- Synchronous, like the direct `deleteCard` it sits beside. It is not a
  queued `enrollment/*` command.
- **CONFIRMED on the F18** (IT Room, `192.168.7.62`) on 2026-09-23 at 11:30 UTC:
  card 2862247 written to ali-test2 (PIN 960802797) and read back. It is unknown on
  every other model. Tests: `tests/Feature/Devices/CardAssignmentTest.php`.
- `recordCard()` now treats only an ACTIVE row as "already recorded". Before this,
  a card that had been replaced and was then written back left the old card listed
  as active.

**From the employee edit form (2026-09-24).** The Card number field on the Personnel
profile tab is editable, as it is in ZKAccess. It is **not** saved with the employee:
`employees.card_number` stays "what a terminal last reported". A changed card is
written, after the save and the push, to each ticked terminal that the push reached,
through the same `PUT …/card` (or `DELETE …/card` when the field is emptied), one
terminal at a time. Each terminal gets its own result row (written, refused, or skipped
when it lacks `card.write`), and Retry re-runs the push and the card for that terminal.
The form refuses a changed card with no terminal on the Access levels tab's right list,
because there would be nowhere to put it. Declining "Push now" writes no card either.
Wiring: `Employees.jsx` `writeCard()` / `withCard()`.

### Choosing terminals in the employee form (2026-09-24)

The Access levels tab lists **terminals**, ZKAccess-style: left = every terminal this
app can write a user to (`writableTargets`, binary protocol and active), right = the
terminals the person is on (their live mappings, `enrolledTargets`). This replaced
both the old "Update them on" checklist and the database-only access-level lists
(the `access_levels` tables and endpoints still exist; the form no longer uses them).

On save, in this order (`Employees.jsx` `updateEmployee` / `createEmployee`):

1. **Moved left → removed.** A separate red confirmation names every terminal, then
   `POST employees/{e}/devices/{d}/remove` for each. That deletes the record,
   fingerprints and card on the device. Declining keeps them on all of them.
2. **On the right → pushed.** The existing "Push now / Not now" prompt on an edit
   (a create pushes directly), then `propagate`.
3. **Card**, if it changed, on each terminal the push reached.
4. **Read back** from each terminal the push reached:
   `GET employees/{e}/devices/{d}/enrollment`, which also writes the slots and card
   onto that terminal's mapping row. Shown as "not found", "fingerprints unknown"
   (template table did not decode) or a real count, never collapsed (rule 3).

A terminal the person is on that this app cannot write to (a C3 panel) sits on the
right **locked**: it cannot be moved left, because nothing here can remove them from
it. Every step reports one row per terminal; Retry re-runs push → card → read for
that terminal and is never offered on a removal row.

## What has never been proven

**A successful fingerprint capture has not been observed end to end.** Every
attempt so far ended in `timeout`, because confirming it requires a person
standing at the terminal at the moment the job runs.

The trigger is confirmed — the device beeps, and the trail proves the command
lands. The capture completing is not. `fingerprint.enroll` is marked `confirmed`
for the trigger path only; treat a first successful capture as the thing that
validates the rest.


## Enrollment belongs to ONE terminal

`employees.fingerprint_count`, `fingerprint_slots`, `card_number` and
`enrollment_synced_at` are a **flat snapshot of whichever device was read last**.
They answer "how many fingers" and never "on which terminal", and with two active
devices that is not a rounding error — it is a different answer.

Observed on this installation, employee `akram`:

| Terminal | Fingerprint slots | Card |
|---|---|---|
| IT Room | `[1, 6]` | 7553651 |
| Good Wheels | `[6, 7]` | none |

The flat columns held IT Room's `[1, 6]` and its card. Read as global, that claims
a card the person does not have on Good Wheels and names two fingers, one of which
Good Wheels does not hold — exactly the overclaim rule 10 forbids.

The per-device truth is `employee_device_maps` (one row per employee per device,
written by the roster read). `EmployeeDeviceMapResource::fingerprintCount()`
returns `null` rather than `0` when `fingerprint_slots` is null, so "unknown"
survives the trip.

### `enrollments` on the employee payload

`EmployeeResource` exposes the rows as `enrollments`, eager-loaded by
`EmployeeController::index()` and `show()` via `deviceMaps.device:id,name`:

```json
"enrollments": [
  { "device_id": 2, "device_name": "IT Room",
    "fingerprint_slots": [1, 6], "fingerprint_count": 2,
    "card_number": 7553651, "synced_at": "2026-08-03T13:37:00+00:00" },
  { "device_id": 5, "device_name": "Good Wheels",
    "fingerprint_slots": [6, 7], "fingerprint_count": 2,
    "card_number": null, "synced_at": "2026-09-02T08:16:11+00:00" }
]
```

The field is **absent** when the relation was not loaded, rather than `[]`. A
consumer must be able to tell "not asked for" from "enrolled nowhere"; collapsing
them is the same class of lie as `0` for unknown. Frontend helpers treat
`Array.isArray(employee.enrollments)` as the test, and fall back to the flat
columns when it is false.

### What the UI does with it

**Never read vs. undecodable (fixed 2026-09-23).** Both arrive as
`fingerprint_slots: null`, and until this date `FingerPicker` rendered both as
"This firmware's fingerprint table couldn't be decoded". So ali-test2, who has slot
5 on IT Room and has never been read on Camelot, showed a decode failure for
Camelot. No row for the selected terminal, or a row with no `synced_at`, now renders
as "Not read from this terminal yet". The amber decode message is kept for a read
that really failed to decode. `EnrollModal` also stopped seeding from the flat
employee snapshot and uses the selected terminal's row, re-seeded when the terminal
changes.

- **Roster** — an `Enrolled on` column (`TerminalsCell`), one badge per terminal
  with its finger count and a card glyph when a card is present. Compact on
  purpose: a stacked hand diagram per terminal would double the height of every
  row, and the per-finger detail is one click away. Amber is a terminal that holds
  the person but no usable biometrics, or one whose template table would not
  decode — both mean "cannot clock in yet", never "broken".
- **Roster, fingerprint and card cells** — sourced from the most recently read
  per-device row (`rosterEnrollment()`), and labelled with that terminal. They
  must NOT read the flat columns first: those are not written by every read path,
  so an employee imported from a second terminal has real slots in
  `employee_device_maps` while `enrollment_synced_at` stays null. Checking the
  flat snapshot first rendered "—" for every Good Wheels-only employee even though
  their map row held slots `[6, 7]`. The glyph and its label must come from the
  SAME row; drawing flat slots under the newest device name attributed one
  terminal's data to another. `syncedAgo()` spans both sources for the same
  reason — it reported the roster as last read in July while a terminal had been
  read that morning.
- **Detail dialog** — an `Enrollment by terminal` panel lists every device at
  once, and everything below the terminal picker is scoped to the **selected**
  device's map row.

### The bug this closed

The dialog previously showed the flat snapshot while its device picker, its
per-slot `Delete` buttons and its `Remove from terminal` action all addressed the
selected device. With the terminals above, an operator could read "slot 1, slot 6"
off IT Room's snapshot with Good Wheels selected and press `Delete` on slot 1 —
against a terminal that holds slots 6 and 7. No row for the selected device now
falls through to "never read" rather than rendering as zero.

## The system's copy of each fingerprint (2026-09-24)

> Since 2026-10-11 this is governed by CLAUDE.md rule 19 — card numbers included.
> The procedure is `docs/credential-preservation.md`.

Owner's decision: keep a copy of every employee's fingerprint templates in the
system, "in case we need to change the id or bring in a new device". This reverses
the earlier rule that template bytes are never stored (reasoning in
`docs/PROJECT-STATE.md`).

- **Where:** `biometric_templates.template` — the column that table always reserved
  for it — one row per employee x terminal x finger. Base64 of the device's blob,
  encrypted with the `encrypted` cast (APP_KEY), `$hidden`. `template_sha1` is the
  plaintext digest; `template_format` is `<protocol>:<model>` of the source terminal.
- **Who touches bytes:** only `App\Services\Employees\FingerprintVault`, fed by the
  new contract method `BiometricDeviceDriver::readTemplates()` (ZKTeco and C3; CSL
  throws unsupported). No resource, log line or audit row carries a byte; the audit
  row `employee.fingerprints_captured` holds counts. The API exposes only
  `stored_fingerprint_slots`.
- **When copies are taken:** `php artisan device:read-templates [device]` (all
  terminals, read-only); after every per-person enrollment read (dialog open,
  post-push read-back); after every roster read (`SYNC_ENROLLMENT`), which is queued
  for every terminal nightly at 02:30 UTC (`fingerprint-copies-nightly` in
  `routes/console.php` — only runs once `schedule:run` is in cron); and **before
  every "Remove from terminal"**, which refuses (409) if the copy cannot be taken.
  The read-backs are best effort; a failure there never fails the read.
- **On demand, one person:** "Read fingerprints to system" in the roster row menu
  and "Read to system" beside Fingerprints in the edit form —
  `POST /api/employees/{employee}/fingerprints/read` (optional `device_ids`), which
  reads the template table of each terminal they are on and stores their copies.
  Read-only on the terminals. One row per terminal: `read` (with slots), `skipped`
  (no template table / terminal switched off) or `failed` (unreachable; nothing
  captured, nothing deleted). Answers 503 with a plain message until the storage
  migration has run.
- **Capture rules:** a finger on the terminal is stored, or refreshed when its digest
  changed; a failed or unsupported read changes nothing. Copies from different
  terminals never overwrite each other.
- **Lifecycle (changed 2026-10-11):** a copy OUTLIVES the terminal's finger. "Remove
  from terminal", deleting a finger there, or a read that no longer finds it removes
  only the bare slot marker; the stored template stays. The old rule — copies follow
  their terminal — deleted the copy at the exact moment it was needed: Akram was
  removed from IT Room (his finger existed nowhere else in the system), added back,
  and showed 0 fingers. Deleting the terminal from the app (`device_id` cascade) or
  the employee still removes copies. What a terminal HOLDS is the mapping's
  `fingerprint_slots`, never these rows.
- **Compatibility:** `FingerprintVault::templatesFor($employee, $protocol, $model)`
  returns only copies from the same family. An F18 template is `unknown` on an F22
  until a real upload says otherwise (rule 15).
- **Writing copies back (2026-10-11):** every path that adds a person to a terminal
  (`DeviceUserSyncService::pushEmployee()` — the push dialog, the single-device push
  and queued `PUSH_USER`) then writes their stored card (if the terminal record has
  none; `assignCard`, read back) and their stored fingers (`FingerprintPusher::restoreOnto`
  → driver `uploadTemplates`, add-only, read back). Each outcome is a sentence in the
  push result; a failure there never fails the push. Methods per family: F18 →
  `SAVE_USERTEMPS` (`TemplateService::addTemplates`, implemented, write CONFIRMED
  2026-10-11 on IT Room); C3 panels → `templatev10` rows (`Personnel::addFingerprints`).
  A terminal without `template.upload` says "cannot take a fingerprint from this app".
- **The employee's card is the system's record:** a read of a terminal that holds no
  card no longer clears `employees.card_number` (it did, for Akram, 2026-10-11); the
  per-terminal row still records exactly what the terminal holds.

