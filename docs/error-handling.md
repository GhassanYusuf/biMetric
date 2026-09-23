# Error handling

## Principle

A device on a network fails constantly and normally. The question is never "did it
fail" but "is this worth retrying, and what does the operator need to know".

So failures are **typed**, each type answers `isRetryable()`, and the message an
operator reads is separate from the technical detail whoever debugs it needs.

## The six exceptions

All extend `DeviceException extends \RuntimeException` and carry `context(): array`.

| Class | `isRetryable()` | Cause | What to do |
|---|---|---|---|
| `ConnectionException` | **true** | unreachable, handshake refused, wrong comm key | retry with backoff; if persistent, check network/firmware |
| `TimeoutException` | **true** | no answer within the timeout | retry; frequent timeouts mean a slow link or a busy device |
| `VerificationException` | **true** | the device **acked a write that did not stick** | retry; if persistent the device may need a power cycle |
| `ProtocolException` | false | malformed bytes, or a precondition failed | do not retry — the request itself is wrong |
| `UnsupportedCommandException` | false | the capability set forbids it | do not retry — the firmware cannot do it |
| `DeviceException` | false | base | |

Branch on `isRetryable()`, never on message text. Messages are written for humans
and will change.

```php
try {
    $driver->saveUser($device, $pin, $name, $identity->slotGuard($device, $employee, $pin));
} catch (DeviceException $e) {
    if ($e->isRetryable()) {
        $command->releaseForRetry($e->getMessage());
    } else {
        $command->fail($e->getMessage(), $e->context());
    }
}
```

### Two app-level refusals that are NOT device failures

Added 2026-09-15. Both extend `\RuntimeException`, not `DeviceException` — nothing
went wrong with the hardware, and neither should ever be retried.

| Class | Thrown by | Means |
|---|---|---|
| `App\Exceptions\DeviceNotAdoptedException` | `Device::assertWritable()` | the terminal's existing roster has not been read, or entries remain undecided |
| `App\Exceptions\DeviceIdentityConflictException` | `DeviceIdentityResolver::assertSlotFree()` | the target PIN is held by somebody who is not this employee |

Both are **operator-facing**, and both are normal states rather than faults. A newly
added terminal is legitimately unadopted; a PIN collision on a terminal that predates
this system is expected. Surface the message and the next action, not a stack trace:
read the roster, or claim/reallocate the PIN.

`DeviceIdentityConflictException` carries `$device`, `$pin` and `$occupantName` so a UI
can offer "claim this person" directly. Do not put the occupant's name in a log line
that leaves the system — it is a real employee.

Retrying either is pointless: nothing about the device will have changed, and a retry
that eventually "succeeded" would mean the guard had been bypassed.

## An ack is not a commit

This firmware sometimes acknowledges a write and then discards it. `UserService::save()`
reads the record back and throws `VerificationException` when it is not there:

> The device accepted the write for 261 but the record did not appear when read
> back. This firmware intermittently drops writes — retry, and if it persists the
> device may need a power cycle.

Related and separate: writes also do not persist at all without
`CMD_REFRESH_DATA` (1013) after them. That is not a failure mode, it is a required
step, and every write path in the library does it.

## A stalled windowed read resumes; it does not fail the transfer

A `ProtocolException` "Windowed transfer stalled … after N window retries" means a
single 16 KB window went silent and did not recover within `bulkWindowRetries`
attempts at that offset. It is thrown only once retries are exhausted — a normal
stall is re-requested at the same offset and never surfaces. Its context carries
`window_offset`, `window_bytes`, `expected` and `received`.

This is the fix for a device on a **lossy or high-latency link** (CONFIRMED on the
Camelot F18 over a public IP, 2026-09-14): raise `attendance.device_window_retries`
(env `DEVICE_WINDOW_RETRIES`) for such a device. It is distinct from the receive
buffer, which stops the *kernel* dropping a burst and does nothing for a device that
goes quiet mid-transfer. A `TimeoutException` on a `READ_BUFFER` request is treated
as the same stall and retried; a `TimeoutException` releasing the buffer
(`FREE_DATA`, command 1502) is swallowed and never fails an otherwise-complete read.

## Retries live on the row, not in the queue

```php
// ProcessDeviceCommand
public int $tries = 25;        // WithoutOverlapping RELEASES count as queue attempts
public int $maxExceptions = 1; // a real throw is terminal
```

`WithoutOverlapping` releases the job when another command is already running
against the same device. **Each release consumes a queue attempt**, so `$tries = 1`
threw `MaxAttemptsExceededException` on the very first release — a job that failed
because the system was working correctly.

`$tries = 25` gives releases room; `$maxExceptions = 1` keeps a genuine throw
terminal. Domain retries are tracked on the `device_commands` row (`attempts`,
`max_attempts`, `can_retry`) where an operator can see and re-fire them.

## A killed worker holds its lock

`WithoutOverlapping` locks live in `cache_locks`. Killing a worker mid-command
leaves the lock held, and every subsequent command for that device is released
until it expires (600s).

If commands are stuck queued and no worker is running, that is the cause. The lock
self-expires; `cache_locks` can also be cleared manually.

**Seen again on 2026-09-15.** A sync of the Camelot terminal (remote, over the internet)
exceeded the worker timeout. The lock survived, so every requeue of that command
returned in ~11ms without touching the device, and the command row sat at `running`
indefinitely. The symptom — a job that "succeeds" instantly and changes nothing — looks
like a device problem and is not.

```
DELETE FROM cache_locks WHERE key LIKE '%device:{id}';
-- then reset the row to queued and redispatch
```

Do **not** pass `--tries=1` to `queue:work` for these jobs. A `WithoutOverlapping`
release counts as a queue attempt, so the first release fails the job with
`MaxAttemptsExceededException` before it ever reaches a device. The job declares
`$tries = 25` for exactly this reason; overriding it on the command line reintroduces
the bug the constant documents.

## Where a failure is RECORDED: `DeviceErrorReporter`

Returning a tidy 502 and writing nothing down was, for a long time, the actual
problem. The three inline read endpoints each caught `DeviceException`, built their
own sentence, and threw away `context()` — the one field that makes a protocol bug
reproducible. "The device errored again" left nothing behind to act on.

`App\Support\DeviceErrorReporter` is now the single place a device failure is
recorded. It does exactly two things:

```php
$message = $reporter->report($e, 'attendance sync', $device, ['command_id' => 12]);
```

1. writes **one** structured entry to the `device` log channel, and
2. returns the **operator-facing sentence** the caller should surface.

Both together, because rule 11 requires the two audiences to be served separately
and splitting them across call sites is how they drifted apart before.

### What the entry contains

| Field | Why |
|---|---|
| `operation`, `device_id`, `device_name`, `device_ip` | which terminal, doing what |
| `tenant_id`, `user_id` | who saw it — a queued command has no user, and that is itself informative |
| `exception`, `message` | the concrete subclass, which already encodes the category |
| `retryable` | `isRetryable()`, so "is this normal" is answerable without reading the message |
| `context` | the library's structured `context()` — offsets, opcodes, counts |
| `trace` | **only** for non-`DeviceException` throwables |

The `trace`/`context` split is the useful signal. A `DeviceException` carries
`context()` and its stack is noise. Anything else — a `TypeError` in a codec — is a
bug in **our** code, has no context, and the frames are the informative part. The
absence of `context` in an entry tells you which kind you are looking at.

### Operator wording lives in one place

`DeviceErrorReporter::operatorMessage()` owns every sentence. It previously existed
twice — in `ProcessDeviceCommand` and, differently worded, in each controller catch
— so the same timeout read as "Sync failed: read timed out" in one place and "The
device did not respond in time." in another.

A non-`DeviceException` never shows its raw message on screen. It would be
meaningless to an operator and can leak internals; the detail is in the log.

## Logging happens exactly once

Two paths, and they cannot both fire for the same failure:

- **Caught** — the call site (`DeviceController::deviceFailure()`,
  `ProcessDeviceCommand`) calls the reporter itself, with a precise `operation`.
- **Uncaught** — the `$exceptions->report()` hook in `bootstrap/app.php` catches
  `DeviceException` and reports it, then **returns `false`** so Laravel does not
  also write its own duplicate to the stack channel.

An exception a controller catches never reaches the hook, so there is no overlap.

## Status codes for device failures

Set centrally in `bootstrap/app.php`, so an endpoint that forgets to catch still
answers correctly instead of returning a bare `500 'Server error.'`:

| Exception | Status | Meaning |
|---|---|---|
| `UnsupportedCommandException` | **501** | the firmware cannot do this, ever — rule 9 says the UI should have hidden it, so this means a mis-declared capability |
| any other `DeviceException` | **502** | the request was fine; the terminal upstream of us was not |
| anything else | 500 | a bug in our code — generic message, real detail in the log |

502 responses carry `errors.retryable`, so a client can distinguish "try again" from
"this will never work" without parsing the message.

## Channels

| Channel | File | Holds |
|---|---|---|
| `device` | `storage/logs/device.log` | every biometric/protocol failure, 30 days |
| `client` | `storage/logs/client.log` | browser crashes reported by the SPA, 14 days |

Separated from `laravel.log` so "the terminal misbehaved again" is one file to read
rather than a grep through application noise.

## Browser-side crashes

A React render error used to blank the whole SPA to a white page, leaving nothing
on the server. `resources/js/spa/shared/errors.jsx` closes that:

- **`<ErrorBoundary>`** — catches render/lifecycle throws and shows a recovery
  screen. Mounted **twice**: an outer one in `main.jsx` above the providers (an
  `AuthProvider` that chokes is invisible to a boundary inside `App`), and an inner
  one in `App.jsx` re-keyed on `location.pathname` so navigating away recovers.
- **`installGlobalErrorHandlers()`** — a boundary only sees errors thrown during
  render. A rejected `await api.post()` in a click handler reaches nothing, which is
  the symptom operators describe as *"I clicked it and nothing happened"*. The
  `unhandledrejection` and `error` window listeners catch those and raise a toast.
- **`reportClientError()`** — POSTs to `/api/client-errors`. It never throws and
  never retries; a reporter that can itself fail is a trap, and a retrying error
  report is how a crash loop starts.

Guards that matter: identical messages are sent **once per page load** (a crashing
component re-throws every frame), the route is throttled to 20/min, every field is
length-capped server-side, and `401`s are skipped — an expired session is not a
crash, and reporting it would bury the real errors.

Reports are logged, **not** stored in a table. The payload is attacker-controllable,
a table invites unbounded growth and a UI nobody prunes, and a log channel already
rotates. Rule 13: reuse before you add.

## A stored `error` is an OBJECT, and rendering it crashed six screens

`attendance_sync_runs.error` and `device_commands.error` are both cast to `array`
(`AttendanceSyncRun::casts()`, `DeviceCommand::casts()`), and
`AttendanceSyncRunResource` / `DeviceCommandResource` pass the field through
untouched. So what reaches the browser is the reporter's structure, not a string:

```json
{
  "exception": "Promoseven\\Biometric\\Exception\\ProtocolException",
  "context": { "command": "ATTLOG_RRQ", "expected": 412084, "received": 162816 }
}
```

Six screens rendered it as `{run.error}` / `{c.error}`. React refuses an object as
a child:

```
Objects are not valid as a React child (found: object with keys {exception, context})
```

That throws during render, the `ErrorBoundary` catches it, and the **entire page**
becomes the recovery screen. `/app/device-sync-runs` was unopenable for as long as
any run in the list had failed — which, given the whole point of the screen, is
whenever somebody needed it. OBSERVED on Firefox 155, two runs carrying
`ProtocolException` from IT Room.

### The fix: `<DeviceError>` in `pages/devices/statusParts.jsx`

`formatDeviceError()` renders the technical half only — short exception class name,
then `context` as `key=value` pairs:

```
ProtocolException — command=ATTLOG_RRQ, expected=412084, received=162816
```

The namespace is dropped because it is noise on screen and the class name already
encodes the category. It tolerates a plain string (rows predating the `array` cast)
and falls back to `JSON.stringify` for an unrecognised object — a formatter that
throws would take the page down a second time.

This sits **beside** `message`, never instead of it, because rule 11 keeps the
operator sentence and the technical detail separate:

```
Incomplete transfer: got 162816 of 412084 bytes.        ← message,  slate
ProtocolException — command=ATTLOG_RRQ, expected=412084 ← error,    rose
```

### Enforced mechanically

`tests/Unit/FrontendConventionsTest::test_structured_device_errors_are_never_rendered_as_a_react_child`
fails on any `{x.error}` interpolated as a JSX **child**. A prop value
(`error={run.error}`) is fine — that hands the object to something that knows the
shape. Six files shipped this bug simultaneously, which is the argument for a guard
rather than a review.

## Failures reach the operator

Three destinations, deliberately not collapsed into one:

1. **`device_commands.message`** — the sentence a human reads.
2. **`device_commands.error` / `raw_response`** — technical detail for whoever
   debugs it. Collapsing these would force one audience to read the other's output.
3. **A notification.** `DeviceSyncFailed` → `NotifyOperatorsOfSyncFailure`, published
   over MQTT and stored.

The UI shows message and error separately on the command queue, and never
flattens a real device error into "something went wrong". A 502 from a controller
carries the device's actual words.

## Capability refusals are 422, before queueing

A command the firmware cannot run is refused with **422 and an explanation** rather
than queued to fail later:

> IT Room does not support device.time.write: Implemented from protocol
> documentation but never confirmed on hardware — may fail.

The queue never fills with work that must fail, and the operator learns why.

## MQTT failures are swallowed on purpose

Every publisher wraps `publish()` in `try { } catch (\Throwable) { }`:

```php
} catch (\Throwable) {
    // MQTT only ENHANCES the experience — a broker outage must never fail a
    // health check or block the transition being recorded.
}
```

The client does the same: `useMqtt` returns silently when there is no broker URL
or the connection fails, and every screen still works through fetch-on-demand.

## The failure mode that matters most

**Silent discard.** The 34% attendance data loss ran for the life of the code
because the decoder returned `null` for unparseable records and the caller simply
skipped them. Nothing counted it. Nothing logged it.

Two rules came out of that:

1. Anything discarded gets **counted** and reported — `undecodable` is a
   first-class number on every sync run, surfaced in the UI in its own column.
2. Anything discarded gets **archived** — raw bytes go to `attendance_raw_logs`
   before decoding, so a decoder fix can recover what a decoder bug lost. That
   archive is what made recovering 2,823 records possible.

A failure you can see is a bug. A failure you cannot see is a wrong payroll.

## Diagnosing a short bulk transfer

Two different failures share one symptom. Both raise `ProtocolException`, both say
"we got less than we were promised", and the fix for one does nothing for the
other — so the context is what tells them apart.

| Message | Path | Meaning |
|---|---|---|
| `Incomplete transfer: got X of Y bytes.` | free-running push | The device streamed the table and we did not capture all of it. |
| `Windowed transfer stalled: got X of Y bytes.` | windowed | A window we explicitly requested never arrived in full. |

### `Incomplete transfer` — check the buffer figures first

The context now carries them, so nobody has to reproduce the problem by hand:

```json
{
  "command": "ATTLOG_RRQ",
  "expected": 326124,
  "received": 182764,
  "receive_buffer_usable": 212992,
  "receive_buffer_granted": 425984
}
```

- `receive_buffer_usable` **below** `expected` → the socket buffer cannot hold the
  table. Raise `net.core.rmem_max` **on the host**; inside an unprivileged LXC
  container that file is read-only and the change cannot be made from within.
  Note `granted` is Linux's doubled bookkeeping value — `usable` is the real one.
- `receive_buffer_usable` **null** → `ext-sockets` is missing and the socket is on
  the platform default. Install it.
- Both fine and it still fails → the device is not sending, not the kernel
  dropping. Confirm with `/proc/net/snmp`:

  ```
  awk '/^Udp:/{getline; print "RcvbufErrors", $5}' /proc/net/snmp
  ```

  Read it before and after a failing transfer. A delta of a few against a loss of
  a hundred-odd datagrams means the kernel is innocent — which is exactly what was
  measured on the F22, and why the windowed read exists.

### `Windowed transfer stalled` — the device staged it and then did not deliver

Context carries `window_offset` and `window_bytes`. This is not a race: the window
was requested explicitly and the device had nothing else in flight. Retrying the
whole read (which `BulkReader` does five times, restaging each time) is the only
handling, and a persistent stall is a device-side problem.

Neither exception ever returns a partial table. A short attendance read that
"succeeded" would silently drop punches, which is the failure this project has
already had once.

## Undecodable is not "no data"

The same principle in the template codec: an undecodable fingerprint table returns
`[]`/`null`, which the UI renders as **"Fingerprints unknown"** — never
"0 fingerprints". Those two statements lead to opposite decisions about whether
somebody can clock in.
