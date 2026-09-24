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

### Refused by the hardware

| Key | Evidence |
|---|---|
| `user.renumber` | **The F18 refuses it.** 2026-09-23, IT Room (F18, `192.168.7.62`), test user `ali-it2`, uid 6: rewriting the uid under a new user id (`UserService::renumber()`, `CMD_USER_WRQ`) answered **ACK_ERROR** for `000000005`, `5` and `10055`. Every time a read-back showed the record untouched — same uid, id `960802797`, card 2862247, finger slot 5. None of the target ids was held by anyone. So leading zeros are **not** the cause; this firmware will not change the user id of an existing uid. The same command with the SAME user id (a name change) succeeds. Declared `not_implemented` in `CapabilitySet::defaults()` so the UI hides it; the code stays for firmware that accepts it. Untested on the F22 and Camelot — `unknown` in fact, hidden by the shared default. The only other route, delete and re-create, needs `template.upload` and would otherwise destroy fingerprints. Trace: `tools/zkprobe/traces-2026-09-23/renumber-itroom-01.json`. **2026-09-24: that route is now written** — `UserService::renumberByReupload()` (library 2.8.0) deletes the record and re-uploads it with every template under the new id via `SAVE_USERTEMPS`, verifies, and restores the original on any failure. `ZktecoDriver::renumberUser()` now calls it. **Never run on hardware**, so `user.renumber` stays `not_implemented` in `defaults()` until the owner-authorised run on a test user (`tools/zkprobe/renumber-reupload.php`, WRITES TO THE DEVICE) succeeds on the F18. Then it is enabled for that terminal only; the F22 and Camelot stay hidden until each is run (rule 15). **Result, 2026-09-24, IT Room (F18, `192.168.7.62`), test user `ali-it` (uid 6):** 960802797 → 5 (5.95 s) and 5 → 960802797, two separate sessions, both verified — same uid, card 2862247 and privilege kept, the slot-5 template came back **byte-identical** (978 bytes, sha1 `ea11f86e…`), `valid` byte kept at 1 (so pyzk's `0x10 + slot` is right for this firmware), every other user and template unchanged. `SAVE_USERTEMPS` is therefore accepted by the F18. **Not yet checked: a real finger scan after a move.** `user.renumber` set to `implemented` for IT Room only, via its `capabilities_cache` (a re-probe may clear it, which only hides the feature again); `defaults()` unchanged. Traces: `tools/zkprobe/traces-2026-09-24/renumber-reupload-01.json`, `-02.json`. |

### Implemented but unproven (6)

| Key | Why it is unproven |
|---|---|
| `device.time.write` | Never fired. The reference device's clock is ~6.4 minutes fast, but writing a wrong clock would misstamp every future punch, so this waits for a deliberate decision. |
| `template.delete` | `CMD_DELETE_USERTEMP` payload is inferred, not documented. |
| `attendance.realtime` | `REG_EVENT` subscription implemented; a live punch has not been observed arriving this way. |
| `attendance.clear` | Destructive. Deliberately never called to verify it. |
| `device.restart` | Deliberately never called. |
| `user.disable` | Approximated by clearing the card — the protocol has no suspend flag. |

### `card.enroll` — IMPLEMENTED, behind a safety net (F18, IT Room `192.168.7.62`, 2026-09-23)

**Observed:** the first live card capture ever run. The operator tapped card
**2862247** (`0x002BACA7`) for ali-test2 (PIN 960802797). The capture reported
success and the user record now holds **4294967295** (`0xFFFFFFFF`). The same
record had held `2857525` at the 10:48 roster read and `0xFFFFFFFF` by 11:09, so an
earlier scan attempt very likely did the same thing.

**Cause, from the code, not the wire:** `EnrollmentService::captureCard()` registers
`EF_ALL`, accepts the **first** `REG_EVENT` packet with 4 or more bytes of data
whatever its event type, reads bytes 0-3 as the card number and calls `writeUser()`
with it, **with no read-back**. `DeviceClient::readRealtimeRaw()` drops the header
field that carries the event type, so the caller cannot tell a card event from any
other event. Some other event arrived first, and its first four bytes were
`FF FF FF FF`.

**Not yet known:** which event carries the card number on this firmware, and where
in the payload it sits. Answering that needs a READ-ONLY probe (rule 16) that
subscribes, logs every `REG_EVENT` header and payload while a KNOWN card is tapped,
and writes nothing. With a known number, `A7 AC 2B 00` is what to look for.

**Research, same day:** `adrobinoga/zk-protocol` (`sections/realtime.md`) documents
`EF_VERIFY` as the user serial number (4 bytes) plus `0x01`, **`ffffffff` when the
user is unidentified**. That accounts for the 4294967295s. It documents no event
carrying a card number. The ZKTeco SDK names `OnHIDNum` for that, with a mask the
available sources contradict (4096 vs 1<<11). Not settled.

**What was done:** scanning was briefly withdrawn, then restored the same day at
the owner's request, behind a safety net in
`EnrollmentOrchestratorService::captureCard()`:
- The card the user holds is read off the device before the scan.
- `isNotACard()` rejects 0, `0xFFFFFFFF`, and four ASCII digits (a user id
  fragment). The previous card is written back through `setUserCard()` and the scan
  is reported as failed (422). Nothing is recorded.
- A scan that passes is checked against the re-read mapping. If the terminal holds
  something else, the scan is a failure.
- The UI offers Scan card with a warning, because the level is `implemented`
  (rule 9).

The safety net cannot catch a wrong number that looks like a real card. Only
fixing the library can. Tests are in `CardAssignmentTest`.

**Library fix owed** (the product, rule 8): filter by event type, never write 0 or
`0xFFFFFFFF`, and read back before reporting success. The owner approved this on
2026-09-23. It is waiting on one run of `tools/zkprobe/f18-card-event.php` while
card 2862247 is tapped, to learn which push carries the number.

### `template.blob.read` — CONFIRMED 2026-09-15 (F18 and F22)

**The template bytes were always arriving. We were stepping over them.**

The table returned by `CMD_DB_RRQ` + `FCT_FINGERTMP` — the one
`template.table.read` already fetches on every enrollment sync — holds each blob
immediately after its record header:

```
[uint32 total length]
  [uint16 record length][uint16 uid][uint8 slot][uint8 valid][TEMPLATE BLOB]
  …
```

`TemplateCodec::decode()` read the 6-byte header and skipped the remainder.
`decodeWithBlobs()` keeps it. **No new command, no extra round trip, no new
capability on the wire.**

Measured on the F18 @ `192.168.7.62`: 22,066-byte table, 19 records, blobs of
706–1,602 bytes, 21,948 bytes of template data, the walk consuming exactly
22,062 of 22,062.

| Terminal | Model | Templates | Bytes |
|---|---|---|---|
| IT Room `192.168.7.62` | F18 | 16 | 19,122 |
| On The Road `192.168.7.63` | F22 | 16 | 18,386 |
| Good Wheels `46.184.163.106` | F18 family | 32 | 27,422 |
| Camelot `87.237.196.60` | F18 | 37 | 45,518 |
| Glass Door, Entrance | C3 panels | **not exposed** | — |

#### The finding that matters

**Templates copied between terminals are byte-identical, across models.**

| | |
|---|---|
| PIN 261 slot 3 | same 1,054 bytes on the **F18** and the **F22** |
| PIN 261 slot 6 | same 1,132 bytes on both |
| PIN 654 slots 6, 7 | identical on both |
| PIN 940 slot 6 | identical on both |

This is the vendor tool's doing: an operator enrols a finger once in ZKAccess and
pushes it to the other terminals. It means a SHA-1 of the blob is a usable identity
signal — the device's own record that two employee records are one human.

**Read it the right way round.** A digest MATCH proves one enrolment in two places.
A digest MISMATCH proves nothing at all: presenting the same finger twice yields a
different template, and there is no matcher in this library. Never conclude
"different people" from a mismatch.

**Store the digest, not the bytes.** The blob is biometric data; the SHA-1 answers
the only question being asked and cannot leak a fingerprint into a log line.
`employee_device_maps.template_digests` holds `["<slot>:<sha1>", …]`.

Read with `php artisan device:read-templates` (read-only). The probe and its trace
are `tools/zkprobe/f18-templates.php` and
`tools/zkprobe/traces-2026-09-15/f18-templates-*.json` — digests and sizes only,
never the bytes.

#### The C3 panels too — SOLVED 2026-09-15

The line that used to sit here said the C3 panels "cannot do this". They can. The
`templatev10` table had simply never been requested, and the panel had been
publishing its schema in every `DATATABLE_CFG` reply all along:

```
templatev10=10,Size=i1,UID=i2,Pin=i3,FingerID=i4,Valid=i5,Template=B6,Resverd=i7,EndTag=i8
```

**What made it look unreadable is one byte.** A C3 record prefixes each field with
its length, and `RecordCodec` read ONE byte — which tops out at 255, while these
templates run to 1,618. The first record decoded perfectly
(`Size=878 UID=1 Pin=10 FingerID=3 Valid=1`), then the walk consumed 110 bytes of an
878-byte template, lost alignment inside that record, and produced plausible-looking
nonsense for all 344 after it. Exactly the failure rule 10 exists to prevent.

**A blob field carries a TWO-byte length prefix.** With that, the walk consumes
281,680 of 281,680 bytes on both panels.

| | inBIO460 `.61` | TF1700 `.60` |
|---|---|---|
| Records | **238** | **238** |
| Payload consumed | 281,680 / 281,680 | 281,680 / 281,680 |
| Distinct people | 196 | 196 |
| Finger slots | 0, 3, 4, 5, 6, 7, 9 | same |
| Template size | 564–1,618 B | same |
| `SS21` signature at offset 2 | every record | every record |
| `Size` field == blob length | every record | every record |

238 is **exactly** the `fingercount` the vendor's own `Machines` table reports for
each panel, and 196 is exactly the number of those PINs with a template in its
`BioTemplate` table. Four independent cross-checks, all agreeing.

`TEMPLATE_TABLE_READ` and `TEMPLATE_BLOB_READ` are therefore `confirmed` **on the
inBIO460 and the TF1700 specifically**. The family default stays `implemented`: a
level confirmed on one model says nothing about another (rule 10), so a C3-400
nobody has read still claims nothing.

The fixture is **synthetic** — `tests/Fixtures/c3-templatev10-synthetic.bin`. A real
capture is 238 people's fingerprints and is not committed (rule 16); the framing is
what matters and it is reproduced exactly.

### Not implemented (2)

`template.download`, `template.upload` — and the distinction from
`template.blob.read` above matters:

- `template.download` is the **separate per-slot command** (`USERTEMP_RRQ` with a
  uid/slot payload). The device answers it — a per-slot probe returned 1,304 bytes
  for uid 8 slot 6 — but it is redundant now that the table read yields the same
  blob. (That 1,304 is the RECORD; the blob is 1,298. Reading the per-slot figure as
  a blob length is an off-by-six that would misalign a decoder.)
- `template.upload` is **writing a template back**. As of 2026-09-15 the **payload is
  no longer unknown** — the vendor's own `BioTemplate` table stores exactly the bytes
  the device holds (26 of 32 compared pairs byte-identical, 6 differing only by
  trailing zero padding, 0 otherwise). See `docs/zkaccess-database.md`. What remains
  unproven is the **write command itself**. A template that appears to save and then fails to match a real
  finger is worse than no feature. Enrolment stays device-side.
  **2026-09-24:** the write command is now transcribed from pyzk and implemented as
  `DeviceClient::saveUserWithTemplates()` (`SAVE_USERTEMPS` 110), used only by
  `renumberByReupload()` to put a person's OWN templates back under a new id — not
  as a general upload. Still EXPERIMENTAL: whether the F18 accepts it, stores the
  bytes identically, keeps the `valid` byte (pyzk sends `0x10 + slot` and drops it)
  and — the real test — still verifies the finger, is exactly what the first run
  must record.

Neither ever blocked reading.

### Unknown (5)

`access.verify_mode`, `access.schedules`, `access.anti_passback`,
`access.duress`, `access.relay`.

Never probed. These may exist on the hardware. We claim nothing.

## Carrying a national id on the terminal — NOT IMPLEMENTED, and where it could go

Asked on 2026-09-21: can the terminal itself hold the national id (CPR, 9 digits
here, e.g. `830108246`), so that a device read alone identifies the human without
going through `employee_code`?

Probed read-only against the F18 at 192.168.7.62 the same day
(`tools/zkprobe/f18-user-record-fields.php`, 18 user records, trace
`traces-2026-09-21/f18-user-record-fields-01.json`).

**The answer is that there is no spare field.** The 72-byte user record is fully
accounted for — uid, privilege, password, name, card, group, timezone flags, user
id — and every always-zero byte on the device is the unused tail of one of those,
not free space (per-byte occupancy is in `docs/protocol-overview.md`). Anything we
store has to go in a field that already means something:

| Field | Room for 9 digits? | What it costs |
|---|---|---|
| **user id** (PIN), 9–24 bytes @48 | Yes — exactly 9 | It is the attendance key. Every punch row carries it, so changing it on an enrolled user **orphans their history**, and the CPR then shows on the terminal display and in every vendor tool. |
| **name**, 24 bytes @11 | Yes, appended — names here reach 18 chars, so not always | The name is what the terminal shows on screen and what reconciliation matches on. Both break. |
| **card**, 4 bytes @35 | Numerically yes (`830108246` < 2^32) | It is a credential field: writing it makes the CPR **work as a card** at the door, and destroys real card enrollment. `0xFFFFFFFF` is already ambiguous here (sentinel vs real). Never do this. |
| **password**, 8 bytes @3 | No — 9 digits do not fit, and only 5 chars were observed in use | It is a verification password: anyone who knows the CPR could clock in as them. |
| **bytes 57..71** | 15 bytes, always zero | Almost certainly the tail of `user id`, not padding — see the unresolved question in `docs/protocol-overview.md`. Writing there is a guess against a live terminal. |

**So the only field that can honestly hold a CPR is the user id**, and only by
making the CPR *be* the user id — which is a data-model decision (the terminal's
key becomes the government id), not a protocol one. It is viable on a terminal
being enrolled from scratch and unsafe on one with attendance history.

**What is NOT settled:** ZKTeco's own SDK exposes extended user fields on some
firmware (`SetUserInfoEx`, and an extended-format flag read via device options).
Whether this F18 has an extended user table with somewhere neutral to put a CPR has
**never been probed** — that is `unknown`, not `not_implemented` (rule 9), and it is
the next thing to look at if this becomes a requirement. It needs a read of the
device option block first, not a write.

Meanwhile the app does not need this: the national id lives in `people`, and the
terminal's `employee_code` maps to it. The lookup endpoint
(`POST /api/employees/lookup`) resolves one to the other.

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

### Writing users to a C3 panel — `not_implemented`, and why (2026-09-15)

`user.write` and `user.delete` are **`not_implemented`** on every C3 panel, so the UI
hides them (rule 9) and a push to Entrance or Glass Door is skipped with *"this
protocol cannot write users"*. That is the truth about the driver, not a device
fault, and it is worth separating from the two things that ARE now known.

**The command numbers are settled.** Recovered from the vendor's `plcommpro.dll` on
2026-09-15 — no public C3 reimplementation carries a write command at all:

| Command | Code | Source |
|---|---|---|
| `SET_DATA` | `0x07` | DLL log string `"…!Cmd=CMD_SET_DATA, datalen=%d"` then `movl $0x7` at `0x1000b7c2` |
| `DELETE_DATA` | `0x09` | constant inside `Process_DeleteDeviceData` |
| `GET_DATA_COUNT` | `0x0A` | constant inside `Process_GetDeviceDataCount` |

The same extraction independently reproduces `0x03`/`0x04`/`0x06`, which are already
known from the references — that agreement is what makes the three above evidence
rather than a guess.

**The payload layout is NOT settled, and was not guessed.** A write to `user` that is
one byte out of phase overwrites somebody already enrolled, and a C3 template cannot
be restored from any backup. Entrance and Glass Door hold **320 roster entries each**
and control doors. Rule 16 is explicit that this is where you stop and record the gap.

`GET_DATA_COUNT` is the safe next step: it is read-only, it exercises the same
extraction, and a correct row count proves the command numbers came out of the DLL
intact before anything is written.

See `docs/protocol-overview.md` for the three ways to close the gap, best first.

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

### Every operation, verified against real hardware — 2026-09-14

Run through the app's own driver contract, read-only, nothing persisted
(`tools/verify-devices.php`):

| Device | Protocol | ping | info | readUsers | readAttendance |
|---|---|---|---|---|---|
| F18 `.62` | binary | 12 ms | ✓ | 18 | 10,529, 0 undecodable |
| F22 `.63` | binary | 15 ms | ✓ | 11 | 8,162, 0 undecodable |
| inBIO460 `.61` | **c3** | 310 ms | ✓ | **320** | **87,756**, 0 undecodable |
| TF1700 `.60` | **c3** | 443 ms | ✓ | **320** | **21,005**, 0 undecodable |

The two binary terminals are the CONTROL: a contract method (`readUsers`) was added
that day, and the F18 is the reference path (rule 15). It behaved identically, which
is what makes the C3 results a fact about C3 rather than about the change.

**Both panels report the same 320-user roster.** That matches the vendor software's
own `usercount` for both rows — they genuinely share a user set.

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
- ~~The roster has never been read.~~ **Done 2026-09-14.** `readUsers()` ran on both
  panels through the app's own driver: **320 users each, all named, 145 carrying a
  card**. `USER_READ` is now `confirmed` on the inBIO460 and the TF1700. Finger
  slots come back **null**, meaning "not read" — templates live in a separate
  `templatev10` table this library does not read, and null must never be rendered as
  "none enrolled" (rule 10). `TEMPLATE_TABLE_READ` stays `not_implemented`.
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

## C3 panels: writing users — research (2026-09-24), NOT implemented

Owner's request: a Personnel ID change must reach Glass Door (inBIO460, `192.168.7.61`)
and Entrance (TF1700, `192.168.7.60`) as it does through ZKAccess. Today the app can
only READ C3 panels (`user.write` = `not_implemented` in `C3Capabilities`).

**What research settled (rule 17):**

- Command numbers: `SET_DATA` = **0x07**, `DELETE_DATA` = **0x09** — extracted earlier
  from ZKTeco's own `plcommpro.dll` (`src/C3/Protocol/Command.php`), and independently
  confirmed by **xub/zkaccess-c3-py**, branch `main`, commit `a6b7b92c`
  (`zkaccess_c3/core.py` `set_user()` / `delete_user()`), which reports both
  validated against a live C3 (add 29→30, delete 31→30, read back via GETDATA). The
  upstream vwout/zkaccess-c3-py and -lua implement no writes.
- Payload per the fork: `[table, field count, field ids…]` then each value as
  `<1-byte length><little-endian value, minimal bytes>` (0 → single `0x00`);
  delete = `[table, 1, key field id]` + `<len><key>`.

**Where OUR panel disagrees with the reference — do not transcribe its field ids:**

The fork hard-codes the `user` table as 7 fields, ids 1..7 = CardNo, Pin, Password,
Group, StartTime, EndTime, SuperAuthorize. The TF1700's own DATATABLE_CFG
(`tests/Fixtures/c3-datatable-cfg-tf1700.txt`) says:

    user=1,UID=i1,CardNo=i2,Pin=i3,Password=s4,Group=i5,StartTime=i6,EndTime=i7,Name=s8,Privilege=i9,SuperAuthorize=i10

So on this panel the fork's add would write the card into UID and the PIN into
CardNo, and its "delete by PIN" (field 2) would delete by **CardNo** — removing
whoever holds a card with that number. A C3 write must therefore build its header
and field ids FROM THE PANEL'S DATATABLE_CFG, per table, never from constants.

**What research did NOT settle:**

- The `s` (string) field encoding for writes (Password, Name) — the fork only ever
  sent an empty password.
- Whether an UPDATE is an upsert keyed on Pin, on UID or on CardNo.
- A PIN change on a C3 also touches `userauthorize` (door x time-zone rows keyed by
  Pin — without them the person opens no door) and `templatev10` (fingerprints keyed
  by Pin). ZKAccess rewrites all three; the order and the atomicity are unknown.
- **First live run, 2026-09-24, Entrance (TF1700, `192.168.7.60`), owner-approved:**
  `tools/zkprobe/c3-user-write.php`, trace `tools/zkprobe/traces-2026-09-24/c3-user-write-01.json`.
  SET_DATA with ids from the panel's catalogue (CardNo 2, Pin 3, Password 4, Group 5,
  StartTime 6, EndTime 7, Name 8, Privilege 9, SuperAuthorize 10; no UID) was
  **accepted session-less**; read back, the row was stored exactly as sent and the
  panel **assigned UID 287** itself; 320 → 321 rows; every other row's digest
  unchanged. DELETE_DATA keyed on **Pin (id 3)** removed exactly that row; 321 → 320,
  other rows unchanged. So on the TF1700 a user row can be added and removed.
  **CONFIRMED on the TF1700 only** — the inBIO460 is `unknown` until run there (rule 15).
  Library: `RecordCodec::encode()` / `encodeDeleteKey()`, `Panel::writeRows()` /
  `deleteRows()` (2.9.0). `user.write` stays `not_implemented` in `C3Capabilities`
  until the ID-change sequence (user + `userauthorize` + `templatev10`) is proven.
- **Move run, 2026-09-24, same panel, owner-approved:** `tools/zkprobe/c3-user-move.php`,
  trace `traces-2026-09-24/c3-user-move-01.json`. `Panel::moveUser(99999991 → 99999992)`
  on test user `zk-test` with one `userauthorize` row (time zone 1, door 1 — the pair
  all 320 people on this panel hold): user row and door row written under the new Pin,
  verified, old Pin removed from both; every other row of both tables unchanged;
  cleanup left both tables exactly as before. **Finding: the panel assigns a NEW UID
  on the re-written user (287 → 296)** — `templatev10` rows carry both Pin and UID, so
  moving fingerprints must write the NEW UID.
- **Full move, 2026-09-24, owner-approved, both panels:** `moveUser()` now also moves
  `templatev10` rows (2-byte blob length, as on reads), re-written under the new Pin
  AND the new UID, compared byte for byte per finger before the old Pin is removed.
  Test user `zk-test` with one door row and one PLACEHOLDER template (random bytes
  behind `SS21`, matches no finger): Entrance/TF1700 trace `c3-user-move-02.json`,
  Glass Door/inBIO460 trace `c3-user-move-03.json` — all three tables moved, template
  identical, UID 287 → 296 on both, every other row unchanged, cleanup exact. The
  inBIO460's `user` table has **no Privilege field**; the catalogue-driven encoder
  simply did not send it. `user.renumber` set to `implemented` on both panels via
  `capabilities_cache` (`C3Capabilities` baseline unchanged). `user.write` stays
  `not_implemented`: names and cards on C3 panels are still ZKAccess's.
  **First real move, 2026-09-24:** Ghassan 656 → 830108246 on both panels from the
  Push dialog; the owner confirmed it works at **Glass Door** → `confirmed` on the
  inBIO460. The TF1700 (Entrance) stays `implemented` until tried at that door.

