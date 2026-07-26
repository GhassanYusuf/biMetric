# Library API reference

`Zkteco\Protocol` — 31 classes. This documents the public surface; the internals
(`Transport`, `Session`, `Codec`, `Packet`) are documented in
[protocol-overview.md](protocol-overview.md) and normally not called directly.

---

## `Client\Device` — the front door

```php
use Zkteco\Protocol\Client\Device;
use Zkteco\Protocol\Value\DeviceConfig;
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
| `DeviceConfig` | `host`, `port = 4370`, `commKey = 0`, `timeout = 8.0`, `timezone = '+03:00'` |
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
