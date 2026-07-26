# The ZKTeco standalone protocol, as observed

Everything in this document was observed against a real terminal. Where a claim
comes from published references rather than this hardware, it says so. Where the
meaning of a byte is genuinely unknown, it says that too — a plausible guess
written as fact is how a payroll system quietly produces wrong numbers.

## The reference device

| | |
|---|---|
| Model | ZKTeco **F18/ID** |
| Firmware | `Ver 6.60 Apr 26 2016` |
| Platform | `ZMM210_TFT` |
| Serial | `AIOR204860846` |
| Address | `192.168.7.62:4370` |
| Comm key | `0` (unset) |
| Device timezone | `+03:00` |

No vendor DLL, OCX or SDK is involved anywhere. The library speaks the binary
protocol directly over a socket.

## Transport: UDP, not TCP

The standalone protocol is commonly documented as TCP with a six-byte header
(`0x5050827d` + payload length). **This hardware answers on UDP** at port 4370
and does not complete a TCP handshake on that port. `UdpTransport` is therefore
the only transport the library ships.

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
`tests/Fixtures/zkteco/fingerprint-template-table.bin`.

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
