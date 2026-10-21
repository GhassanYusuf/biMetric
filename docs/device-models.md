# Device models — how each one does each action

**Last updated: 2026-10-18**

Required by **CLAUDE.md rule 21** (owner, 2026-10-11): *"each device, a different
model, has its own method of achieving the same required action. Know all models and
how each operates; when fixing a problem for one, don't break the others with the same
solution. Build a methodology for each model."*

Every terminal holds the same four things about a person: a **user ID**, a **name**, a
**card number** and **fingerprints**. What differs per model is **how** each is read,
written, moved, removed and restored. This file is that methodology, one section per
model, and it must agree with the code (rule 14: code wins; fix the file).

## Where each model's method lives in code

| Family | Selected in | Per-model files |
|---|---|---|
| ZKTeco standalone (UDP/TCP binary) | `ZktecoDriver::capabilities()` → `Model\TerminalModelFactory::for($device->model)` (library 2.11.0) | `src/Model/F18.php`, `src/Model/F22.php`; any other model → `GenericTerminal` (reads only, every write `unknown`) |
| ZKTeco C3 / PullSDK panels | `C3Driver::capabilities()` → `C3Capabilities::forModel()` | `src/C3/Model/InBio460.php`, `src/C3/Model/Tf1700.php`; other panels → `GenericPanel` |
| CSL web firmware | `CslHttpDriver` | `app/Services/Devices/Csl/` |

The **model name stored on the device row decides the method.** A terminal with no
model recorded gets the cautious generic set. Identify it first — `device.info` is
read-only — and store the model.

Levels (rule 9): **C** confirmed on this model · **I** implemented, offered with a
warning · **U** unknown, hidden until run on this model · **N** not implemented / refused.

---

## Estate (2026-10-11)

| Terminal | Model | IP | Firmware | Platform | Family |
|---|---|---|---|---|---|
| IT ROOM | F18 (F18/ID) | 192.168.7.62 | Ver 6.60 Apr 26 2016 | ZMM210_TFT | standalone, UDP |
| Camelot | F18 (F18/ID) | 87.237.196.60 | Ver 6.60 Apr 26 2016 | ZMM210_TFT | standalone, UDP, **AUTH needed** |
| Good Wheels | F18/ID | 46.184.163.106 | **Ver 6.60 Oct 12 2021** | ZMM210_TFT | standalone, UDP |
| On The Road | F22/ID | 192.168.7.63 | Ver 6.60 Apr 27 2017 | ZLM60_TFT | standalone, UDP |
| Glass Door | inBIO460 | 192.168.7.61 | AC Ver 5.2.4 Apr 19 2016 | — | C3, TCP, session-less |
| Entrance | TF1700 | 192.168.7.60 | Ver 6.64.0013 Oct 16 2018 | — | C3, TCP, CONNECT accepted |

---

## F18 — IT ROOM, Camelot, Good Wheels

The reference model: `CapabilitySet::defaults()` is the F18. Calibrated on IT ROOM.

| Attribute | Action | Method | Level |
|---|---|---|---|
| User ID | read | user table, bulk read (`USERTEMP_RRQ` 9), `UserCodec` | C |
| User ID | add | `USER_WRQ` 72-byte record on a free uid, `group ≥ 1`, `REFRESH_DATA`; slot read and guarded first (`saveUser` + `slotGuard`) | C |
| User ID | **move** | **In place: REFUSED** (`ACK_ERROR`, DC-006) → `user.renumber` N. **The F18 method: re-add** — `DeviceUserSyncService::moveByReadding()`: refuse first if the new ID is taken or a credential can't be put back; save card + fingers (rule 19); delete; add under the new ID; write card + fingers back; read back. Steps proven on IT ROOM 2026-10-11 (Akram's remove + re-add, DC-012); the composed move refused safely for Ali, not yet run end to end (DC-014). The library's one-session alternative, `renumberByReupload()` (verified twice on IT ROOM 2026-09-24), is not enabled — see DC-014. | I (re-add) |
| User ID | delete | `DELETE_USER` (18) — takes card and fingers with it, so `removeEmployee()` saves them first | C |
| Name | write | `USER_WRQ` on the same uid | C |
| Privilege | keep | in the same record; `save()` keeps it, a re-created record gets it back (`privilegeToRestore`) | C |
| Privilege | **set** (admin / normal user) | `UserService::setPrivilege()` (2.14.0): same `USER_WRQ` record with 14 or 0, name / card / password kept, privilege read back. `user.privilege`. **C since 2026-10-20 (library 2.15.0):** `tools/zkprobe/user-privilege-write.php` — a test user made 14 then 0, each read back, name and password kept, every other user unchanged — twice on IT ROOM, Camelot and Good Wheels (`traces-2026-10-20/user-privilege-write-01/02.json`); and the owner's "Test" on IT ROOM 0 → 14 at 12:51 UTC | C |
| Privilege | set **from the form's Device role** (2026-10-20) | `EmployeeDevicePropagator::applyRole()` → the same `setPrivilege()`: Admin / Super admin → 14, User → 0, Enroller / Not set → untouched. Only when the save CHANGED the role (`apply_role`), only where `user.privilege` is **C** (`Device::canSetPrivilegeOnSave()`); elsewhere the result says it was held back. Real roles differ per terminal, so an unchanged role never levels them | follows `user.privilege` |
| Card | read | field in the user record (one card per user; `0xFFFFFFFF` = probably none) | C |
| Card | write / clear | `UserService::setCard` (record rewrite, 0 clears), read back | C |
| Card | enroll (tap) | card-scan capture | I |
| Fingerprint | read | template table (`TemplateCodec::decodeWithBlobs`), blobs byte-exact | C |
| Fingerprint | enroll | `STARTENROLL` at the terminal — trigger confirmed | C (trigger) |
| Fingerprint | write | `TemplateService::addTemplates()` — `SAVE_USERTEMPS` with the device's own record, add-only, read back. Write CONFIRMED on IT ROOM 2026-10-11; a real finger match not yet observed | I |
| Fingerprint | delete one | `DELETE_USERTEMP` (19), table read before/after | I |
| Password | read | password(8)@3 of the user record — equal to the system's copy on IT ROOM and Camelot, 2026-10-12 | C |
| Password | write / keep | same `USER_WRQ` record; `save()` keeps the device's when not given (2.13.0 — before, every write erased it). The app writes it on every push. **C since 2026-10-20 (library 2.15.0):** `tools/zkprobe/user-password-write.php` — a test user's password written and read back, kept through a name write, roster unchanged — twice on IT ROOM, Camelot and Good Wheels (`traces-2026-10-20/user-password-write-01..04.json`) | C |
| Punches | count | `GET_FREE_SIZES` (50), `FreeSizesCodec` — `records` equalled a full read on IT ROOM, Camelot and Good Wheels, 2026-10-18 | C |
| Punches | **clear** | `AttendanceClearService` → `CLEAR_ATTLOG` (15) in one session with the count before and after (`AttendanceService::clearAll($expected)`, 2.14.0); refused unless a full read matched the count and every record decoded and was stored. `attendance.clear`. Never run (2026-10-18) | I |

**Per-unit notes.** Camelot answers `CONNECT` with `ACK_UNAUTH` even with comm key 0;
it needs `CMD_AUTH` (DC-002), and its public link is flaky, so reads go through the
queue with retries. A user write there once returned the unnamed reply **4994**
(2026-09-24). Good Wheels runs **2021 firmware** — newer than the 2016 units every F18
level was measured on. Same model, so it gets the F18 set. **First writes on Good Wheels,
2026-10-11:** a user delete ("ali-it", 565656565), a user add (ALI MOHAMED, 960802797), a
card write (7511517) and a fingerprint write (fingers 6, 7) — every one read back; users
19 → 19, templates 32 → 34, none lost. Still treat a first write of any OTHER kind as a
first run.

## F22 — On The Road

`src/Model/F22.php`. Shares the protocol with the F18 and differs at the edges.

| Attribute | Action | Method | Level |
|---|---|---|---|
| User ID | read | user table by the **push** path — the users table **refuses windowed reads (reply 4991)** | C |
| User ID | add / name | `USER_WRQ`, as the F18 — Ali's name written and read back 2026-10-11 | C |
| User ID | delete | `DELETE_USER` — Tariq (PIN 940) removed and verified 2026-10-11 | C |
| User ID | **move** | In place: never tried (U). **By re-add**, as the F18 — run on On The Road 2026-10-11: ALI MOHAMED 654 → 960802797, card, fingers 6, 7 and admin read back (DC-014) | I (re-add) |
| Card | read | user record | C |
| Card | write | `setCard` — card 7511517 written to Ali and read back 2026-10-11 (one run) | I |
| Fingerprint | read | template table, blobs byte-identical to the F18's for the same enrollment (PIN 261 slot 3) | C |
| Fingerprint | write | `addTemplates` (`SAVE_USERTEMPS`) — 2026-10-11: test on a temporary user read back byte-identical, every other record unchanged; then Ali's fingers 6, 7 | I |
| Fingerprint | delete / enroll | never run on an F22 | U |
| Attendance | read | **windowed** (`PREPARE_BUFFER` 1503 / `READ_BUFFER` 1504); the push path loses the tail (DC-001) | C |
| Attendance | count | `GET_FREE_SIZES` — 8,430, equal to a full read, 2026-10-18 | C |
| Attendance | clear | never run on an F22; the F18's method is not inherited (2.14.0 sets it UNKNOWN) | U |
| Privilege | set | the F18's `setPrivilege()`, same record. **C since 2026-10-20:** test user 14 then 0, read back, name and password kept, others unchanged, twice on On The Road (`user-privilege-write-01/02.json`) | C |

**Password (2026-10-12):** read **C** (On The Road's one password equal to the system's copy). **Write / keep C since 2026-10-20:** a test user's password written and read back twice, kept through a name write, roster unchanged (`user-password-write-03/04.json`).

## inBIO460 — Glass Door

C3 / PullSDK access panel, **not** a standalone terminal (DC-003). `src/C3/Model/InBio460.php`.

| Attribute | Action | Method | Level |
|---|---|---|---|
| User ID | read | `GETDATA` `user` table — `0x0D` buffered pull; **session-less** (refuses both connect forms) | C |
| User ID | — | **a number**: the panel drops leading zeros (`041112750` → `41112750`); compare numerically (`C3Driver::samePin`, DC-009) | C |
| User ID | add / name / delete | `SET_DATA` / `DELETE_DATA` on `user` (`C3\Service\Personnel`); names are usually managed in ZKAccess | I |
| User ID | move | `Panel::moveUser` — `user` + `userauthorize` + `templatev10` copied under the new PIN, verified, old removed, undo on failure. Model baseline N; enabled **per panel** in `capabilities_cache` — Glass Door `confirmed` (Ghassan, Ali moved 2026-10-11). Same PIN → no-op (DC-010) | C (this unit) |
| Card | read / write | `CardNo` on the `user` row | U read / I write |
| Fingerprint | read | `templatev10` table, two-byte blob prefix | C |
| Fingerprint | write | `Personnel::addFingerprints` → `templatev10` rows (the panel cannot enrol; ZKAccess pushes templates from a reader) | I |
| Fingerprint | keep linked | every template row carries its owner's UID; `Personnel::update()` re-links them before and after an edit (DC-018) | C |
| Attendance | read | `transaction` table, CRC-32 verified | C |
| Clock | read / set | GET_PARAM "DateTime" / DATETIME (0x03) "DateTime=<packed>" — read 650 s slow, set and read back exact 2026-10-11 (DC-019) | C |
| Password | read | `Password` field of the `user` row — all 4 equal to the system's copies, 2026-10-12 | C |
| Password | write / keep | `Personnel::add/update` `Password`; an edit carries the row's own over. The app writes it on every push. **C since 2026-10-20:** test user's password written and read back twice, kept through a name write, 315 users before and after, on Glass Door and Entrance (`user-password-write-05/06.json`) | C |
| Privilege | set | the `user` row has a `Privilege` field; nothing writes it, and a panel has no menu to grant | N |
| Attendance | count / clear | `GET_DATA_COUNT` (0x0A) exists, never sent; clearing `transaction` is not implemented (2026-10-18) | N |

---

## Method: keeping terminal clocks right (owner, 2026-10-11 — casebook DC-019)

A terminal stamps every punch with its own clock, so a clock that is wrong makes every
punch wrong. **The time-zone setting in the app does NOT change a clock** — it only tells
the app how to read one.

**The method, every terminal, every model** (`App\Services\Devices\ScheduledJobRunner`,
command `sync_time`):

1. **Reference:** this server's clock — NTP-synchronised, UTC.
2. **Convert** to the terminal's own zone (`Timezone::forDevice`: its own setting, else
   the company's `system.timezone`). Terminals keep local time with no zone.
3. **Write** with that model's own command — contract `setClock()` (rule 21):

   | Model | Read | Write | Level |
   |---|---|---|---|
   | F18, F22 | GET_TIME | SET_TIME (202) | C (IT ROOM, On The Road, Good Wheels, Camelot, 2026-10-11) |
   | inBIO460, TF1700 | GET_PARAM "DateTime" | DATETIME (0x03) "DateTime=<packed>" | C (Glass Door, Entrance, 2026-10-11) |
   | CSL | — | — | not supported |

4. **Read back.** The command fails (red in the terminal's command list) unless the
   clock reads within 60 s of the real time — the acknowledgement proves nothing.

**When:**

- **On a schedule:** a cron job on Company → *Cron jobs* (2026-10-20; was the
  `devices.clock_sync_*` settings). This company: *Set clocks, then read the past day*
  at **05:00 and 10:00 company time**. **Right after each clock set, on the same
  terminal's queue, the past day's attendance is read** — yesterday and today in the
  terminal's own dates. The terminal runs one command at a time, so the read starts once
  its clock is right; a range read leaves the watermark alone and a punch already stored
  is never added twice. A *Set clocks* job without the read is also on the list.
  Checked every minute in each company's own timezone — **only runs once cron runs
  `schedule:run`**. See `docs/attendance-sync-flow.md`, "Scheduled reads".
- **On demand:** the terminal's page → **Set clock**.

**If a clock keeps going wrong:** measure it (the health check reports the clock; the
casebook DC-019 entry shows how), compare with the last `sync_time` result. Drift of
minutes within days is not the terminal — something else is setting it. On 2026-10-11
four LAN terminals were ~10¾ min slow by the same amount while the remote ones were
right: most likely the ZKAccess PC's clock. Fix that source, or the daily sync will be
correcting it every morning.

## TF1700 — Entrance

C3 / PullSDK, despite looking like a standalone terminal. `src/C3/Model/Tf1700.php`.
Same methods as the inBIO460, except that it **accepts CONNECT**, and its levels are its
own. `user.renumber` is set **per panel** to **I** here in `capabilities_cache` (it has
moved people the same way as Glass Door, but has not been promoted); fingerprint and
card writes are **I**. Password: read **C** (all 4 equal to the system's copies,
2026-10-12), write **I**, as on the inBIO460. Privilege set and attendance clear: **N**,
as on the inBIO460 (2026-10-18).

---

## Changing a method — the procedure

1. **Which model?** Read the device row; if the model is missing, run `device.info`
   (read-only) and store it.
2. **Read this section and the casebook** (rule 20) for that model.
3. **Change only that model's file**, or add a method selected by capability. Never edit
   `CapabilitySet::defaults()` (the F18) to make another model work, and never add an
   `if model ==` inside shared code (rule 15).
4. **Run it on that model**, on a test user or with the owner's go-ahead (rule 16), and
   read back. Then update the level, this table and the casebook entry.
5. **Check the others did not move:** the per-model capability printout for every
   terminal must show the same levels as before for models you did not touch
   (`tests/Unit/DeviceModelMethodologyTest.php` pins them).
