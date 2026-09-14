# Library API reference

`Promoseven\Biometric` — 31 classes. This documents the public surface; the internals
(`Transport`, `Session`, `Codec`, `Packet`) are documented in
[protocol-overview.md](protocol-overview.md) and normally not called directly.

---

## Two families, two front doors

This library speaks **two unrelated protocols**. Everything below documents the
ZKTeco **standalone** family (`Promoseven\Biometric\Protocol`, `Client\Device`)
unless it says otherwise. The **C3 / PullSDK** family lives under
`Promoseven\Biometric\C3` and shares no frame, checksum, session model or command
constant with it — see "`C3\Client\Panel`" at the end of this file.

## `Client\Device` — the front door

```php
use Promoseven\Biometric\Client\Device;
use Promoseven\Biometric\Value\DeviceConfig;
```

### Lifecycle

```php
Device::run(DeviceConfig $config, Closure $callback, ?CapabilitySet $capabilities = null): mixed
```

Opens a session, invokes `$callback(Device $device)`, and **closes the session
even if the callback throws**. This is the form to use.

```php
$users = Device::run($config, fn (Device $d) => $d->users()->all());
```

```php
Device::open(DeviceConfig $config, ?CapabilitySet $capabilities = null): self
$device->close(): void
```

Manual lifetime. Use only when a session must outlive one function — and then
close it in a `finally`.

### Service accessors

| Method | Returns |
|---|---|
| `users()` | `Service\UserService` |
| `attendance()` | `Service\AttendanceService` |
| `enrollment()` | `Service\EnrollmentService` |
| `templates()` | `Service\TemplateService` |
| `client()` | `Client\DeviceClient` (low level) |
| `config()` | the `DeviceConfig` in use |

### Device-level operations

| Method | Level | Notes |
|---|---|---|
| `ping(): array` | confirmed | |
| `info(): array` | confirmed | model, firmware, platform, serial, MAC, capacities |
| `time(): ?DateTimeImmutable` | confirmed | device-local, anchored to `config()->timezone` |
| `syncTime(?DateTimeInterface $to = null): void` | **implemented** | never confirmed on hardware |
| `restart(): void` | **implemented** | deliberately never exercised |
| `capabilities(): CapabilitySet` | | the set in effect |
| `probeCapabilities(): CapabilitySet` | | non-destructive probe, returns an upgraded set |

---

## `Service\UserService`

```php
all(bool $withFingerprints = false): array          // DeviceUser[]
find(string $userId, bool $withFingerprints = false): ?DeviceUser
exists(string $userId): bool
save(string $userId, string $name, ?int $privilege = null, ?int $cardNumber = null): DeviceUser
ensureExists(string $userId, string $name, ?int $cardNumber = null): DeviceUser
delete(string $userId): void
setCard(string $userId, ?int $cardNumber): DeviceUser
softDisable(string $userId): DeviceUser
```

### `save()` — the nullable arguments are the whole point

Privilege and card live in the **same 72-byte record** as the name. Writing that
record with the defaults wipes both. A caller who only means to rename somebody
would silently clear their card and demote an admin to a plain user.

So `$privilege` and `$cardNumber` are nullable, and `null` means *read the current
value back and preserve it*. Pass a value only when you intend to change it.

```php
$device->users()->save('261', 'Ghassan Yusuf');           // card + privilege preserved
$device->users()->save('261', 'Ghassan Yusuf', 0, 0);     // card CLEARED, admin DEMOTED
```

### `save()` verifies the write

The device sometimes acknowledges a write and drops it. `save()` reads the record
back and throws `VerificationException` if it is not there. An ack is not a
commit; see [error-handling.md](error-handling.md).

### `ensureExists()`

A template or card attaches to an existing record, so capturing for somebody the
device has never heard of is refused at the protocol level. `ensureExists()`
creates the record if needed, turning a two-step dance (that used to fail with an
error in the middle) into one call.

### `softDisable()`

There is no suspend flag in the protocol. This clears the card, which is an
approximation — the user's fingerprints still work. Named `soft` for that reason.

---

## `Service\AttendanceService`

```php
all(): array                                        // decoded punches
raw(): string                                       // undecoded bytes
since(DateTimeInterface $since): array
latestTimestamp(): ?DateTimeImmutable
count(): int
listen(int $eventMask = Command::EF_ATTLOG): void
nextRealtimePunch(float $pollSeconds = 0.5): ?array
clearAll(): void                                    // implemented, destructive
```

Decoded punches are `DateTimeImmutable` anchored to the **device's** timezone.
Convert to UTC before storing — see [attendance-sync-flow.md](attendance-sync-flow.md)
for the two production bugs caused by not doing so.

`since()` filters after reading. The protocol offers no server-side date filter:
the device always sends its whole log, so an "incremental" sync is incremental in
what it *stores*, not in what it transfers.

`raw()` exists so a host can archive the original bytes before decoding. That
archive is what made recovering 2,823 silently-dropped records possible.

---

## `Service\TemplateService`

```php
slotsByUserId(): array          // ['261' => [0, 6], ...]  or [] if undecodable
slotsFor(string $userId): ?array
countsByUserId(): array
slotIsFilled(int $uid, int $fingerIndex): bool
deleteSlot(string $userId, int $fingerIndex): array   // implemented, inferred payload
rawTable(): string
downloadTemplate(int $uid, int $fingerIndex): string  // NOT IMPLEMENTED
uploadTemplate(int $uid, int $fingerIndex, string $template): void  // NOT IMPLEMENTED
```

### The `[]` versus `null` distinction is load-bearing

`slotsByUserId()` returns `[]` when the template table **could not be decoded**.
That is not the same as "nobody has fingerprints", and the host must not conflate
them. `slotsFor()` returns `null` for an undecodable table and `[]` for a user
genuinely holding no templates.

The app surfaces this as *"Fingerprints unknown"*, never as *"0 fingerprints"* —
because the two answers lead to opposite decisions about whether someone can clock
in.

`TemplateCodec` only reports slots if the table consumed **exactly** its declared
length. Any misalignment fails safe to unknown rather than reporting a plausible
subset.

---

## `Service\EnrollmentService`

```php
captureFingerprint(
    string $userId,
    int $fingerIndex,                 // 0-9
    ?Closure $onProgress = null,
    int $timeoutSeconds = self::DEFAULT_FINGER_TIMEOUT,  // 30
): EnrollmentResult

captureCard(
    int $uid, string $userId, string $name, int $privilege = 0,
    ?Closure $onProgress = null,
    int $timeoutSeconds = self::DEFAULT_CARD_TIMEOUT,   // 20
): EnrollmentResult

captureCardForUser(string $userId, ?Closure $onProgress = null, int $timeoutSeconds = …): EnrollmentResult
cancel(): void
clearCard(int $uid, string $userId, string $name, int $privilege = 0): void
```

These **block** while a human interacts with the terminal. Never call them in a
web request; queue them. See [enrollment-flow.md](enrollment-flow.md).

### `$onProgress`

Invoked with each device event as it arrives, so a host can persist a live trail:

```php
$device->enrollment()->captureFingerprint('261', 6, function (string $event) {
    // 'beeped', 'waiting_for_finger', 'enrolled', 'timeout'
    $session->appendEvents([$event]);
});
```

The complete event vocabulary is those five strings — `beeped`,
`waiting_for_finger`, `waiting_for_card`, `enrolled`, `timeout`. Nothing else is
emitted. A trail may repeat a cycle (`beeped, waiting_for_finger, timeout,
beeped, waiting_for_finger, timeout`) when the capture retried.

`$onProgress` is nullable, and the implementation checks it explicitly. `?->()`
does **not** work for invoking a nullable closure in PHP — that mistake threw here
once.

### `EnrollmentResult`

```php
// readonly state
$result->outcome;      // string
$result->fingerIndex;  // ?int
$result->cardNumber;   // ?int
$result->errorCode;    // ?int
$result->events;       // array

// queries
$result->succeeded(): bool
$result->didTimeOut(): bool
$result->isRetryable(): bool
$result->toArray(): array

// static constructors
EnrollmentResult::fingerprint(int $fingerIndex, array $events = [])
EnrollmentResult::card(int $cardNumber, array $events = [])
EnrollmentResult::rejected(int $errorCode, array $events = [])
EnrollmentResult::timeout(array $events = [])
```

The static constructor is `timeout()` and the instance check is `didTimeOut()`.
They were both once called `timedOut()`, which is not legal PHP.

---

## `Capability\*`

```php
$set = CapabilitySet::defaults();
$set->supports(Capability::TEMPLATE_DELETE): bool     // usable = confirmed|implemented
$set->isConfirmed(Capability::ATTENDANCE_READ): bool
$set->level(Capability::SET_TIME): string             // 'implemented'
$set->forUi(): array                                  // every key, for a client
Capability::describe($level): string
```

See [device-capabilities.md](device-capabilities.md).

---

## `Exception\*`

All extend `DeviceException extends \RuntimeException` and carry `context(): array`.

| Class | Retryable | Means |
|---|---|---|
| `ConnectionException` | yes | could not reach or handshake |
| `TimeoutException` | yes | no answer in time |
| `VerificationException` | yes | device acked a write that did not stick |
| `ProtocolException` | no | malformed/unexpected bytes, or a precondition failed |
| `UnsupportedCommandException` | no | the capability set forbids it |
| `DeviceException` | no (base) | |

`isRetryable()` is the decision a host's retry policy should read, rather than
matching on message text. See [error-handling.md](error-handling.md).

---

## `Value\*`

| Class | |
|---|---|
| `DeviceConfig` | `host`, `port = 4370`, `commKey = 0`, `timeout = 8.0`, `timezone = '+03:00'`, `receiveBufferBytes = 4 MB`, `bulkWindowRetries = 4` (times a stalled windowed-read window is re-requested before the transfer is abandoned; raise for devices reached over the internet, `0` for pre-2.1.0 behaviour), `transport = 'udp'` (`'udp'`/`'tcp'`; TCP speaks the same commands inside the 8-byte TCP frame — see `TcpTransport` — with flow control and retransmission, for devices reached over the internet) |
| `DeviceUser` | `uid`, `userId`, `name`, `privilege`, `cardNumber`, `fingerSlots`; plus `hasCard()`, `fingerprintCount()`, `cardLooksLikeSentinel()` |
| `Reply` | a parsed response: command, session, counter, payload |
| `EnrollmentResult` | above |

`DeviceUser::$fingerSlots` is `null` when the template table could not be decoded,
and `fingerprintCount()` returns `null` in that case rather than `0` — the same
distinction as `TemplateService`.

`cardLooksLikeSentinel()` flags values such as `4294967295` (`0xFFFFFFFF`), which
appears on a real employee record. We do not know whether the device means "no
card", "read error", or a genuine number, so it is surfaced as suspect rather than
trusted.

## Progress on bulk transfers

`Device::onProgress(?ProgressListener)` attaches a listener that receives a
`Value\Progress` while a bulk table streams in.

```php
Device::run($config, function (Device $d) use ($listener) {
    $d->onProgress($listener);

    return $d->attendance()->raw();
});
```

`Progress` carries `operation` (`attendance` / `users` / `templates`), `bytesRead`,
`bytesTotal`, and `attempt`. `percent()` returns **null**, not 0, when the device
declared no total — a small table arrives inline with no `CMD_PREPARE_DATA` and
therefore no announced size.

Progress is in **bytes**, deliberately. The reader is generic over three tables and
this protocol never announces a record width; deriving records is the caller's job
(`RecordBuffer::detectRecordSize()` against the declared total).

### The listener contract is strict, for a measured reason

- **Called only from idle windows** — after three consecutive silent polls, never
  while chunks are arriving.
- **Must not throw.** What escapes is swallowed, but do not rely on that.
- **Must be fast, and must throttle anything that writes.**

Reporting per chunk was measured to destroy transfers outright: 0 of 3 completed
with a listener doing one throttled database write per event, against 6 of 6 with
none attached. UDP has no flow control, so time spent in the loop is data lost. See
`BulkReader::emit()` and [attendance-sync-flow.md](attendance-sync-flow.md).

**On the windowed path the contract relaxes, and you get more events.** When the
device stages the table (`CMD_PREPARE_BUFFER`) the library requests one 16 KB
window at a time, so nothing is in flight while the listener runs and progress is
reported **once per window** rather than only during proven silence. A listener
still must not throw, but it no longer has to be quick. Callers cannot choose the
path — it is negotiated per read — so **write every listener to the strict contract
anyway**; the relaxed one is a bonus, not a guarantee.

## Windowed bulk reads

Nothing in the public API changes. `BulkReader` negotiates per read: it asks
`CMD_PREPARE_BUFFER` for the table it wants, uses the flow-controlled path if the
device stages it, and falls back to the free-running push envelope otherwise.

What a **reuser** needs to know:

- A custom `Transport` needs no changes. The negotiation rides on `send()`.
- Implement `Contracts\SupportsRawDrain` on a datagram transport if you want the
  faster reassembly loop (payload without a `Reply` per datagram). It is optional
  in exactly the way `HasReceiveBuffer` is — skip it and the reader uses
  `readUnsolicited()`.
- Support is **per table**, not per device. Do not cache the outcome.
- A stalled window raises `ProtocolException` with `window_offset` and
  `window_bytes` in its context. It never returns a short table.

See [protocol-overview.md](protocol-overview.md#windowed-reads-the-flow-controlled-alternative-confirmed-f22)
for the wire format and the measurements.

## `C3\Client\Panel` — the front door for access-control panels

A different protocol family: ZKTeco **C3 / PullSDK**, spoken by inBIO and C3 access
panels and by the TF1700. TCP 4370 only, `AA 01 … 55` framing, CRC-16/ARC.

**Read-only by construction.** There is no write method on this class. Enrollment,
`SetDeviceData` and the door relay (`CONTROL_DEVICE`, `0x05`) are unbuilt on
purpose — see rule 16 on why a door relay is not probed casually.

```php
$panel = new Panel(new DeviceConfig(host: '192.168.7.61', timezone: '+03:00'), 'inBIO460');
$panel->open();
try {
    $panel->info();                  // array<string,string> of named parameters
    $panel->catalogue();             // C3\Codec\Catalogue — the panel's own schema
    $panel->readTable('transaction'); // list<array<string,int|string>>
    $panel->readAttendance($onProgress); // the shared attendance record shape
} finally {
    $panel->close();
}
```

| Method | Notes |
|---|---|
| `open()` / `close()` | The model decides the session dialect; selection happens once, in the constructor. |
| `info()` | Named parameters. A panel that does not know one omits it, so ask for all. |
| `catalogue()` | The panel describes its own schema. **Table and field indexes are read, never hard-coded** — the inBIO460 reports 12 tables, the TF1700 reports 11. Cached per session. |
| `readTable()` | Handles both answers — inline rows, or a staged read. Callers do not need to know which. |
| `readAttendance()` | `transaction` decoded into the same record shape the standalone family returns, so one consumer serves both. |

### The staged read — `C3\Protocol\BufferedReader`

`GET_DATA` on a populated table stages its answer rather than returning it:
**`0x0D` PREPARE_DATA → `0x0E` DATA (repeated) → `0x0F` FREE_DATA**.

| Offset | Size | Field |
|---|---|---|
| 0 | 1 | compression flag, `0` = raw |
| 1 | 4 | `datalen` — bytes staged |
| 5 | 4 | `oriLen` — length after decompression |
| 9 | 4 | **CRC-32/ISO-HDLC** over the assembled payload |
| 13 | 4 | `packageLen` — most the device puts in one reply |

Each `0x0E` carries a **4-byte little-endian offset and nothing else**; the reply
echoes it back, then the chunk. The offset is a running byte count, advanced by
bytes actually received. `0x0F` takes no argument and is sent on every exit path.

**The CRC is why you can trust the result.** A wrong pull loop fails the checksum
instead of returning a plausible half-table, so `pull()` throws rather than hand
back a short read. No public C3 reimplementation has this path.

### `C3\Codec\Timestamp`

**Not** the standalone family's `Codec\Timestamp` and not a unix epoch. A mixed
radix with a 31-day month:

```
second = t % 60; minute = (t/60) % 60; hour = (t/3600) % 24;
day = (t/86400) % 31 + 1; month = (…) % 12 + 1; year = … + 2000
```

Panels stamp **LOCAL** time and say nothing about the zone, so pass one. Anything
that will not decode returns `null` — never an epoch, never midnight.

### `C3\Model\*` — per-model behaviour

| Class | Panel | Session |
|---|---|---|
| `InBio460` | inBIO460 | Refuses both connect forms; runs session-less. Sends nothing. |
| `Tf1700` | TF1700 | **Requires `CONNECT_SESSION_LESS` (0x01).** |
| `GenericPanel` | anything else | Asks for session-less, tolerates refusal. Confirms no capability. |

`PanelModelFactory::for($name)` is the single selection point. Each model class
owns its own name match and its own `CapabilitySet`, so a model identifier never
appears outside the file named for it.

**The TF1700 trap, in full.** Without `0x01` that panel prefixes every reply with
four session bytes. A session-less reader is then shifted four bytes on every
payload: `~SerialNumber` disappears and `GET_DATA` answers a well-formed `0xC8`
with ten useless bytes. **It does not error — it returns zero records**, so a panel
holding 21,005 rows reports as reachable, healthy and empty.
