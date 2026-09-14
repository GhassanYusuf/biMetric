# The ZKTeco standalone protocol, as observed

**Scope: this document describes ONE of the two protocol families in this estate.**
It covers the ZKTeco **standalone** binary protocol — the F18, the F22, and the
F-/K-series generally. The second family, **C3 / PullSDK**, is spoken by the
inBIO460 *and by the TF1700*, shares nothing with this one but a port number, and is
documented in [device-capabilities.md](device-capabilities.md). Do not carry a fact
from one family to the other; they differ in transport, framing, checksum and
session model.

Everything in this document was observed against a real terminal. Where a claim
comes from published references rather than this hardware, it says so. Where the
meaning of a byte is genuinely unknown, it says that too — a plausible guess
written as fact is how a payroll system quietly produces wrong numbers.

## The reference devices

There are two, and they do **not** behave identically. Where a claim below names
only one of them, that is deliberate — it has not been confirmed on the other.

| | Reference | Second device |
|---|---|---|
| Model | ZKTeco **F18/ID** | ZKTeco **F22** |
| Firmware | `Ver 6.60 Apr 26 2016` | not yet recorded |
| Platform | `ZMM210_TFT` | not yet recorded |
| Serial | `AIOR204860846` | not yet recorded |
| Address | `192.168.7.62:4370` | `192.168.7.63:4370` |
| Comm key | `0` (unset) | `0` (unset) |
| Device timezone | `+03:00` | `+03:00` |
| In this app | device 5 | device 6, "On The Road" |

The F22 is the reason the windowed read below exists: the free-running bulk
transfer that works on the F18 is unreliable on it.

No vendor DLL, OCX or SDK is involved anywhere. The library speaks the binary
protocol directly over a socket.

## Transport: UDP, not TCP

The standalone protocol is commonly documented as TCP with an 8-byte transport
header (`0x5050` + reserved + uint32 length). **This hardware answers on UDP** at
port 4370. `UdpTransport` is the only transport the library ships.

### Careful: TCP 4370 is OPEN, it just does not answer

An earlier version of this note said the device "does not complete a TCP
handshake". That is wrong and it misleads anyone holding a port scanner.

RE-CONFIRMED on the F18 at 192.168.7.62:

| Probe | Result |
|---|---|
| `tcp://…:4370` connect | **succeeds in ~1ms** — the port is open |
| CONNECT over TCP, 8-byte wrapper | 0 bytes back, `eof=true` — device closes immediately |
| CONNECT over TCP, bare packet | 0 bytes back, `eof=true` — same |
| CONNECT over UDP (control) | 8 bytes back: `d50738f3f1040100` → `ACK_UNAUTH`, session assigned |

So the TCP listener accepts and then hangs up without a word, for both framings.
A scan reporting `4370/tcp open` is not evidence that TCP works here, and a
firewall rule written for TCP will let a connection through that can never carry
the protocol.

### The consequence you will actually feel

There is no stream to rely on, and **no flow control**. Every read has to cope with
datagrams arriving out of order, arriving late from a *previous* request, or not
arriving at all — and with the device out-running the socket receive buffer, which
surfaces as `Incomplete transfer: got X of Y bytes`.

### The receive buffer was ONE root cause — fixed, but not the only one

That failure used to be common: roughly **1 read in 3** of the 412,084-byte
attendance table died with `Incomplete transfer`, with nothing attached to it.

On the **F18** the cause was not the network. **The OS default receive buffer is
64 KB and the table is 412 KB.** With no flow control, any pause longer than it
takes 64 KB to arrive means the kernel discards the rest before PHP ever sees it.

> **Do not read this section as "short transfers are solved".** It fixed the F18.
> The F22 fails the same way for an entirely different reason — the device stops
> sending — and no buffer size fixes that. See
> [Windowed reads](#windowed-reads-the-flow-controlled-alternative-confirmed-f22).

`UdpTransport::enlargeReceiveBuffer()` now asks for **4 MB**, so a whole table fits
and a pause costs latency instead of data:

| | Transfers completed |
|---|---|
| 64 KB default | ~2 of 3 |
| 4 MB buffer | **25 of 26** |

It needs `ext-sockets`, which is a **suggest, not a require** — without it the
request is skipped silently and the old behaviour returns. `DeviceConfig::$receiveBufferBytes`
sets the size (`DEVICE_RECEIVE_BUFFER` in this app); the granted value is readable
via `UdpTransport::receiveBufferBytes()`, because Linux halves the request and
clamps it to `net.core.rmem_max`.

`BulkReader::MAX_ATTEMPTS = 5` and the reopen-per-read rule remain as the backstop.
TCP would remove the class of bug entirely; the device will not speak it.

The practical consequence is that there is no stream to rely on. Every read has
to cope with datagrams arriving out of order, arriving late from a *previous*
request, or not arriving at all. Most of the hard-won behaviour below exists for
that reason.

## Packet layout

Every packet is an eight-byte header followed by an optional payload:

```
offset  size  field
0       2     command (request) or reply code (response), little-endian
2       2     checksum
4       2     session id
6       2     reply counter
8       n     payload
```

`Packet::build()` writes this; `Packet::parse()` reads it. The checksum is a
one's-complement sum over 16-bit words with the checksum field zeroed —
`Packet::checksum()`.

### Session handshake

1. `CMD_CONNECT` (1000) with session id 0.
2. The device answers `CMD_ACK_OK` (2000) carrying the session id to use for
   every subsequent packet.
3. If the device requires a comm key it answers `CMD_ACK_UNAUTH` (2005), and the
   client replies `CMD_AUTH` (1102) with `Packet::makeCommKey()`.

The reply counter increments per packet. A device that receives an unexpected
counter simply ignores the packet, which reads as a timeout.

## Bulk transfer: the PREPARE_DATA envelope

Anything larger than one datagram — the user table, the attendance log, the
fingerprint template table — arrives as a stream:

```
client → CMD_USERTEMP_RRQ / CMD_ATTLOG_RRQ / CMD_DB_RRQ
device → CMD_PREPARE_DATA (1500), payload = total byte length
device → CMD_DATA (1501) × N
device → CMD_ACK_OK
```

Two properties of this envelope caused real bugs and shape the whole library:

### 1. The device keeps sending after you stop reading

Once `CMD_PREPARE_DATA` has announced N bytes, the device sends all of them
regardless of whether the client is still listening. If the client takes the
bytes it wanted and issues a *different* command on the same socket, the
leftovers arrive first — and because they are well-formed `CMD_DATA` packets,
they are indistinguishable from a legitimate reply to the new command.

The symptom was that a second bulk read on one connection returned the *first*
read's data. Concretely: reading users and then the template table reported
"fingerprints unknown" for every employee, and a diagnostic probe reported
"0 users" on a device holding 19.

The fix is structural, in `BulkReader`:

```php
$this->session->reopen();          // fresh session per bulk read
$data = $this->readOnce($command, $requestData);
$this->session->markDirty();       // the stream may still be draining
return $data;
```

`Session::markDirty()` / `ensureClean()` also guard the single-packet path in
`DeviceClient::send()`: any command that triggers `PREPARE_DATA` marks the
session dirty, and the next command reopens before sending. Without this,
template slot probes came back shifted by exactly one request.

### 2. Writes do not persist without CMD_REFRESH_DATA

After `CMD_USER_WRQ`, `CMD_DELETE_USER` or a template delete, the device
acknowledges the write and then discards it unless `CMD_REFRESH_DATA` (1013)
follows. The ack is not a commit. Every write path in the library sends it.

## Windowed reads: the flow-controlled alternative (CONFIRMED, F22)

**Status: CONFIRMED on the F22, 2026-09-13.** This is now the preferred path for
every bulk read, with the push envelope above as the fallback.

### The problem it solves

On the F22, the push envelope above is not reliable. Asking for the 326,124-byte
attendance table and then draining it produced, across 20 attempts:

| Result | Frequency |
|---|---|
| 54-75% of the table, then four seconds of silence | typical |
| 99.7% (one datagram short) | occasional |
| 100% in 33-50 ms | occasional |

The table arrives as a **single burst of roughly 33 ms at close to line rate**, so
either the reader keeps up for all of it or the read fails.

### It is NOT the receive buffer — measured

This looks exactly like buffer overflow, and it is not. `/proc/net/snmp` counts
datagrams the kernel dropped for want of receive buffer:

```
RcvbufErrors before: 13550
stream  granted=425984  got=177152 of 326124 (54.3%)
RcvbufErrors after:  13553   delta=3
```

**Three** dropped datagrams across a read that lost about 145,000 bytes — roughly
145 datagrams. The kernel received and delivered everything it was given. The
device stopped sending.

Two further measurements point the same way, and both contradict the buffer
theory outright:

- Varying the requested buffer (64 KB / 128 KB / 4 MB) did not improve capture.
  The **smallest** setting produced the most complete transfers in that sample.
- A failing read always ends in the full silence timeout, never in a trickle.

### The fix: ask for one window at a time

```
client → CMD_PREPARE_BUFFER (1503), payload = uint8(1) uint16(command) uint32(fct) uint32(ext)
device → CMD_ACK_OK (2000),  payload = byte + uint32 total size
   repeat until the table is consumed:
client →   CMD_READ_BUFFER (1504), payload = uint32(start) uint32(length)
device →   CMD_PREPARE_DATA (1500) then CMD_DATA (1501) × N, then CMD_ACK_OK
           (or CMD_DATA inline, for a small window)
client → CMD_FREE_DATA (1502)
```

Because the device sends **only what was just asked for**, there is no burst to
outrun and no race to lose. The library uses a **16 KB** window
(`BulkReader::WINDOW_BYTES`), chosen to sit below even the 64 KB platform-default
receive buffer.

| Window size | Result on the F22 |
|---|---|
| 16 KB | 5 of 5 complete, ~6.6-7.4 s |
| 32 KB | 3 of 3 complete, ~6.4-7.3 s |
| 64 KB | 3 of 3 complete, ~6.4-7.1 s |

Larger windows buy no speed — the device's own staging time dominates — and only
narrow the safety margin, so 16 KB stands. The whole table decoded to all **8,153
attendance records**, oldest 2024-01-21, newest the moment of the read.

### Support is PER TABLE, not per device

This is the trap. The same F22, in one sitting:

| Table | Request | `CMD_PREPARE_BUFFER` answer |
|---|---|---|
| Attendance | `CMD_ATTLOG_RRQ` | `ACK_OK`, size 326,124 — **supported** |
| Templates | `CMD_DB_RRQ` + `FCT_FINGERTMP` | `ACK_OK`, size 18,486 — **supported** |
| Users | `CMD_USERTEMP_RRQ` | reply **4991** — **not supported** |

So a caller **must negotiate per read**, not per device, and must treat a refusal
as an ordinary answer rather than an error. `BulkReader::readWindowed()` returns
`null` on refusal and the push path runs instead. Reply `4991` is not a documented
code and its meaning beyond "no" is unknown.

### `fct` has to be carried across

The selector that distinguishes tables sharing a command — `CMD_DB_RRQ` is the
template table only because `FCT_FINGERTMP` says so — is a single leading byte in
the push request, but a **uint32 field** in the `CMD_PREPARE_BUFFER` payload. Pass
it through unwidened and the device stages the wrong table.

### Results after the change

Eighteen consecutive library reads across all three tables, on the F22:

| Table | Bytes | Records | Result |
|---|---|---|---|
| Attendance | 326,124 | 8,153 | 6 of 6 (windowed) |
| Users | 796 | 11 | 6 of 6 (fell back to push) |
| Templates | 18,486 | 16 | 6 of 6 (windowed) |

Before the change, attendance was 0 of 3.

### Progress reporting is free on this path

The push path has to ration progress events, because time spent in a listener is
time not spent draining a burst that will not wait (see `BulkReader::emit()`).
On the windowed path the device sends nothing until asked, so a listener may take
as long as it likes between windows. Progress is reported **once per window**,
unconditionally — the one place in the library where a smooth bar costs nothing.

## Record formats

### User record — 72 bytes (`Packet::buildUserRecord()`)

| offset | size | field |
|---|---|---|
| 0 | 2 | uid (device-internal slot) |
| 2 | 1 | privilege |
| 3 | 8 | password |
| 11 | 24 | name |
| 35 | 4 | card number |
| 39 | 1 | group — **must be ≥ 1** |
| 40 | 2 | user timezone flag |
| 42 | 6 | tz1 / tz2 / tz3 (2 bytes each) |
| 48 | 9 | user id (the PIN attendance records carry) |
| 57 | 15 | zeros |

A `group` of 0 is accepted and then ignored: the record appears to write and the
user cannot authenticate. It must be at least 1.

There is **one card field per user**. A user cannot hold two cards, so
"replace the card" and "add a card" are the same operation, and clearing a card
means writing 0.

### Attendance record — 40, 16 or 8 bytes

The device chooses the width based on how much it has to say; `AttendanceCodec`
detects it from the total payload length. In the 40-byte form:

| offset | size | field |
|---|---|---|
| 0 | 2 | uid |
| 2 | 24 | user id (NUL-terminated) |
| 26 | 1 | verify method |
| 27 | 4 | timestamp (packed) |
| 31 | 1 | status — constant `255` here |
| 32 | 8 | reserved |

Byte 31 is 255 on **every** record read from this unit, matching the `status`
column of the vendor's own CSL/HTTP export. It is a placeholder, **not** a punch
direction — do not read in/out from it.

A chunk that fails to decode is kept as `['raw' => <hex>]` rather than dropped, so
the caller can count and archive it. That is the mechanism behind the
`undecodable` count on every sync run.

The 16- and 8-byte layouts are DOCUMENTED only; this hardware always sends 40.

**Verify method values 3 and 4 are undecoded.** The schema comment documents
0/1/2/15 (password / fingerprint / card / other) from published references, but
this device emits 3 and 4 as well and we have no evidence for what they mean.
They are stored verbatim rather than being mapped to a guess.

### Packed timestamp (`Codec\Timestamp`)

A 32-bit value decoded as:

```
second =  t % 60;  t /= 60
minute =  t % 60;  t /= 60
hour   =  t % 24;  t /= 24
day    =  t % 31 + 1;  t /= 31
month  =  t % 12 + 1;  t /= 12
year   =  t + 2000
```

The timestamp carries **no timezone**. It is device-local — `+03:00` here — and
must be converted before storage. See
[attendance-sync-flow.md](attendance-sync-flow.md) for the two separate bugs
this caused.

### Fingerprint template table

`CMD_DB_RRQ` with `FCT_FINGERTMP` returns a table of fixed-size entries, one per
enrolled template. On the reference device this table is **22,066 bytes**; a
capture of it is committed as a test fixture at
`vendor/promoseven/biometric-protocol/tests/Fixtures/fingerprint-template-table.bin`.

`TemplateCodec` requires the table to consume **exactly** its declared length. On
any misalignment it returns `[]`, which the app renders as
*"fingerprints unknown"* — never as *"no fingerprints"*. Those two statements
mean opposite things to whoever is deciding if an employee can clock in, and
guessing is not an option. This decoding has **not** been verified against a
second device or firmware revision.

### Enrollment request — 26 bytes (`Packet::buildEnrollRequest()`)

| offset | size | field |
|---|---|---|
| 0 | 9 | user id, **truncated to 9 bytes** |
| 24 | 1 | finger slot (0–9) |
| 25 | 1 | flag |

Followed by `CMD_START_ENROLL` (61). `CMD_TEST_VOICE` (1017) produces the beep
that tells the person at the terminal to present a finger.

## Command confidence markers

`Protocol\Command` marks every constant:

- **CONFIRMED** — exercised against this hardware
- **DOCUMENTED** — from published references, not exercised here
- **EXPERIMENTAL** — implemented on reasonable inference, unverified

`CMD_DELETE_USERTEMP` (134) is EXPERIMENTAL: its payload is inferred as
`(uint16 uid, uint8 finger slot)`. Deleting a single fingerprint has never been
confirmed on hardware. See [device-capabilities.md](device-capabilities.md) for
how that uncertainty reaches the UI instead of being hidden in a comment.

## Known unknowns

- **Reply `4991`** is what the F22 returns from `CMD_PREPARE_BUFFER` for the users
  table. **Partly resolved 2026-09-14:** the vendor's own error dictionary
  (`Languages/language_en-US.xml`, `SDKError_StandAlone`, from the owner's licensed
  ZKAccess 3.5.5) gives **`-4991` = "Operation database has no corresponding data"**,
  and the neighbouring `-4987/-4988/-4989` are "session buffer" codes. So this is a
  **semantic refusal about that table's contents, not a transport failure** — which
  is consistent with our reading that staging support is per table rather than per
  device. What remains unknown is why the F22's users table specifically provokes
  it while its attendance table does not. Our fallback behaviour is unchanged and
  still correct.
- **Why the F22 stops sending mid-burst** on the push path is unexplained. We have
  ruled out the socket receive buffer (three `RcvbufErrors` across ~145,000 lost
  bytes) and local contention (no other process held a socket to the device). It
  could be the device's own send pacing, or the routed hop between the server's
  subnet and the device's. The windowed read makes it moot rather than answering
  it.
- The **F18 supports windowed reads** — CONFIRMED on Camelot (`87.237.196.60`)
  2026-09-14: it staged a 1,664,804-byte attendance table and served 16 KB windows.
  Previously untested; the library negotiates per read regardless.
- **Over a public link a window stalls, and the reader now resumes it.** On the same
  Camelot read a packet capture showed ~7 stalls per full read, each a >2 s silence
  mid-window — not a buffer problem (`RcvbufErrors` delta 0) but the device (or the
  routed hop) going quiet. The reader re-requests the stalled window at the same
  offset rather than discarding the transfer; see `DeviceConfig::$bulkWindowRetries`
  and `docs/attendance-sync-flow.md`.
- Fingerprint template decoding is unverified on any second device.
- `verify_method` 3 and 4 have no known meaning.
- A successful fingerprint *capture* has never been observed end to end — it
  needs a person at the terminal, and every attempt so far ended in `timeout`.
- Card `4294967295` (`0xFFFFFFFF`) appears on one real employee record. It is
  flagged as a sentinel (`AccessCard::looksLikeSentinel()`) rather than trusted
  as a card number, but we do not know whether the device means "no card",
  "error", or a genuine value.
- The reference device's clock runs approximately **6.4 minutes ahead** of the
  server and drifts. `SET_TIME` is DOCUMENTED, not CONFIRMED, so the correction
  has not been fired automatically.

## The other family: C3 / PullSDK

Nothing above applies to it. The inBIO460 (`192.168.7.61`) and the **TF1700**
(`192.168.7.60`) speak C3/PullSDK over **TCP**, with an `AA 01 … 55` frame, a
**CRC-16/ARC** check, and — on the inBIO460 — no session at all.

Its bulk read is the analogue of `PREPARE_DATA` here, and was solved on 2026-09-14:
`0x0D` PREPARE_DATA → `0x0E` DATA (repeated) → `0x0F` FREE_DATA, with a 17-byte
staging header carrying a **CRC-32 that makes the read self-verifying**. Both panels
were read end to end with the checksum matching. The full byte layout, the pull
loop, the per-model session difference, and where the knowledge came from are in
[device-capabilities.md](device-capabilities.md).

That protocol has **no driver in this app** and every write on it is unbuilt. The
probe is `tools/zkprobe/inbio460.php`.
