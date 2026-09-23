# What the vendor's own database told us

`ZKAccess 3.5.5`, extracted to `draft/extracted/`, examined 2026-09-15.

**`draft/` is gitignored and must stay that way.** It holds real names, card numbers
and fingerprint templates. Everything below is decoded *structure* and *counts* —
rule 16: commit the findings, never the payload.

---

## Why this was worth an afternoon

Two questions had been stuck:

1. **180 duplicate employee records** that name, card and fingerprint evidence could
   not settle.
2. **How ZKAccess pushes one enrolment to several terminals** — the thing the owner
   does routinely and this library cannot.

The vendor database answered both, and the first one completely.

---

## The install manages exactly four terminals

`Machines` — 4 rows:

| IP | Serial | Alias in ZKAccess | fingercount |
|---|---|---|---|
| `192.168.7.62` | AIOR204860846 | **IT ROOM** | 16 |
| `192.168.7.63` | BOCK192660478 | **OTR** | 16 |
| `192.168.7.61` | AK2J183860073 | **P7H ENTRANCE** | 238 |
| `192.168.7.60` | ESK5242000009 | **GLASS_ENTRANCE** | 238 |

Three things fall out of that table:

- **Good Wheels and Camelot are not here.** They are separate installs at separate
  sites, so a PIN shared with these four means nothing.
- **The name transposition is confirmed, not suspected.** ZKAccess calls `.61`
  "P7H ENTRANCE" and `.60` "GLASS_ENTRANCE"; this app calls `.61` "Glass Door" and
  `.60` "Entrance" — exactly backwards. `docs/PROJECT-STATE.md` recorded this as an
  inference; it is now evidence. Still the owner's call to rename.
- **`fingercount` 16 / 16 matches what we read off the wire exactly.** The C3 panels
  report **238** templates each — data that exists and that our C3 driver cannot
  reach.

## A PIN is the person — inside that set

`USERINFO`: **334 people, 334 distinct `Badgenumber`s.** ZKAccess keeps one employee
list and pushes the same badge number to every terminal it manages.

Cross-checked against our own roster reads:

| | |
|---|---|
| Distinct PINs across the four managed terminals | **320** |
| Found in `USERINFO` | **320** — all of them |
| Roster names matching `USERINFO` | **669** |
| Disagreements | **0** |

That is what makes `device_groups.shared_pin_namespace` legitimate. It is declared by
an operator, with this evidence recorded on the row, and it turns 177 previously
unresolvable duplicate groups into a statement of fact: *the same badge number on two
terminals this install manages is the same human, by construction.*

It stays **off by default**, and it must. Asserting it across an unmanaged terminal is
precisely how two people become one — see the four groups spanning Camelot that
correctly still fall through to weaker evidence.

### `SSN` is not a national ID

It is populated for all 334 rows and holds a **single digit, identical on every row** —
a flag, not an identifier. There are no national IDs in this database. That question
is still open and still only the owner can answer it.

---

## How a fingerprint moves between terminals

`BioTemplate` — 248 templates across 204 people:

```
UserID, BadgeNumber, BioType, Version, DataFormat, TemplateNO, TemplateData, ValidFlag
```

| Field | Value across all 248 rows |
|---|---|
| `BioType` | `1` (fingerprint) |
| `Version` | `10.0` — ZKFinger v10 |
| `DataFormat` | `0` |
| `ValidFlag` | `1` |
| `TemplateNO` | the finger slot: 0, 3, 4, 5, 6, 7, 9 |
| `TemplateData` | **base64**, decoding to 564–1,620 raw bytes |

**There is no machine column.** One template per person per finger, stored once, pushed
to every terminal. That is exactly the mechanism the owner described, and the table
layout is the proof of it.

The decoded blob carries `53 53 32 31` — ASCII **`SS21`** — at offset 2. Bytes 0-1 vary
per template; `SS21` is the constant signature.

### The blob in the database IS the blob on the device

Comparing 32 `badge:slot` pairs present in both the vendor database and a live read of
the terminals ZKAccess manages:

| | |
|---|---|
| Byte-identical | **26** |
| Identical except 6 trailing `00` bytes in the database | **6** |
| Any other difference | **0** |

The six extra bytes are zero padding. **Nothing else differs.** So the template
ZKAccess stores is exactly what the terminal holds, in the same encoding, with no
per-device transformation.

Against terminals the install does *not* manage (Good Wheels, Camelot), templates
differ — which is the right answer: a shared PIN there is a different person.

### What this settles, and what it does not

**Settled — the payload.** To write a template to a device we now know precisely what
bytes to send: the raw blob, no record header, exactly as `allWithTemplates()` returns
it. The earlier worry that the blob format was unknown is resolved.

**Not settled — the command.** The wire protocol for the *write* is still unproven.
`TemplateService::uploadTemplate()` therefore still throws, and
`template.upload` stays `not_implemented` (rule 9). Knowing the payload is not knowing
the envelope, and a template that appears to save and then fails to match a real finger
is worse than no feature at all.

**The next step**, and it is a small, well-bounded one (rule 17: the gap left after
research is where reverse-engineering starts): capture ZKAccess pushing one enrolment
to one terminal and read the command off the wire. `tcpdump` on the host while the
vendor tool does a "sync to device". Per rule 16 that is a **write** probe — the first
this project would run — so it needs the owner's explicit go-ahead, a terminal out of
service or a test user created for the purpose, and the current state read out and
saved first.

**Do not commit the capture.** It will contain real templates. Commit the command
number, the payload framing and a synthetic fixture.

---

## Tables present but empty or unused

`TEMPLATE` (the legacy OLE-blob table) holds **0 rows** — superseded by `BioTemplate`.
`FaceTemp`, `FingerVein`, `TEMPLATEEx`, `FaceTempEx`, `FingerVeinEx` exist in the
schema for other hardware in the product line.

Other tables worth knowing about if this is ever revisited: `CHECKINOUT` (attendance),
`personnel_issuecard` (159 cards), `DEPARTMENTS` (7), `acc_levelset*` (access rules),
`acc_timeseg` (time zones), `action_log` (operator audit).
