# Device capabilities

## Why four levels and not a checkbox

A boolean can only say "supported" or "not supported". That forces every
unverified feature into one of two lies: claim it works, or claim it does not.

So a capability has four levels:

| Level | Meaning | UI treatment |
|---|---|---|
| `confirmed` | Exercised successfully against real hardware in this project. | Offered normally. |
| `implemented` | The code exists and is believed correct, but **no device has ever confirmed it works**. | Offered, labelled *"unproven"*. |
| `not_implemented` | This library does not do it. | Not offered. |
| `unknown` | Never probed. We have no evidence either way. | Not offered; never described as missing. |

`usable` = `confirmed` or `implemented`. `Capability::describe()` returns the
sentence shown to an operator.

The distinction between `not_implemented` and `unknown` matters. The first is a
statement about our code. The second is an admission about our knowledge —
access-control scheduling may well work perfectly on this hardware; we have never
asked. Reporting it as "not supported" would be inventing a limitation.

## The current set for the ZKTeco standalone protocol

`CapabilitySet::defaults()` is the honest baseline. Every level below reflects
what was observed on the reference F18, not a datasheet.

> **The F22 is not the F18.** A second device (192.168.7.63, app device 6) behaves
> differently on bulk reads, and the difference is per *table* rather than per
> device — see [Windowed bulk reads](#windowed-bulk-reads-not-a-capability-flag)
> below. Treat every level in these tables as "confirmed on the F18" unless it
> says otherwise.
>
> CLAUDE.md **rule 15** follows from this: a model that needs different behaviour
> gets its own file rather than a branch inside the F18 path, and a level confirmed
> on the F18 is `unknown` on another model until that model has been read.

## Windowed bulk reads: deliberately NOT a capability flag

The F22 supports the flow-controlled `CMD_PREPARE_BUFFER` / `CMD_READ_BUFFER`
read for some tables and not others:

| Table | Windowed read |
|---|---|
| Attendance | supported (size 326,124) |
| Templates | supported (size 18,486) |
| Users | **refused** — reply 4991 |

This is **not** modelled as a `Capability`, and that is a considered decision
rather than an oversight:

- Capabilities exist so the **UI can hide actions an operator must not be offered**
  (rule 9). Windowed reads are a transport strategy underneath `attendance.read`
  — the operator asks for the same thing and gets the same answer either way.
  There is no button to hide.
- It is **per table, per read**, so it does not fit a per-device flag. The reader
  asks `CMD_PREPARE_BUFFER` for the specific table it wants and falls back on
  refusal. One round trip, and the negotiation cannot go stale the way a cached
  probe result can.
- `CapabilityResolver` probes read-only, and this *is* read-only — so it could be
  probed. It still should not be, because a cached "supported" would then have to
  be invalidated per table and per firmware update, to gate nothing the operator
  can see.

If a future device refuses windowed reads on a table where the push path is also
unreliable, that is a real operator-visible failure and **`attendance.read` is the
flag that should drop**, not a new one.

### Confirmed (12)

| Key | Notes |
|---|---|
| `device.ping` | |
| `device.info` | Model, firmware, platform, serial, MAC, capacities |
| `device.time.read` | |
| `user.read` | Full user table via the bulk envelope |
| `user.write` | Requires `group ≥ 1` and `CMD_REFRESH_DATA` |
| `user.delete` | |
| `card.read` | One card per user |
| `card.write` | Writing 0 clears it |
| `fingerprint.enroll` | The *trigger* is confirmed; a completed capture is not — see below |
| `template.table.read` | The 22,066-byte table on the reference device |
| `template.slot.probe` | Per-slot occupancy check |
| `attendance.read` | |

### Implemented but unproven (7)

| Key | Why it is unproven |
|---|---|
| `device.time.write` | Never fired. The reference device's clock is ~6.4 minutes fast, but writing a wrong clock would misstamp every future punch, so this waits for a deliberate decision. |
| `template.delete` | `CMD_DELETE_USERTEMP` payload is inferred, not documented. |
| `card.enroll` | Live card capture never completed on hardware. |
| `attendance.realtime` | `REG_EVENT` subscription implemented; a live punch has not been observed arriving this way. |
| `attendance.clear` | Destructive. Deliberately never called to verify it. |
| `device.restart` | Deliberately never called. |
| `user.disable` | Approximated by clearing the card — the protocol has no suspend flag. |

### Not implemented (2)

`template.download`, `template.upload` — the library can read the template
*table* (which slots are filled) but does not transfer template blobs.

### Unknown (5)

`access.verify_mode`, `access.schedules`, `access.anti_passback`,
`access.duress`, `access.relay`.

Never probed. These may exist on the hardware. We claim nothing.

## The CSL/HTTP web firmware

Some ZKTeco hardware ships with reseller-branded firmware ("Secutime"/"CSL") that
does not speak the binary protocol at all. It exposes an authenticated web UI with
form endpoints. `App\Services\Devices\Csl\CslHttpDriver` implements the same
`BiometricDeviceDriver` contract for it, and `DeviceDriverManager` picks between
the two by `devices.protocol`.

Its baseline is deliberately much smaller, and most of it is `not_implemented`
rather than `unknown` — we have looked at the web UI and the endpoints are absent,
which is knowledge, not ignorance:

| Capability | Level | Why |
|---|---|---|
| `ping` | `confirmed` | A completed web login proves both reachability and credentials. |
| `attendance.read` | `confirmed` | The date-range export at `/form/Download`. |
| `user.read` | `confirmed` | The admin user list at `/csl/user`. |
| `user.write`, `user.delete` | `implemented` | The forms are driven, but no write has been observed end-to-end from this app. |
| everything fingerprint, card, template, realtime, clock, restart | `not_implemented` | No such endpoint exists in the web UI. |
| access control (`verify_mode`, schedules, anti-passback, duress, relay) | `unknown` | Never investigated on this firmware. |

Two consequences worth stating plainly:

- **There is nothing to probe.** `probeCapabilities()` verifies reachability and
  then returns the declared baseline unchanged. Inventing a probe that always
  succeeds would promote `DOCUMENTED` to `CONFIRMED` without evidence.
- **There is no session.** `run()` and `open()` throw
  `UnsupportedCommandException`, so the realtime listener and every template
  operation correctly refuse these devices rather than half-working on them. The
  listener also gates on `attendance.realtime`, which is `not_implemented` here, so
  it skips them before ever opening a connection.

### Known debt

`Capability`, `CapabilitySet` and `DeviceException` live inside
`promoseven/biometric-protocol`, so a non-ZKTeco driver still has to import from the
ZKTeco package. That vocabulary is vendor-neutral in content and badly located.
Moving it into its own package is a prerequisite for a **third** vendor; it was not
one for the second, so it is recorded rather than done. The allowlist in
`ProtocolLibraryBoundaryTest` names the exceptions explicitly.

## How a capability reaches the operator

Three layers, all reading the same set:

1. **API refusal.** `DeviceSyncController::capabilityRefusal()` and
   `DeviceEnrollmentController::refuse()` return **422 before anything is
   queued**, with the level's description in the message. A command the firmware
   cannot run never enters the queue.

2. **UI gating.** `DeviceResource` exposes `capabilitySet()->forUi()` — every key
   with its `level`, `usable`, `confirmed` and `description`. The device detail
   screen hides unusable actions and appends *"(unproven)"* to usable but
   unconfirmed ones.

3. **The capabilities panel.** The device detail page lists all 26 keys with
   their level and a legend explaining what each level means, so nothing is
   hidden in a tooltip.

## Probing

`POST /api/devices/{device}/commands` with `probe_capabilities` queues a probe.
`CapabilityResolver` upgrades what it can verify non-destructively and leaves
everything else alone.

`CapabilityResolver::unprobeable()` exists because some capabilities cannot be
tested without doing the damage: you cannot verify `attendance.clear` without
clearing the attendance log, or `device.restart` without rebooting a terminal
somebody is standing at. Those stay `implemented` forever, and that is correct.

Probed results are persisted per device with `capabilities_probed_at`. Until a
probe runs, the device reports the protocol baseline — which the UI labels as
such rather than presenting it as a measurement of that unit.

## Adding a capability

1. Add the constant to `Capability` and to `Capability::all()`.
2. Give it an honest level in `CapabilitySet::defaults()` — `implemented` if you
   wrote it from documentation, never `confirmed` until a real device proved it.
3. If it can be probed safely, handle it in `CapabilityResolver`; if not, add it
   to `unprobeable()`.
4. Gate the endpoint on it.
5. Only after a successful run against hardware, promote it to `confirmed` — and
   say in the commit message which device and firmware proved it.

Promoting a level without hardware evidence is the one change that turns this
whole system back into a set of claims.

## A second protocol family: inBIO460 (C3 / PullSDK) — DOCUMENTED, EXPERIMENTAL

The inBIO460 at `192.168.7.61` (app device 7, "Entrance") is **not** a ZKTeco
standalone terminal like the F18/F22. It is a 4-door access-control **panel** and
speaks the **C3 / PullSDK** protocol, which is unrelated to the standalone protocol
the rest of this document describes:

| | F18 / F22 (standalone) | inBIO460 (C3 / PullSDK) |
|---|---|---|
| Transport | UDP 4370 (this project) | **TCP** 4370 |
| Frame | ZKTeco standalone header | `AA 01 <cmd> <len16> [data] <crc16> 55` |
| Checksum | standalone | **CRC-16/ARC** (poly `0xA001`, init 0), over all but start/end |
| Session | handshake assigns id | **refused; commands run session-less** |

**Confirmed on the panel** (`192.168.7.61`, firmware `AC Ver 5.2.4 Apr 19 2016`,
2026-09-14, all READ-ONLY, all session-less):

| Command | Code | Result |
|---|---|---|
| GETPARAM | `0x04` | `0xC8` — identity and all named parameters |
| DATATABLE_CFG | `0x06` | `0xC8` — full schema (user, transaction, template, …) |
| DISCOVER | `0x14` | `0xC8` — MAC, IP, SN, model, firmware |
| CONNECT (session) | `0x76` | `0xC9` error **−13** "command not available" |
| CONNECT (sessionless) | `0x01` | `0xC9` error **−98** |
| GETDATA | `0x08` | **`0x0D`** — buffered handshake, see below |

**GETDATA does not return rows inline.** It answers `0x0D` (not `0xC8`) with a
17-byte staging header, and the rows are fetched by a follow-up buffered pull.
**This was an open gap until 2026-09-14; it is now CONFIRMED and implemented in the
probe** — see "The `0x0D` buffered read" below. The public reference libraries
(`vwout/zkaccess-c3-py`, `-lua`) implement only the inline `0xC8` case, so this path
is a genuine gap in every public reimplementation of C3, not just in ours.

Read end to end on 2026-09-14, READ-ONLY: `transaction` returned **1,717,298 bytes
in 337 chunks, CRC-32 `3ee6a1f9` verified, decoding to 87,756 rows** spanning
`2000-10-04 23:02:17 .. 2026-09-14 13:04:06` in device local time. The 2000 stamps
are `EventType 206` (Device Started) events recorded before the panel's clock was
ever set — real rows, not a decode error, and a reminder that a device clock is not
evidence of anything by itself.

**Where this device disagreed with the reference libraries**, and why that matters
more than any datasheet (rule 17):

- The references always open a session first; **this firmware refuses connect and
  needs none.** Reading the refusal replies — whose CRCs were all valid — is what
  revealed the session-less path.
- The references expect `0xC8` with inline rows for GETDATA; **this firmware returns
  a `0x0D` buffered handshake.**

When a real inBIO integration is built it is a **new driver implementing
`BiometricDeviceDriver`** (rule 8 — a second vendor-shaped protocol, not a ZKTeco
dialect), in **model/vendor-named files** (rule 15), capability-gated (rule 9), and
its door-relay control (`0x05`) is a WRITE gated behind the owner's authorization
(rule 16). Probe: `tools/zkprobe/inbio460.php`. Traces:
`tools/zkprobe/traces-2026-09-14/inbio-*.json`.

## TF1700 on 192.168.7.60 (Glass Door) — C3 / PullSDK panel, READS CONFIRMED

**CORRECTED 2026-09-14.** An earlier entry in this file concluded that `.60` was a
standalone-family terminal whose comm/SDK service was switched off, and left a
physical menu action owed to Ghassan. **That conclusion was wrong and has been
removed.** The service was serving the whole time. We were speaking the wrong
protocol at it — exactly the inBIO460 mistake, made a second time, three sections
further down the same document.

### What it actually is

The TF1700 at `.60` is **not** a standalone terminal. It answers **C3 / PullSDK**,
the same family as the inBIO460, and it always did.

| | Evidence |
|---|---|
| Serial | `ESK5242000009` |
| Firmware | `Ver 6.64.0013 Oct 16 2018` |
| MAC | `00:17:61:10:96:FD` |
| `MachineType` | **101** = `FPMachine101` (from the vendor's `Machines/MachineType` enum) |
| Doors / readers | 1 / 1, no aux in or out |

Two independent lines of evidence, which is what let this be corrected rather than
merely doubted:

1. **The vendor's own software.** ZKAccess 3.5.5's `Machines` table carries a
   `DevSDKType` column, and the selection is four IL instructions in
   `MachinesManager.dll` (`MachinesManagerPlug::Load`): `_SDKType == 2` constructs
   `STDDevServer` (a COM client of `zkemkeeper`), anything else constructs
   `DevServer` (whose entire P/Invoke table names `plcommpro.dll`). The enum is
   `ZK.Data.Model.SDKType { Undefined = 0, PullSDK = 1, StandaloneSDK = 2 }`.
   The F18 and F22 are stored as `2`. **The TF1700 and the inBIO460 are stored as `1`.**
2. **The wire.** `tools/zkprobe/inbio460.php info 192.168.7.60` — our C3 probe,
   unchanged — read identity on the first attempt.

Caveat worth keeping: `DevSDKType` is what the vendor software *chose* when the
device was added, not something read live off the device. It is strong evidence,
not hardware identity. The wire test is what makes it a fact.

### What the device did on the wire — and the control that makes it evidence

All READ-ONLY, 2026-09-14. Traces: `tools/zkprobe/traces-2026-09-14/inbio-*.json`.

| Command | Code | TF1700 `.60` | inBIO460 `.61` (same family) |
|---|---|---|---|
| CONNECT (session) | `0x76` | **`0xC8` accepted** | `0xC9` error −13 |
| CONNECT (sessionless) | `0x01` | **`0xC8` accepted** | `0xC9` error −98 |
| GETPARAM | `0x04` | `0xC8` full identity | `0xC8` full identity |
| DATATABLE_CFG | `0x06` | `0xC8` 11 tables | `0xC8` 12 tables |
| GETDATA | `0x08` | `0x0D` staged | `0x0D` staged |

**Control, same session (rule 16):** the F18 at `.62`, which is known to work on the
standalone protocol, was sent the identical C3 frames by the identical code. It
refused everything and broke the pipe. So `.60` answering C3 is a fact about `.60`,
not a probe that answers anything that moves.

**A per-model difference inside one family (rule 15):** the TF1700 **accepts** both
connect forms; the inBIO460 **refuses both** and must run session-less. Same
protocol, same vendor, opposite session behaviour. When this is built for real, that
is a selection at construction time, never a conditional in a read loop.

### Full read, CONFIRMED

`transaction` pulled end to end: **367,768 bytes in 72 chunks, CRC-32 `031eaee7`
verified, decoding to 21,005 rows** spanning `2025-08-10 17:28:11 ..
2026-09-14 14:46:03` in device local time.

The first row decodes to `2025-08-10 17:28:11`, which is **exactly** the earliest
TF1700 event in the vendor's own `Access.mdb`. Three independent checks agree: the
CRC-32 the device itself declared, the row count against the vendor's 20,931, and
that timestamp to the second.

### The `0x0D` buffered read — CONFIRMED on both panels

`GETDATA` on a populated table stages the answer instead of returning it. The
sequence is `0x0D` PREPARE_DATA → `0x0E` DATA (repeated) → `0x0F` FREE_DATA — the
standalone family's 1500/1501/1502, renumbered for the panel dialect.

The `0x0D` payload is a packed 17-byte header, little-endian, no padding:

| Offset | Size | Field |
|---|---|---|
| 0 | 1 | compression flag — `0` = raw, non-zero = compressed |
| 1 | 4 | `datalen` — bytes staged on the device |
| 5 | 4 | `oriLen` — length after decompression |
| 9 | 4 | **CRC-32/ISO-HDLC** over the assembled payload |
| 13 | 4 | `packageLen` — most the device will put in one reply |

Then:

```
offset = 0
while offset < datalen:
    send 0x0E with a 4-byte little-endian offset and nothing else
    reply 0xC8: first 4 bytes echo the requested offset, rest is the chunk
    if echo != offset: abandon the read        # the SDK's error -109
    offset += length of chunk
send 0x0F with no payload                      # on EVERY exit path, error included
verify CRC-32 over the assembled bytes
```

Points that are easy to get wrong, all CONFIRMED from the vendor's `plcommpro.dll`
(ZKAccess 3.5.5, `NewSDK/`; dispatch `0x10002be0`, pull loop `0x100029b0`):

- The request carries **only** the offset. No length, no token, no echo of the
  header. `packageLen` is never transmitted — the device decides chunk size and the
  client only says where to start.
- The offset is a **running byte count**, advanced by bytes actually received, not a
  chunk index.
- `0x0F` takes no argument and must be sent even when the read failed, or the
  panel's staging buffer leaks and starves the next read.
- Observed `packageLen` is **5,108** on both panels — the SDK's `PackageSize`
  default of 5,120 less 12.

**The CRC is the reason this can be trusted.** It makes the read self-verifying: a
loop that is wrong produces a checksum mismatch rather than plausible rows. That is
rule 10 enforced by the protocol itself, and it is why these results are recorded as
CONFIRMED rather than "looked right".

Both panels answer **flag = 0 (raw)**, so the compressor — which lives in a `plce.dll`
the vendor did not ship in `NewSDK/` — is not on the critical path. A non-zero flag
is unhandled and says so rather than returning partial data.

### The session dialect differs per model, and getting it wrong is SILENT

**MEASURED 2026-09-14**, one variable at a time (rule 16), on both panels:

| Connects sent | TF1700 `.60` | inBIO460 `.61` |
|---|---|---|
| none | `GET_PARAM` 56 B (truncated), catalogue **1180 B**, `GET_DATA` → `0xC8` 10 B | 81 B, 1085 B, `0x0D` staged |
| `0x76` only | identical to none (`0x76` answers `0xC8` with a session id) | `0xC9` −13, then identical to none |
| `0x76` then `0x01` | 80 B, **1176 B**, `0x0D` staged | identical to none |
| **`0x01` only** | 80 B, **1176 B**, `0x0D` staged | `0xC9` −98, then identical to none |

Look at the catalogue sizes: **1180 against 1176 — exactly four bytes.** That is the
session prefix. The TF1700 **defaults to session framing** and `CONNECT_SESSION_LESS`
(`0x01`) is what turns it off. Read it session-less without sending `0x01` and every
payload is shifted four bytes.

**And it does not error.** `GET_DATA` answers a well-formed `0xC8` carrying ten
useless bytes, which decodes to **zero records**. A panel holding 21,005 punches
reports as reachable, healthy and empty — an operator would read that as "nobody
clocked in today". This is precisely the failure rule 10 exists for, and it was
caught only because a control read of the same panel had already returned 21,005.

The inBIO460 is the opposite: it refuses `0x01` with −98 because it has no session
mode to leave, and all four sequences give identical results.

Two models in one family with opposite session requirements is exactly why they have
separate classes (`C3\Model\InBio460`, `C3\Model\Tf1700`) and not a shared file
with a flag (rule 15).

### The app driver — BUILT 2026-09-14

`App\Services\Devices\C3\C3Driver`, the third implementation of
`BiometricDeviceDriver`, registered in `DeviceDriverManager` under protocol `c3`.
Protocol lives in the library (`Promoseven\Biometric\C3`, version 2.3.0), per rule 8.

Read end to end through the app's own driver, against the real panels:

| | inBIO460 `.61` | TF1700 `.60` |
|---|---|---|
| ping | 304 ms | 412 ms |
| identity | `AK2J183860073`, 4 doors / 4 readers | `ESK5242000009`, 1 door / 1 reader |
| records | **87,756** in 12.8 s | **21,005** in 4.0 s |
| with a person | 86,512 | 5,795 |
| door-only events | 1,244 | 15,210 |
| timestamps decoded | 87,756 of 87,756 | 21,005 of 21,005 |
| range | 2000-10-04 → 2026-09-14 13:04 | 2025-08-10 17:28 → 2026-09-14 14:46 |

Door-only events are exit buttons, relays and restarts — real rows with no person
attached. They come back without a `user_id` rather than being dropped, so they can
be counted instead of vanishing (rule 11).

### What is still not settled

- **Writes: nothing, and deliberately so.** Enrollment, `SetDeviceData` and the door
  relay (`0x05`) are unbuilt. Every write capability is `not_implemented`, which the
  UI hides. We know from the vendor SDK exactly which commands would do it — the
  literal `update|user$` / `update|templatev10$` vocabulary, the field lists, even
  the teardown order — so this is `not_implemented` and not `unknown`. Rule 16 needs
  the owner's explicit go-ahead and a terminal out of service first.
- **No incremental read.** There is no cursor, so every sync transfers the whole
  table — 1.7 MB on the inBIO460. Watermarking and dedupe are the app's job. A
  server-side `Filter` clause exists in the SDK vocabulary but is unproven, and one
  the panel silently ignored would look exactly like one that worked.
- **No realtime.** The vendor polls `GetRTLogExt` on a one-second thread with a
  documented backoff; we have not built it. `ATTENDANCE_REALTIME` is
  `not_implemented`.
- **The roster has never been read.** `USER_READ` stays `implemented`, not
  `confirmed`: the table is in both catalogues and the same code path would read it,
  but it has not been run. "The same code should work" is not "it worked" (rule 10).
- **The compressed path** (`flag != 0`) has never been seen and is not implemented.
- **`GetDeviceDataCount`'s command number** could not be separated from payload bytes
  in the disassembly and was deliberately not guessed.
- **Nothing has been wired into scheduled sync.** No device row has been switched to
  protocol `c3` yet; that is an operational decision, and both panels hold years of
  history that would land in attendance on first sync.

## Verification status by model — what is actually tested (2026-09-14)

There are **two protocol families, not one per model.** Every F-/K-series terminal
speaks the same ZKTeco standalone binary protocol; they differ only at the edges
(e.g. the F22 needs windowed bulk reads). Every inBIO/C3 panel speaks C3/PullSDK.
So "is the model supported" is really "is its family supported, and has this
particular model been seen".

**A model number does not tell you the family.** The TF1700 looks like a standalone
fingerprint terminal, is sold as one, and is not one: it answers C3/PullSDK. That
cost this project a wrong entry in this table for weeks. The family is established
by reading the device, never by the name on the case.

Tested read-only on 2026-09-14 against the four devices this project physically has:

| Model | Family / protocol | Status | Evidence |
|---|---|---|---|
| **F18** | standalone binary | **tested** | info, users (18), attendance (10,510) read clean @ 192.168.7.62 |
| **F22** | standalone binary | **tested** | info, users (11), attendance (8,162) read clean @ 192.168.7.63 |
| F19, F16, K40 | standalone binary | **family, untested** | same protocol as F18 (pyzk tests F18/K40; the ZK spec was extracted from an F19). No such device here yet. |
| **TF1700** | **C3 / PullSDK** | **reads confirmed, driver built** | **Not standalone** — corrected 2026-09-14. Identity, schema and a full `transaction` pull (367,768 B / 72 chunks / CRC-32 verified / 21,005 rows) @ 192.168.7.60 via `tools/zkprobe/inbio460.php`. Accepts CONNECT, unlike the inBIO460. Writes unbuilt. |
| **inBIO460** | C3 / PullSDK | **reads confirmed, driver built** | Identity, schema and a full `transaction` pull (1,717,298 B / 337 chunks / CRC-32 verified / 87,756 rows) @ 192.168.7.61 via `tools/zkprobe/inbio460.php`. Session-less only. Writes unbuilt. |
| inBIO260/160, C3-400/200/100 | C3 / PullSDK | **untested** | same family as inBIO460, never connected here |

**What this means for operations, honestly:**

- **Standalone binary reads** (info, users, attendance) are confirmed on F18 **and**
  F22. F19/F16/K40 should work on the same driver but are unverified.
- **Standalone binary writes** (push user, delete fingerprint, clear card) are
  confirmed on the **F18 only** — see the confirmed table above. Not re-verified on
  the F22 in this pass (writes are not run as a casual probe; rule 16).
- **C3 / PullSDK (inBIO460 and TF1700):** reads are **confirmed end to end through
  the app's own driver** — identity, schema and the full `transaction` table,
  CRC-32 verified, on both panels. The driver, capability sets and the protocol
  option in the add-device form all exist.
  **What does NOT exist yet:** any device row actually set to protocol `c3`, and any
  scheduled sync. Switching a panel over is an operational decision, not a code one
  — both hold years of history that would land in attendance on the first run.
- **Every C3 write remains unbuilt and unprobed** — enrollment, `SetDeviceData`, and
  the door relay (`0x05`). Rule 16: those need the owner's explicit go-ahead and a
  terminal out of service, not a casual probe.

The model dropdown in the add-device form carries these same four states
(`tested` / `same protocol, untested` / `reads only` / `untested`) so an operator
sees the truth at the point of choosing, and untested models stay listed so the
choice exists the day the hardware arrives. The TF1700 has been **moved from the standalone group to the C3
panel group**, where it belongs; it was listed as standalone until 2026-09-14 and
that was the entire reason the unit looked dead.

### Where this knowledge came from (rule 17)

The C3 buffered read was recovered on 2026-09-14 from the owner's own licensed
ZKAccess 3.5.5 installation, supplied as a directory dump: the native SDK in
`NewSDK/` (`plcommpro.dll` for PullSDK, `zkemkeeper.dll` for standalone), the managed
assemblies with their `.pdb` symbols, and the live `Access.mdb`. Analysis notes are
in `draft/analysis/` on the owner's machine — **deliberately gitignored**, because
that directory contains real employee names, card numbers and fingerprint templates.
Rule 16: the decoded structure is committed here; the capture is not.

The vendor's own error and event dictionaries are not in the DLLs — they are in
`Languages/language_en-US.xml` (`SDKError_Pull`, `SDKError_StandAlone`,
`SDKEvent_Pull`, `SDKEvent_StandAlone`, `VerifyType`, `InOutStateInfo`). Two codes
that matter to us: **`-4991` = "Operation database has no corresponding data"**,
which is the F22's recorded `4991` reply and is therefore a *semantic* refusal about
a table's contents, not a transport failure; and **`-13`** = "command not
available", the inBIO460's refusal of CONNECT.

## Auto-detect on add (`POST /api/devices/identify`)

The add-device form's "Read from device" button reads a device's identity **before
it is saved** and selects the matching model. It runs `info()` over the chosen
protocol on a transient, unsaved `Device` (`DeviceHealthService::identify()` — no
persistence) and maps the reported name onto a dropdown model by longest prefix
("F18/ID" → F18). A device that does not answer that protocol — an inBIO/C3 panel on
the binary protocol — times out and returns a 502 telling the operator to pick the
model by hand. **Auto-detect therefore works for the binary family today and not for
C3**, for the same reason as everything else here: there is no C3 driver yet.
