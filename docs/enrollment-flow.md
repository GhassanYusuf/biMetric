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

What the add/edit form does instead is ask. It lists the terminals the person is
**currently** enrolled on, pre-ticked, and the save is followed by:

```
POST /api/employees/{employee}/propagate   { "device_ids": [2, 5] }
```

which queues one `PUSH_USER` command **per device** (`EmployeeDevicePropagator`).

- **Queued, never inline** (rule 11). A push opens a session, reads the target slot
  and writes it; across several terminals that outlives a web request, and a command
  per device is what makes *"it updated on four of five"* legible at all.
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
