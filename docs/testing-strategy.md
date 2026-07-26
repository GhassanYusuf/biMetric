# Testing strategy

**139 tests, 766 assertions, ~10 seconds, no hardware required.**

```bash
php artisan test
php artisan test --filter=TimestampTest
```

Everything runs against in-memory SQLite (see `phpunit.xml`), fully isolated from
real data. No test opens a socket.

## The principle

The bugs that hurt this system were never crashes. They were **silent, plausible
wrong answers**: 34% of attendance records discarded without an error, a watermark
three hours in the future, a template table that reported "no fingerprints" when it
meant "could not read".

So the tests concentrate on the paths where being wrong looks exactly like being
right, and several exist specifically to make a known past bug impossible to
reintroduce.

## What is covered

| Suite | Tests | What it protects |
|---|---:|---|
| `TimestampTest` | 19 | **The 34% data loss.** Single-digit components, timezone anchoring, watermark conversion, round trips |
| `DeviceQueueTest` | 18 | Work is queued not performed, capability refusals, tenant isolation, group fan-out |
| `PacketTest` | 16 | Byte-for-byte encoding: 72-byte user record, 26-byte enroll request, checksum, comm key |
| `AttendanceCodecTest` | 11 | Undecodable records are kept and counted, never dropped |
| `CapabilityTest` | 10 | The four levels stay distinct; unproven capabilities cannot be quietly promoted |
| `SessionLifecycleTest` | 10 | Dirty tracking — the fix for "fingerprints unknown" |
| `FingerprintTemplateDecodingTest` | 8 | Real 22,066-byte device fixture; fail-safe on any misalignment |
| `DeviceExceptionTest` | 7 | Retryable vs terminal classification |
| `ApiFlowTest` | 7 | Multi-tenant permission matrix end to end |
| `ProtocolLibraryBoundaryTest` | 5 | The library stays framework-agnostic |
| `FrontendConventionsTest` | 3 | No native dialogs, toasts not banners |

Plus Laravel's own auth and profile tests.

## The regression tests that matter most

### The 34% data loss

```php
#[DataProvider('singleDigitComponents')]
public function test_single_digit_components_decode_rather_than_being_discarded(...)
```

Eleven cases, every one silently discarded by the original decoder, plus a test
that walks **all 3,600 seconds of an hour** and asserts none decode to null. If
that ever fails, attendance data is being lost again.

### Undecodable is kept, not dropped

```php
public function test_an_undecodable_record_is_kept_as_raw_hex_rather_than_dropped()
```

A bad chunk comes back as `['raw' => <hex>]`. That is what lets the caller **count**
it (`undecodable` on the sync run) and **archive** it — which is the entire reason a
decoder bug was recoverable rather than permanent.

### Fingerprints unknown ≠ no fingerprints

`FingerprintTemplateDecodingTest` runs against a real device capture at
`tests/Fixtures/zkteco/fingerprint-template-table.bin` — 22,066 bytes read off the
reference F18. Truncation, overrun, a slot outside 0–9, and trailing bytes all
abandon the read and report **unknown** rather than a plausible subset.

### Capability honesty

```php
public function test_unproven_capabilities_are_not_marked_confirmed()
```

Seven capabilities are `implemented` because no hardware has ever confirmed them.
Promoting one to `confirmed` breaks this test on purpose: it should require a
deliberate commit that names the device and firmware that proved it.

### The library boundary

`ProtocolLibraryBoundaryTest` fails the build on an `Illuminate\` import inside the
package, a `config()`/`now()`/`app()` call, a second class in `app/` importing
`Zkteco\Protocol`, a controller constructing a session, or a `require` entry beyond
`php`.

This one was verified to actually bite: injecting a deliberate violator file made
it fail with both violations named, and removing the file made it pass again. A
convention test that cannot fail is decoration.

## Testing without hardware

Two techniques carry everything.

**Synthesise the bytes.** `AttendanceCodecTest` builds records in the confirmed
layout and prefixes the envelope, so decoding is exercised exhaustively — including
failure shapes a real device rarely produces.

**Fake the transport.** The `Transport` interface exists so everything above it is
protocol logic and everything below is sockets. `SessionLifecycleTest` implements a
`FakeTransport` answering from memory, which makes the session lifecycle — including
the dirty-tracking that fixed a real data bug — fully testable with nothing on the
network.

```php
class FakeTransport implements Transport
{
    public int $openCount = 0;
    public function send(int $command, string $data = ''): Reply { /* canned */ }
}
```

For the app layer, `Queue::fake()` proves a controller **queued** work rather than
performing it — which is the actual contract.

## What is deliberately not tested here

| | Why |
|---|---|
| Live UDP against a terminal | Needs the hardware on the network. Verified manually and recorded in [device-capabilities.md](device-capabilities.md). |
| A completed fingerprint capture | Needs a person at the terminal. Still unproven — see the roadmap. |
| `attendance.clear`, `device.restart` | Verifying them means destroying data or rebooting a live terminal. |
| React component rendering | No JS test runner is configured. `FrontendConventionsTest` greps for banned patterns instead; the build is the compile check. |

Where something is untestable, the honest response is to mark the capability
`implemented` rather than to write a test that proves nothing.

## Manual checklist against real hardware

Run after any change to the protocol, codecs or sync path.

**Connectivity**
- [ ] `test-connection` succeeds
- [ ] `read_info` returns model, firmware, platform, serial
- [ ] `probe_capabilities` completes and persists `capabilities_probed_at`

**Attendance**
- [ ] A sync completes with `read` matching the device's own record count
- [ ] **`undecodable` is 0**
- [ ] A second sync immediately after reports `imported=0`, high `duplicate` — correct incremental behaviour
- [ ] **The watermark is not in the future** (`SELECT name, attendance_watermark FROM devices WHERE attendance_watermark > NOW()` returns nothing)
- [ ] No rows exactly 3 hours apart beyond genuine in/out pairs
- [ ] A full re-read imports 0 new rows on an already-synced device

**Users and enrollment**
- [ ] The user table reads back the expected number of users
- [ ] Fingerprint slots decode, or report **unknown** — never a wrong count
- [ ] Renaming an employee **preserves their card and privilege**
- [ ] A fingerprint trigger beeps the terminal and the trail reaches `beeped`
- [ ] A capture that nobody answers ends `timeout`, not `failed`
- [ ] Cancelling releases the terminal so it accepts normal punches again
- [ ] **A completed capture** — still unproven; the first success validates the path

**Queue**
- [ ] Two commands for one device serialise rather than interleaving
- [ ] A killed worker's lock expires and commands resume
- [ ] A failed command keeps its error and can be retried from the UI

**Multi-tenancy**
- [ ] Another tenant's device 404s
- [ ] A group cannot absorb another tenant's device by id

## Adding a test

Ask what a wrong answer would look like. If the failure mode is a crash, a light
test is enough. If it is a plausible wrong number that nobody would notice for
months — a count, a timestamp, a capability claim — that is where the effort
belongs, and the test should name the bug it prevents.
