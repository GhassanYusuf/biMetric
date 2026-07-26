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

`ensureExists()` creates the record if it is missing. This used to be a two-step
dance where adding an employee and then triggering a capture failed in the middle
with an unhelpful error — the reported symptom was *"I added a new employee and
the fingerprint trigger didn't work"*.

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
