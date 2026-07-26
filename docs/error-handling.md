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
    $driver->run($device, fn ($d) => $d->users()->save($code, $name));
} catch (DeviceException $e) {
    if ($e->isRetryable()) {
        $command->releaseForRetry($e->getMessage());
    } else {
        $command->fail($e->getMessage(), $e->context());
    }
}
```

## An ack is not a commit

This firmware sometimes acknowledges a write and then discards it. `UserService::save()`
reads the record back and throws `VerificationException` when it is not there:

> The device accepted the write for 261 but the record did not appear when read
> back. This firmware intermittently drops writes — retry, and if it persists the
> device may need a power cycle.

Related and separate: writes also do not persist at all without
`CMD_REFRESH_DATA` (1013) after them. That is not a failure mode, it is a required
step, and every write path in the library does it.

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

## Undecodable is not "no data"

The same principle in the template codec: an undecodable fingerprint table returns
`[]`/`null`, which the UI renders as **"Fingerprints unknown"** — never
"0 fingerprints". Those two statements lead to opposite decisions about whether
somebody can clock in.
