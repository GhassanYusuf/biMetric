# Device casebook

**Last updated: 2026-10-26**

The history of every problem met between this software and the terminals — what was
seen, what was done about it, what the cause turned out to be, how it was fixed and
how the fix was proven. Required by **CLAUDE.md rule 20** (owner, 2026-10-11): *"log it
all, what have you done, what was the problem and how you got it fixed — a memo for
yourself, so next time we try to find a problem or fix it you have a history to read."*

**Read this before investigating any device problem.** Search it for the device name,
the model, the IP, the command number, the error text. Most problems here have a
cousin already in this file, and the dead ends are recorded so they are not walked
twice.

How it differs from the other records:

| File | Answers |
|---|---|
| `docs/PROJECT-STATE.md` | Where are we now? What is open, who owes what? |
| `docs/device-capabilities.md` | What can each model do — the settled facts? |
| **this file** | **What went wrong before, how was it found, what fixed it?** |

---

## How to write an entry

- **Open it when the investigation starts**, not when it ends. Add to it as you go.
  An investigation abandoned halfway is still an entry (`Status: OPEN`) — the next
  person starts where it stopped.
- **One entry per problem.** A problem that turns out to be two gets two entries,
  cross-referenced.
- **Newest first.** IDs are sequential and never reused: `DC-NNN`.
- **Absolute dates and UTC times.** Never "today" or "last week".
- **Name the hardware exactly:** name, model, IP — e.g. `IT ROOM (F18, 192.168.7.62)`.
- **Direction:** `software → device` (a write, a command), `device → software` (a
  read, a decode, a stored value), or both.
- **"What was done" includes every action taken on real hardware or on production
  data** — reads, writes, manual database corrections, re-attributions — with what
  was saved first and how to undo it. Hand corrections are the first thing the next
  person needs to know about and the last thing anyone remembers.
- **Write down what did not work** (rule 16): the wrong first diagnosis, the setting
  that changed nothing. One line saves a day.
- **Evidence** is something a reader can open: a trace file, a command id, a log line,
  a test name, a commit.
- **Status** is one of `FIXED`, `WORKAROUND`, `MONITORING`, `OPEN`. A fix is `FIXED`
  only when it was verified against the terminal, or the entry says why it could not be.
- **Keywords** make it findable: error text verbatim, reply codes, command names.

Template:

```
### DC-NNN — one-line title

- **Date:** YYYY-MM-DD (to YYYY-MM-DD)
- **Devices:** NAME (MODEL, IP), …
- **Direction:** software → device | device → software | both
- **Symptom:** what the operator saw, in their words where possible
- **What was done:** the investigation and every action, in order, dead ends included
- **Root cause:**
- **Fix:** files, classes, tests
- **Verified:** how it was proven, on what hardware
- **Evidence:** traces, command ids, log lines, tests
- **Status:** FIXED | WORKAROUND | MONITORING | OPEN — and who owes what if open
- **Keywords:**
```

---

## Index

| ID | Date | Devices | Title | Status |
|---|---|---|---|---|
| DC-033 | 2026-10-26 | IT ROOM | Scan card for Test User on IT ROOM returns no card number ("9811" or 0xFFFFFFFF) | FIXED |
| DC-032 | 2026-10-25 to 2026-10-26 | Catering | Catering missing from an employee's Access levels; it is a UA300, a model with no write proven | FIXED |
| DC-031 | 2026-10-25 | Catering | Catering's workers show 0 punches on Employees while the terminal and the system both hold 2,112 | FIXED |
| DC-030 | 2026-10-22 | Camelot | A person linked to a terminal but no longer on it could not be pushed, moved or removed — every path said "not found" | OPEN |
| DC-029 | 2026-10-22 | Camelot | A delete-by-code half-ran on Camelot: fingers gone, records kept, and the record now drops new fingers | FIXED |
| DC-028 | 2026-10-22 | Camelot | Camelot wiped outside the system; its June–October 2026 attendance exists only as undecodable raw bytes | FIXED |
| DC-027 | 2026-10-21 | IT-Office | A terminal with no fingerprints at all could never be given one: an empty template table read as "unknown" | OPEN |
| DC-026 | 2026-10-21 | Good Wheels | A CPR typed onto the wrong employee, corrected, could never be given to the right one on that terminal | FIXED |
| DC-025 | 2026-10-21 | IT ROOM, Entrance, On The Road | Employee deleted by Personnel ID over the API is still on three terminals and can still punch | FIXED |
| DC-024 | 2026-10-18 to 2026-10-20 | Good Wheels, On The Road, Camelot, IT ROOM | The 5-minute health check never ran: it threw before queueing, then skipped every terminal | FIXED |
| DC-023 | 2026-10-18 | all six | Punches were never read automatically: nothing ran the scheduler | MONITORING |
| DC-022 | 2026-10-12 to 2026-10-20 | all six | Keypad passwords: never pushed, never read — and erased by every standalone write | FIXED |
| DC-021 | 2026-10-12 | Entrance, Glass Door, IT ROOM | Every Save took 20–40 s; two operators could talk to one terminal at once | FIXED |
| DC-020 | 2026-10-11 to 2026-10-22 | Camelot | 1,497 Camelot punches stored with dates from 2026-12-07 to 2030-01-30 — misaligned reads, not a clock | FIXED |
| DC-019 | 2026-10-11 | IT ROOM, On The Road, Glass Door, Entrance | Clocks ~10¾ min slow although time zones were set | MONITORING |
| DC-018 | 2026-10-11 | Glass Door, Entrance, IT ROOM | "Abdul" (21) is Abdul Haq; a panel edit cut his finger off his record | FIXED |
| DC-017 | 2026-10-11 | all | "Wipe a terminal and push the stored data" — Rebuild from system | MONITORING |
| DC-016 | 2026-10-11 | Glass Door, Entrance, IT ROOM | Yusuf: card shows 960403094, finger shows 695 | FIXED |
| DC-015 | 2026-10-11 | On The Road, Good Wheels | Every ZKTeco model inherited the F18's methods | FIXED |
| DC-014 | 2026-10-11 | IT ROOM, On The Road, Good Wheels | Save leaves the old user ID on some terminals (Ali, 654 vs 960802797; "ali-it" duplicates) | FIXED |
| DC-013 | 2026-10-11 | all | Saving Ali Mohamed Jaafar "got an error" — permission-cache 500 | FIXED |
| DC-012 | 2026-10-11 | IT ROOM | Akram removed and re-added: 0 fingerprints, no card, new user ID | FIXED |
| DC-011 | 2026-10-11 | Entrance, Glass Door | Renumber moved the panels, then our bookkeeping crashed | FIXED |
| DC-010 | 2026-10-11 | Entrance, Glass Door | "Moving a user needs two different PINs" | FIXED |
| DC-009 | 2026-10-11 | Entrance, Glass Door | "041112750 was written but is not on the panel" | FIXED |
| DC-008 | 2026-10-11 | On The Road, IT ROOM, all | "Tariq could not be removed" — 500 on an unadopted terminal | FIXED |
| DC-007 | 2026-10-11 | all | Attendance "Method" column showed bare numbers | FIXED |
| DC-006 | 2026-09-23 to 2026-09-24 | IT ROOM | The F18 refuses to change a user ID | WORKAROUND |
| DC-005 | 2026-09-23 | IT ROOM | Push refused the slot's own owner | FIXED |
| DC-004 | 2026-09-21 to 2026-09-23 | all | Every attendance sync failing on SQL Server | FIXED |
| DC-003 | 2026-09-14 | Glass Door | The inBIO460 "pings but never answers" | FIXED |
| DC-002 | 2026-09-13 to 2026-09-14 | Camelot | Camelot refuses to connect; bulk reads die part-way | WORKAROUND |
| DC-001 | 2026-09-13 | On The Road | "Incomplete transfer" on every attendance read | FIXED |

---

## Entries

### DC-033 — Scan card for Test User on IT ROOM returns no card number ("9811" or 0xFFFFFFFF)

- **Date:** 2026-10-26
- **Devices:** IT ROOM (F18/ID, 192.168.7.62, Ver 6.60 Apr 26 2016, ZMM210_TFT)
- **Direction:** device → software
- **Symptom:** Owner: scanning a card for the test user on IT ROOM "is not reading the card".
- **What was done:** read-only so far.
  - enrollment_sessions 51 (09:45 UTC) and 52 (09:46): both failed safely, nothing
    saved, Test User (#1827, 981122445) keeps card 2857525. 51 returned `0x31313839`,
    ASCII "9811", the first four characters of his own user ID; 52 returned `0xFFFFFFFF`.
  - The same problem as `card.enroll` in `docs/device-capabilities.md` (2026-09-23): the
    capture takes the first realtime push of 4+ bytes as the card. Those are verify
    pushes: the terminal reporting WHO it identified (his PIN text, so 51 was very likely
    his own registered card) or ffffffff for "unidentified" (52, likely an unregistered
    card). Neither carries the card number. The safety net (`isNotACard()`) refused both.
  - Owner: typing the number works; Scan card does not. They cleared Test User's card on
    IT ROOM at 09:46:14 UTC on purpose to reproduce (access_cards "Cleared from the
    terminal"). So scan 51 (09:45) saw his card REGISTERED ("9811" = his PIN text in the
    push); 52 and later saw it unregistered.
  - Read-only probe `tools/zkprobe/f18-card-event.php`, first run, 2026-10-26 ~09:53 UTC,
    holding IT ROOM's queue lock, owner tapping card 2857525 three times
    (`traces-2026-10-26/f18-card-event-01.json`, reconstructed from the console: the run
    could not write it because a branch switch had recreated the folder root-owned).
    Subscribed `REG_EVENT EF_ALL` = 0xFFFF, which includes every bit an SDK source gives
    for OnHIDNum (1<<10, 1<<11, 4096). Three pushes, all `cmd 500`, event `0x0080`
    (EF_VERIFY), payload `ffffffff01` ("unidentified"). **No push carried the card number.**
  - Library `EnrollmentService::captureCard()` (read): REG_EVENT EF_ALL, START_ENROLL with
    slot 11 (a guess, "card is commonly 11") + START_VERIFY, then the FIRST push of 4+ bytes
    is taken as the card and written with `writeUser()`, no read-back. On the F18 that first
    push is always EF_VERIFY, so the method cannot work there. The app's safety net catches
    it and restores the previous card.
  - Research (rule 17): adrobinoga/zk-protocol lists OnHIDNum as "Todo"; the SDK manual's
    real-time section could not be read. Not settled whether any F18 setting enables it.
  - Write test with the owner's go-ahead, `tools/zkprobe/f18-card-enroll-mode.php` (Test
    User only, queue lock held; captureCard()'s sequence minus its writeUser(); record read
    before, after and after 5 s), owner tapping 2857525, traces
    `traces-2026-10-26/f18-card-enroll-mode-01..03.json`:
    - 01, 02 (with START_VERIFY, as captureCard() sends it): START_ENROLL slot 11 ACK_OK.
      Screen: **"Verify: Badge, Badge number 2857525, Failed to verify, User ID:"**. One push,
      EF_VERIFY `ffffffff01`. Record unchanged, no card. (01 was interrupted by the owner's
      phone; 02 is the clean run.)
    - 03 (`--no-verify`, one variable changed): START_ENROLL slot 11 ACK_OK, but **no enrol
      screen at all**; the tap gave the same push; record unchanged.
  - So the terminal READS the card (it displays the number) but neither pushes the number
    nor stores it in any mode we can start remotely. The first push in 01/02 at 29.3 s both
    times looked like a timeout; 03's at 61 s says it was the tap.
- **Root cause:** on this F18 firmware (Ver 6.60 Apr 26 2016, ZMM210_TFT) no realtime push
  carries a tapped card's number (EF_ALL), and START_ENROLL slot 11 starts no card enrolment
  (ACKed, nothing on screen, nothing stored). `captureCard()` assumed both. Card scanning
  from the system is not possible on it; typing the number is (card.write CONFIRMED).
- **Fix:** owner's choice, 2026-10-26: hide Scan card on the F18 rather than build an
  "enrol at the terminal, the system reads it back" flow. Library 2.19.0 sets `card.enroll`
  NOT_IMPLEMENTED in the F18 set (`CapabilitySet::defaults()`; F18 only, as the finding is
  the F18's; F22, UA300, unknown models and C3 panels already had it off). The enrol dialog
  hides Scan and says "Type the number printed on the card."; the server refuses a scan
  (`DeviceEnrollmentController`, `EnrollmentOrchestratorService`). Test:
  `TerminalModelTest::test_the_f18_does_not_offer_card_scanning`.
- **Verified:** after installing 2.19.0, IT ROOM, Camelot and Good Wheels report
  `card.enroll` not usable (see the commit).
- **Evidence:** enrollment_sessions 51, 52; access_cards for employee 1827; traces-2026-10-26/f18-card-event-01.json, f18-card-enroll-mode-01..03.json.
- **Status:** FIXED — Scan card hidden on the F18. Test User has no card on IT ROOM (cleared by
  the owner at 09:46 to reproduce); typing 2857525 restores it.
- **Keywords:** scan card, card enroll, not reading the card, 0xFFFFFFFF, 4294967295, 9811, 0x31313839, realtime event, EF_VERIFY, OnHIDNum, IT ROOM, F18

### DC-032 — Catering missing from an employee's Access levels; it is a UA300, a model with no write proven

- **Date:** 2026-10-25 to 2026-10-26
- **Devices:** Catering (UA300/ID, 192.168.7.120, Ver 6.60 May 14 2018, ZMM200_TFT)
- **Direction:** software → device
- **Symptom:** Owner: editing an employee, the Access levels tab lists no Catering under
  terminals.
- **What was done:** read-only investigation, then identification; no write to the terminal.
  - The list is `writableTargets()` in `DeviceTargets.jsx`: active terminals whose
    `user.write` is supported. Catering's row had no model, so it ran on `GenericTerminal`
    (every write UNKNOWN), and was hidden by design (rule 9, rule 21.2).
  - 2026-10-25 read-only `device.info`: UA300/ID, serial CEG9194260020, MAC
    00:17:61:11:bd:84. Clock 95 s slow; no clock set had ever run on it.
  - 2026-10-25 13:19 UTC the owner set the row's model to "F18" in the browser (audit_logs
    18570). Catering then appeared in Access levels with every F18 write. **Correction
    (2026-10-26 08:3x UTC): writes DID run in that window.** A first check looked only at
    queued `device_commands` and missed them; the employee form writes in the request.
    audit_logs show the owner merging the imported Catering employees into their CPR
    records, 2026-10-25 13:14 to 14:13 and 2026-10-26 06:55 to 07:08 UTC. Each move ran the
    F18 method on the UA300: re-add under the CPR ID, fingers written (`slots_written`),
    old ID deleted, each read back in the app. Then 07:26 to 07:32 the owner resigned
    Arich, Jahid, Jairus, Sunitha and Riza; the first four were removed from Catering
    (maps closed "Removed from device"). The nightly clock job ran: 02:00 UTC ACKed, but the
    read-back showed the old time and the command failed (8302); 07:00 already right (8623).
    So the UA300 applies the clock later than the read-back, unlike the F18 (dead end: the
    F18's method is NOT the UA300's).
  - 2026-10-26, owner's go-ahead for steps 1-3: research (nothing UA300- or
    ZMM200-specific in pyzk, pyzatt, php-zkteco); the row set to `UA300/ID` with
    firmware, serial and platform at 07:45 UTC (was "F18"); library 2.17.0 adds
    `Model\UA300` (reads confirmed, no write); the app pins it in
    `DeviceModelMethodologyTest`.
  - 2026-10-26 08:19 UTC, the write test with the owner's go-ahead
    (`tools/zkprobe/ua300-write.php`, trace `traces-2026-10-26/ua300-write-01.json`,
    reconstructed from the console because the run could not write it; the folder is
    now created for www-data). The queue's per-device lock was held for the run. Roster
    saved first to `storage/app/private/removed-records/catering-roster-before-ua300-write-20261026-081950.json.gz`.
    Test User (#1827) as 981122445: add ✓, rename ✓, card ✓, fingerprint slot 0 written
    and read back byte-identical (1,444 bytes) ✓, delete ✓ (user and finger gone), every
    other user, card and finger unchanged ✓ (digest e501a7ca… before and after). Clock:
    refused by the library's own guard (UNKNOWN); not re-run, because the clock was already
    right and making it wrong would mis-stamp punches. Use `--clock-only` once it drifts
    30 s or more.
  - The "before" read held **16** users, not the 21 of 02:30 UTC: the owner's moves and
    removals above, not the test.
  - **Riza Teodosio (870876872). First read WRONG, corrected 08:58 UTC:** moved at 07:02
    with finger 6 written and read back; resigned at 07:30; her removal at 07:31 logged a
    timeout on CONNECT (laravel.log WARNING, command 1000) and her link stayed open. I
    then reported her "on Catering with no finger and no card". That came from her open
    LINK in the app, not the terminal: the 08:19 roster had no 870876872. The owner's
    retry at 08:58 got a 502, "User 870876872 is not on the device"; a fresh read
    confirmed she is not there (nor as 4). So the 07:31 delete reached the terminal, and
    only the step after it failed. Nothing was lost; her finger and card are in the
    system. Dead end worth keeping: an open map is not evidence of a record on the
    terminal (rule 10).
  - Fingers on the terminal vs the system for the 16: every stored finger present except
    Riza's. Cards: 10 match; Ragesh holds a different card per terminal (expected); Riza
    none. Only one punch under a new ID since the moves (Shahad, by card), so no finger
    written by the system has yet been seen to match at the terminal.
  - The 502: `removeEmployee()` sent a delete for a user the terminal no longer held, and
    `UserService::delete()` threw. **Fixed:** when the save-first read and a second read
    agree the record is absent, it sends no delete, closes the link ("Not on the device
    when removed") and says so. Test:
    `FingerprintPushTest::test_removing_someone_already_off_the_terminal_closes_the_link`.
    750 tests pass.
- **Root cause:** a new model with no file of its own. The app correctly offers no write
  until one has run on that model; the first attempt to work around it (labelling it F18)
  would have offered unproven writes.
- **Fix:** library 2.17.0 (`Model\UA300`, reads) and 2.18.0 (from the write test:
  `user.write` C; `user.delete`, `card.write`, `template.upload` I; the rest U). The
  assistant's commit/tag/push of 2.18.0 was refused by its permission classifier; the
  owner ran it, and it was installed the same day. **2.18.1** (owner committed and tagged it;
  installed the same day) corrects 2.18.0's Riza reasoning and sets `user.delete` C; pin
  `c c c u c i u c i u i u u u`. Pin `standalone:UA300` updated in
  `DeviceModelMethodologyTest`; `docs/device-models.md` UA300 table. The owner reports the
  two days of real use under the F18 label (moves, merges, resignations) "all working
  fine", which the audit and the terminal read agree with except for Riza.
- **Verified:** reads only: Catering resolves to `UA300` in production; `user.write` is
  unsupported, so Access levels correctly omits it; the clock is right (drift 1 s at 07:44 UTC).
- **Evidence:** audit_logs 18570 (model → F18); device_commands 8302, 8623, 3918, 5391,
  6864, 8340; library v2.17.0.
- **Status:** FIXED — Catering is in Access levels on its own UA300 profile (2.18.1), and the owner's
  save of Riza at 09:09 UTC closed her link ("Not on the device when removed"), her finger kept.
  Follow-ups, not this problem: card and fingerprint writes to C once a system-written finger
  matches at the terminal; the clock write with `--clock-only` after 30 s of drift.
- **Keywords:** Catering, UA300, ZMM200, access levels, terminal missing, writableTargets, user.write, generic terminal, model F18 mislabel, clock applied late

### DC-031 — Catering's workers show 0 punches on Employees while the terminal and the system both hold 2,112

- **Date:** 2026-10-25
- **Devices:** Catering (model not recorded on the device row, 192.168.7.120)
- **Direction:** device → software
- **Symptom:** Owner: the Catering workers imported from the terminal "have 0 punches near
  their name" on Employees, while Devices shows Catering with 2,112 device and 2,112 system
  punches.
- **What was done:** read-only so far.
  - 2,111 of Catering's 2,112 `attendance_logs` rows have `employee_id` NULL. Only one is
    attributed: Shahad (user 119), punched 08:00 and imported 09:22 on 2026-10-25.
  - Timeline: the device row was created and its punches read on 2026-10-22 07:51, BEFORE
    any link existed. The 21 `employee_device_maps` rows (users imported from the
    terminal, all open-ended: `valid_from`/`valid_to` NULL) were created 2026-10-25 08:54.
    A punch is attributed at import time, so everything read before 08:54 stayed
    unattributed.
  - Nothing re-links history after a user import. `attendance:reattribute` exists for
    exactly this, but no code calls it; it is only suggested in console output
    (`StaffImport`, `EmployeesMerge`, `ResetDeviceIdentity`).
  - `attendance:reattribute 17 --dry-run`: **673 newly attributed, 0 cleared, 1,438 still
    unmatched.** By person: Essam 602 → 174, youssef 732 → 155, Sunitha 19 → 124 (some
    dated 2000-02, the terminal clock was wrong then), Akhil 701 → 118, Shahad 119 → 57,
    Jimmely 15 → 19, Joanne 472 → 19, Ragesh 261 → 3, Mintesnot 215 → 2, Jahid 109 → 1,
    Arich 111 → 1. The 10 others have no punches on the terminal.
  - The 1,438 unmatched are under 22 user IDs nobody is linked to, people no longer on the
    terminal: 702 (481, until 2025-10-08), 59 (128), 205 (124), 123 (119), 25 (117),
    719 (101), 112 (93), 3 (85), 28 (65), 61 (46) and smaller. They stay unattributed until
    the owner says who they were (rule 10).
  - Owner said yes. 12:44 UTC: saved every Catering punch's attribution to
    `storage/app/private/removed-records/catering-attribution-before-reattribute-20261025-124431.json.gz`
    (undo = set `employee_id` back from it), then `attendance:reattribute 17`: 673 newly
    attributed, 0 cleared, 1,438 unmatched, exactly as the dry run said.
  - Dead end found while writing the test: when a PIN had a closed earlier window and is
    then claimed open-ended, BOTH windows cover the earlier punches, and all three
    attribution paths (bulk sync, `attendance:reattribute`, realtime `inForceAt`) took
    whichever row the database returned first. The new test failed by giving a leaver's
    2024 punch to the new holder.
- **Root cause:** a punch's employee is decided once, at import, from the links in force
  then. Catering's punches were read before its users were imported and linked, and the
  import did not re-link stored punches. Secondary: no tie-break between overlapping
  windows.
- **Fix:** `App\Services\Attendance\PunchAttribution` holds the one definition (windows,
  precedence, relink). `DeviceAdoptionService::claim()` re-links that PIN's stored punches,
  so "Import from device" and a single claim both bring the history, and both messages say
  how many punches became the person's. `attendance:reattribute` and the bulk sync
  (`AttendanceSyncService`) now use the same class. Precedence: a closed window wins over
  an open-ended one, then the later start; `EmployeeDeviceMap::scopeByPrecedence()` gives
  the realtime query the same order. The re-link also moves `updated_at`, so open
  Attendance screens refresh (rule 18). Tests (`DeviceIdentityGuardTest`, 3): a claim
  links only that PIN on that device, a leaver's punches stay theirs, and the import links
  and reports. 748 pass. Docs: `attendance-sync-flow.md`.
- **Verified:** on production data, Catering's workers now carry their punches (Essam 174,
  youssef 155, Sunitha 124, Akhil 118, Shahad 58, ...). A dry run of the new precedence over
  every terminal changed nothing (0 attributed, 0 cleared on all seven). The shared sync
  path was re-confirmed on the F18 (rule 15): an IT ROOM sync, command 7504, imported 15
  new punches, all attributed, with unmatched unchanged at 3,400.
- **Evidence:** attendance_logs and employee_device_maps for device 17; the backup above;
  device_commands 7504.
- **Status:** FIXED. The 1,438 punches under 22 user IDs no longer on the terminal stay
  unattributed until the owner says who those people were. Catering's model is not
  recorded on its device row, so it runs with the cautious generic capabilities (rule 21);
  identify it (`device.info`, read-only).
- **Keywords:** 0 punches, unattributed, employee_id null, punches before users, user import, reattribute, Catering, history not linked

### DC-030 — A person linked to a terminal but no longer on it could not be pushed, moved or removed — every path said "not found"

- **Date:** 2026-10-22
- **Devices:** Camelot (F18/ID, 87.237.196.60)
- **Direction:** software → device
- **Symptom:** Owner: pushing Gretchen Grace Gan Kaptan (Device User ID 6) "says failed,
  Gretchen is not found on Camelot".
- **What was done:** read-only, then a code change.
  - Her Camelot link is user 6, `reconciliation_status` missing_on_device (nightly roster
    read): Camelot's 2026-10-21 wipe removed her and the link stayed live. 13 such links on
    Camelot (DC-029). Her Personnel ID is 841021481, so every push tried the F18 move
    (`moveByReadding`), which needs the record and threw "was not found"; a removal
    would fail the same way (DELETE of a user who is not there) — no screen path out.
- **Root cause:** `moveByReadding()` treated "not on the terminal" as an error, though there
  is nothing to save or delete and the system holds the person's copies.
- **Fix:** `DeviceUserSyncService::moveByReadding()` — when the record is not on the terminal
  AND the link is already missing_on_device (two reads agree), close the old link and add
  the person under the new ID with `pushEmployee()` (stored card and fingers written back,
  read back). One read only → refused with a clearer message. Tests:
  `EmployeePropagationTest` (2). `docs/device-models.md` F18 row.
- **Verified:** — (the owner's push of Gretchen on Camelot, next)
- **Evidence:** employee_device_maps for employee 1778, device 12.
- **Status:** OPEN — until Gretchen's push succeeds and reads back on Camelot.
- **Keywords:** not found on Camelot, read the terminal's users first, missing_on_device, stale link, wiped terminal, moveByReadding, re-add, push failed

### DC-029 — A delete-by-code half-ran on Camelot: fingers gone, records kept, and the record now drops new fingers

- **Date:** 2026-10-22
- **Devices:** Camelot (F18/ID, 87.237.196.60)
- **Direction:** software → device
- **Symptom:** Owner: "by mistake I deleted 2 users just now in Camelot, through Postman
  — can you get them back".
- **What was done:** read-only investigation, two database/terminal recoveries.
  - `DELETE …/by-code/{code}` at 10:47 (Charmae Darbin Quijano, #1771, user 930326288)
    and 10:49 UTC (Monir Khandakar, #1767, user 230): each deactivated and queued
    `remove_user` (commands 2946, 2947). `removeEmployee()` saved their credentials,
    sent DELETE_USER, and the read-back found the record STILL there —
    `VerificationException` "accepted the delete … but the record is still there".
    Writing that failure to `storage/logs/device-2026-10-22.log` then threw
    "Permission denied": the file had been created by a diagnostic script run as root
    that morning, so the www-data worker could not append — the commands ended "job
    lost" instead of failed cleanly. The employee delete never ran (it needs a
    confirmed removal).
  - Read-only: both still on Camelot, links intact, punches intact (1,382 / 2,653),
    stored copies intact (1 / 4 slots) — but **no fingers on the terminal** for these two
    (the other 9 users keep theirs).
  - Reactivated both (`is_active` true, through the model, audited 10:55:47 UTC).
  - Fixed: every root-owned file under storage/ and bootstrap/cache chown'ed to www-data.
  - Tried: `FingerprintPusher::push` of their stored copies onto Camelot — ACKed, read
    back with none kept ("accepted fingerprint slot(s) … but did not keep any"). Nothing
    overwritten (add-only).
  - Owner: "re-add Charmae and Monir from the system". 11:5x UTC, per person: read →
    `removeEmployee()` → check gone → `pushEmployee()`. Both REMOVES refused again the
    same way (ACKed, record still there on read-back) — stopped for each before any
    re-add; nothing changed on the terminal.
  - Read-only, raw user table: 11 records, NO duplicate user ids (Charmae uid 3, Monir
    uid 11); every other uid holds templates, uids 3 and 11 none. Dead end: the
    "two records with one number" explanation is ruled out.
  - Stopped remote attempts on a live terminal (rule 16). The firmware's own message
    suggests a power cycle.
  - Owner power-cycled Camelot on site (back at 14:13 UTC, same address, clock right,
    11 users). Retry at ~14:15 UTC: both REMOVES now worked and read back gone. The
    re-add then refused — "has no identity on Camelot": the script called
    `pushEmployee()` without `claim()` after the removal closed the link (a script
    mistake, not the terminal). Both were off Camelot for ~2 minutes; then `claim()` +
    `pushEmployee()`: **Charmae 930326288 finger 6, Monir 230 fingers 3, 5, 6, 7 —
    written from the system copies and read back.**
  - Found while there: 13 employees still LINKED to Camelot are not on the terminal
    (its 2026-10-21 wipe) — including Winnie Khandakar (link 900177098, the DC-026-style
    mix-up; she is not on Camelot at all) and Firoj, both punching there until
    2026-10-21. Owner to decide re-adding them / closing the other links.
- **Root cause:** Camelot executed DELETE_USER only partly — templates removed, user
  record kept — and the remaining record will not take templates. Firmware state on this
  unit after a partial delete over the internet link; cause inside the terminal not
  known. Separately: diagnostics run as root left a root-owned log file that the worker
  could not write.
- **Fix:** none to the software. A power cycle of Camelot cleared the stuck state; the
  two were then removed and re-added from the system with their stored fingers. Lesson:
  on this unit, a delete ACKed but not done is a terminal state a restart clears —
  restart before retrying writes.
- **Verified:** Camelot read back 14:16 UTC: Charmae [6], Monir [3, 5, 6, 7], 11 users.
- **Evidence:** commands 2946, 2947; laravel.log 10:47:06, 10:49:48 UTC; audit 10:55:47.
- **Status:** FIXED — both active, on Camelot with their fingers.
- **Keywords:** delete by code, accepted the delete but the record is still there, VerificationException, job lost, Permission denied, device log, root-owned, fingers dropped, did not keep any of them, Camelot

### DC-028 — Camelot wiped outside the system; its June–October 2026 attendance exists only as undecodable raw bytes

- **Date:** 2026-10-22
- **Devices:** Camelot (F18/ID, 87.237.196.60)
- **Direction:** device → software
- **Symptom:** Owner, 2026-10-22: Camelot "was having a problem for 2 days and we wiped
  the device yesterday", and asked to put its punches back to see what exists and what
  not. The owner's partner wiped it from outside this system; no backup was taken.
- **What was done:** read-only, no hardware touched, no data changed.
  - The terminal held 42,423 punches at 2026-10-21 14:20 UTC and 0 at 16:34 UTC (health
    check counts); users went 24 → 11. This system's own wipe (command 1894, 16:12) and
    clear (1884, 16:04) never ran — "busy", "job lost". Nothing was sent by them.
  - The system's newest REAL Camelot punch before the wipe is **2026-06-04 07:44 UTC**.
    Nothing is stored for 2026-06-05 → 2026-10-20 apart from 87 in June and the 1,497
    misaligned future-dated rows of DC-020. For ~4½ months every read came back mostly
    misaligned (DC-020), so the attendance lived only on the terminal, which is now
    empty.
  - What survives: the raw 40-byte records of every undecodable read, archived before
    parsing (rule 11) — sync runs 255 (2026-10-18, 30,720 rows), 263 (10-19, 14,108),
    283 (10-20, 39,520 of 42,388 read), 310 (10-21 07:00, 29,305). DC-020 showed the
    misalignment shifts records by whole fragments rather than destroying them.
  - Not archived: records that DID decode but fell outside a range read's dates
    (e.g. 2,868 in run 283) — left alone by design, so not stored anywhere.
  - **Rebuild, read-only, offline** (scratch script; nothing written): each run's raw
    rows (hex of 40 bytes) joined in id order into one stream, then scanned for valid
    40-byte records (digit user id, timestamp 2015 → 2026-10-21, 8 zero padding bytes),
    re-syncing a byte at a time where none starts. The first sample showed the record
    starting 4 bytes into the row; across the reads, records start at offsets 28, 20,
    32, 4 and 16 — a few fixed shifts, not random damage.
    - Run 283 alone: 39,518 of 39,520 rows rebuilt (44 bytes skipped); **33,684 match
      stored punches exactly** on user and time — the rebuild reproduces real records.
    - Runs 255 + 263 + 283 + 310: 100,129 rebuilt, **39,362 distinct**, 34,592 already
      stored, **4,770 not in the system**, of which **2,591 dated June–October 2026**
      (June 558, July 543, August 522, September 587, October 444); 44 user ids, 31 of
      them ever mapped to an employee on Camelot. Newest rebuilt: 2026-10-21 01:45
      office time.
    - Not recoverable from the archive: punches after 2026-10-21 01:45 office time until
      the wipe, and ~3,000 of the 42,423 records the terminal held (records a range read
      decoded but skipped as outside its dates are not stored anywhere).
  - **Dry run** (offline, nothing written): the 4,770 would import as 3,969 attributed
    by the mapping in force at each punch, 801 unattributed. 20 Sep – 21 Oct 2026, the
    month the owner says HR never received: 12 employees with 31–59 punches each, plus
    users **9** (58) and **14** (53) mapped to nobody — likely current staff never
    linked (owner to name them). Many people appear under a short id and their CPR:
    moved during October, both credited by time.
  - Owner, 2026-10-22: Camelot itself stays a fresh start — nothing recovered goes back
    on it. Wants the recovered data on the TEST terminal IT-Office to look at in the old
    software, and a "copy onto a replacement terminal" feature.
  - **Punch WRITE found and proven** on IT-Office (now device #18, F18, ZMM210_TFT,
    Ver 6.60 Apr 26 2016 — the same firmware as Camelot): `CMD_SET_DATA` (3001),
    `ATT_LOG` text payload, from `taha-rafi/php-zkteco` `bf7805c` (research, rule 17);
    two runs, separate sessions, one test punch each for user 999999001 on 2001-01-01
    09:00 / 09:01 — `ACK_OK`, read back exactly (`attlog-write-01/02.json`). Those two
    test punches remain on IT-Office.
  - **Copied onto IT-Office** (owner: "go with A"), `tools/zkprobe/attlog-copy.php`
    (WRITES, IT-Office only, resumable so nothing is written twice): Camelot as it was
    before the wipe — the system's Camelot punches with a digit user id, before
    2026-10-21 16:30 UTC and from 2015 on (DC-020 junk and the 18 post-wipe punches
    left out), plus the rebuilt ones not stored: **42,415 punches** (37,645 + 4,770),
    2021-08-17 → 2026-10-20 UTC, 46 user ids — within 8 of the 42,423 Camelot itself
    reported before the wipe. 2026-10-22 08:09 → 08:38 UTC, ~41 ms a punch, every reply
    ACK_OK. **Read back: 42,418 records (42,415 + the 3 earlier), all decoded, 0 of the
    42,415 missing** (`attlog-copy-01..04.json`).
  - IT-Office is linked to nobody, so a read cannot credit these to anyone; a FULL
    re-read of IT-Office would import them as unattributed punches — do not.
  - 2026-10-22 08:45 UTC a "Sync punches" on IT-Office (command 2794, not by this
    session) imported all 42,418 into the system as IT-Office punches, unattributed.
  - Owner asked for an admin user on IT-Office to pull the punches by USB. A push of
    Ghassan was refused (roster entry 1434 for 830108246 unclaimed — the guard working);
    830108246 holds none of the copied punches, so entry 1434 was claimed for Ghassan
    (map 3001). The privilege write then found NO user on the terminal: between 08:55 and
    09:00 UTC IT-Office's clock was set from outside this system (drift 123,874,360 s →
    665 s) and the terminal was emptied — **0 users, 0 punches** at 09:05. Nothing was
    sent by this system. Likely the old software connecting with a download-and-clear,
    or the terminal menu — owner to say.
  - Hand correction: map 3001 `valid_from` NULL → 2026-10-22 09:05:30 UTC, so the
    claim's unbounded start cannot credit Ghassan with IT-Office punches already stored
    (1 is under 830108246: IT-Office's own old punch, left unattributed). Undo: set it
    back to NULL.
  - Owner asked for an admin user to pull the punches by USB; their Test User (1805,
    code 999) push had been refused while roster entry 1434 was unclaimed. Pushed after
    the claim: IT-Office user **1**, privilege 14, keypad password set — read back.
  - **Re-copied** the same 42,415 (2026-10-22 09:10 → 09:37 UTC, every reply ACK_OK; first
    run's progress kept as `.progress-run1`). Read back: **42,416 records, all decoded,
    0 of the 42,415 missing** (one punch made at the terminal during the copy) —
    `attlog-copy-05/06.json`.
  - Owner pulled the log from IT-Office by USB and produced a report of 20 Sep – 21 Oct
    2026 by user id: 669 punches, 23 ids — equal, to the second, to the same period of
    the copied set. Named from Camelot's links for that period (14 people; ids 9 and 14
    by Camelot's own names "TUHIN" / "Suzeth", unconfirmed; 900177098's 4 punches are
    Winnie Khandakar's — DC-026-style mix-up, Monir's CPR on her record 10-08 → 10-21).
    Delivered as `draft/Camelot_Attendance_20Sep-21Oct_2026_named.pdf` (git-ignored).
    Owner, 2026-10-22: "we solved the attendance problem", IT-Office disconnected.
  - Not done (owner's call, not required to close): importing the 4,770 into the
    system; confirming ids 9 and 14; removing the 42,418 IT-Office punches and the
    IT-Office device from the system.
- **Root cause:** DC-020's misaligned reads over the internet link left the attendance
  only on the terminal; the terminal was then wiped without a backup.
- **Fix:** none to the software; the data was recovered — raw archive rebuilt (4,770
  punches the system lacked), Camelot's pre-wipe log reassembled (42,415 of the 42,423
  the terminal held), written onto IT-Office with the newly proven punch write
  (`CMD_SET_DATA` 3001), pulled by USB, and named for HR. Prevention still owed: an alert
  when a terminal's reads are mostly undecodable, and refusing a clear while they are.
- **Verified:** IT-Office read back 0 missing of 42,415 (twice); the owner's USB report equals the copied set for 20 Sep – 21 Oct (669 punches, same times).
- **Evidence:** devices.terminal_punch_count audit trail for device 12; sync runs 255,
  263, 283, 310; attendance_raw_logs for device 12.
- **Status:** FIXED — the period HR needed is recovered and delivered. Keep Camelot's
  raw archive rows and sync runs: they are the evidence and the only other copy.
- **Keywords:** Camelot wiped, data loss, no backup, raw archive, undecodable, misaligned, recovery, rebuild punches, attlog, DC-020

### DC-027 — A terminal with no fingerprints at all could never be given one: an empty template table read as "unknown"

- **Date:** 2026-10-21
- **Devices:** IT-Office (F18, 192.168.7.64); controls Good Wheels (F18/ID, 46.184.163.106), Camelot (F18, 87.237.196.60), On The Road (F22, 192.168.7.63)
- **Direction:** both — a read decided a write would not happen
- **Symptom:** Owner pushed Ghassan to IT-Office right after the terminal was wiped:
  "it gives success but his fingerprint did not work".
- **What was done:** read-only investigation, then a library fix.
  - Read-only: IT-Office held Ghassan (user 830108246, card 7566580, password) and no
    templates; the system holds two of his fingers (slots 3, 6). No
    `employee.fingerprints_pushed` in the audit log. His fingers read back `null`
    (unknown), so `FingerprintPusher` skipped him ("its fingers would not decode").
  - Cause in the library: `UserService::all(true)` made every user `null` when the
    template table decoded to no records — empty and unreadable looked the same.
  - Probe `tools/zkprobe/empty-template-table.php` (READ-ONLY, new): IT-Office's
    template read returned **0 bytes**; control Good Wheels 28,610 bytes, prefix
    `be6f0000` (28,606), 33 templates. `BulkReader` returns `''` only for the device's
    own "nothing to send"; a failed transfer throws.
  - **First fix incomplete.** Library 2.16.1 fixed the READ (`UserService::all()`):
    IT-Office then reported Ghassan's fingers as `[]`. The owner pushed again at 14:32
    UTC: card fine, still no finger, no `fingerprints_pushed` audit. Read-only, each
    `FingerprintPusher::pushOne()` condition passed (active, writable, template.upload,
    linked, 2 copies). The WRITE refused: `TemplateService::addTemplates()` asks
    `TemplateCodec::isEmptyTable()`, which knew only the 4-byte zero header, so the
    0-byte table read as "would not decode" → `UnsupportedCommandException`. Dead end
    worth recording: 2.16.1 added a second empty-table check inside `UserService`
    instead of fixing `isEmptyTable()` — two definitions for one question.
  - The refusal reached the operator as "IT-Office cannot take a fingerprint from this
    app" — `FingerprintPusher::restoreOnto()` worded every `UnsupportedCommandException`
    that way, true or not.
  - Second read-only probe run (`empty-template-table-02.json`, 34 min after the
    first): IT-Office 0 bytes again, `isEmptyTable` true; Good Wheels, Camelot, On The
    Road non-empty, false.
- **Root cause:** library ≤ 2.16.0 treated an empty template table as "unknown". Every
  wiped or brand-new terminal was therefore unable to receive a first finger from the
  system, on every model using `UserService::all()`.
- **Fix:** library **2.16.1** (read) and **2.16.2** (write): `TemplateCodec::isEmptyTable()`
  accepts `''` as well as a zero header and is the one definition, used by
  `UserService::all()`, `TemplateService::addTemplates()` and the ID-move paths; bytes
  that decode to nothing stay unknown. App: `restoreOnto()` says "cannot take a
  fingerprint" only when the model lacks template.upload, otherwise the real reason.
  Tests: `UserFingerSlotsTest` (4), `TemplateAddTest` (first finger on an empty
  terminal), `TemplateCodecTest` (the definition), `FingerprintPushTest` (real reason).
- **Verified:** read-only through the fixed library: IT-Office → Ghassan `[]` (was
  `null`); Good Wheels 19/19, Camelot 24/24, On The Road 8/8 users' finger slots
  identical to the system's previous read (rule 15 re-confirmation; IT ROOM, the usual
  F18 reference, was offline — Camelot is the F18 control). Not yet: Ghassan's fingers
  actually written to IT-Office — the owner's next push after 2.16.2.
- **Evidence:** `tools/zkprobe/traces-2026-10-21/empty-template-table-01.json`, `-02.json`.
- **Status:** OPEN — fixed in code (2.16.2); FIXED once a push writes Ghassan's fingers
  to IT-Office and they read back.
- **Keywords:** fingerprint not pushed, empty template table, 0 bytes, fingerSlots null, unknown fingers, FingerprintPusher skipped, its fingers would not decode, wiped terminal, new terminal, UserService::all, slotsByUserId

### DC-026 — A CPR typed onto the wrong employee, corrected, could never be given to the right one on that terminal

- **Date:** 2026-10-21
- **Devices:** Good Wheels (F18/ID, 46.184.163.106)
- **Direction:** software → device
- **Symptom:** Owner: Syed Wajid Abbas has his CPR 770280340 as Personnel ID but shows as
  user 8 on Good Wheels. The Push dialog offered "User ID 8 → 770280340 by re-adding
  them"; the push failed: "User ID 770280340 is already in use on Good Wheels, or was in
  the past. User IDs are never reissued".
- **What was done:** read-only investigation, then a code change.
  - 11:17 UTC read of Good Wheels: 19 users; Syed = user 8 (uid 35), no card, 1 finger
    (slot 0); 770280340 not on the terminal.
  - History (audit_logs, employee_device_maps): 2026-10-11 07:23 Syed's CPR, last name
    and job were typed onto **Muhammad Asif AKRAM** (employee 1748). 2026-10-18 09:38 a
    push moved Asif on Good Wheels 16 → 770280340 (F18 re-add). 6 of Asif's punches were
    recorded under 770280340 (2026-10-18 14:06 → 2026-10-21 04:59), credited to him.
    2026-10-21 08:31 corrected: Asif back to 850256461, moved there on Good Wheels.
    08:34 Syed created with 770280340.
  - Correction of an earlier claim made in this session: the F18 re-add
    (`moveByReadding`) HAS run end to end on Good Wheels — Asif, 2026-10-18 and
    2026-10-21, both successful.
  - The refusal came from `DeviceIdentityResolver::assertPinFreeFor()`: any PIN another
    employee EVER held on a terminal was refused. Audited every lookup by
    `device_user_id`: terminal-record ownership uses live mappings only; punches resolve
    by time (`EmployeeDeviceMap::inForceAt`, `AttendanceSyncService::employeeAt`,
    `attendance:reattribute`). So a new holder's mapping starting now cannot reach the
    old holder's punches — the blanket rule was stricter than the data model needs.
  - Separate finding: 315 punches under user 8 on Good Wheels (2024-07-28 →
    2025-04-21) are unattributed — Syed's mapping to 8 ran only 2026-10-21 08:34 →
    11:35. No punches under 8 since April 2025, and nothing in the system records who
    user 8 was before 2026-10-21 (a terminal's attendance log carries the number only).
    **Owner, 2026-10-21: "User 8 was someone else back then."** So they are NOT Syed's
    and are left unattributed. The terminal record had been reused for Syed (renamed or
    re-enrolled at the keypad). If the former worker is ever identified, give them a
    closed mapping on Good Wheels pin 8 ending before 2026-10-21 08:34, then run
    `attendance:reattribute 5 --dry-run` first.
- **Root cause:** the never-reissue rule predates time-boxed attribution and refused even
  a number whose previous holder's history is fully closed.
- **Fix:** `DeviceIdentityResolver::pinReusableFor()`, used by `assertPinFreeFor()` and
  `claim()`: an explicitly requested PIN may take over a retired one when every other
  holder's mapping is closed, no stored punch under it is dated at or after the last
  one ended, and its roster entry is a reservation or already this employee's (the entry
  becomes their claim). `nextFreePin()` unchanged. Tests: `DeviceIdentityGuardTest` (3
  new), `EmployeePropagationTest` (refusal test updated to a still-refused case, success
  case added). Docs: `device-identity-and-adoption.md`. 710 tests pass. Real data,
  read-only: `assertPinFreeFor(Good Wheels, Syed, 770280340)` now allows it; 0 punches
  under 770280340 since Asif left it.
- **Verified:** on Good Wheels. Owner pushed Syed from the Push dialog at 11:35:55 UTC
  (F18 re-add). Read back 11:39 UTC: 19 users (unchanged); user 8 gone; **770280340 =
  SYED WAJID ABBAS**, same uid 35, card **7632845** (the system's card, written by the
  push), finger slot 0 with the **same template hash** as before (`bc715568cb7b`). Mapping
  8 closed 11:35:55.623, 770280340 opened 11:35:55.643. Asif still 850256461 on the
  terminal; his 6 punches under 770280340 still credited to him.
- **Evidence:** audit_logs for employees 1748 and 1803; employee_device_maps 2962, 2987.
- **Status:** FIXED. The 315 old punches under user 8 are someone else's (owner) and stay unattributed until that person is identified.
- **Keywords:** never reissued, already in use, or was in the past, PinInUseException, assertPinFreeFor, pinReusableFor, wrong CPR, Personnel ID typed on wrong employee, moveByReadding, re-add, Good Wheels

### DC-025 — Employee deleted by Personnel ID over the API is still on three terminals and can still punch

- **Date:** 2026-10-21
- **Devices:** IT ROOM (F18, 192.168.7.62), Entrance (TF1700, 192.168.7.60), On The Road (F22, 192.168.7.63)
- **Direction:** software → device (a delete that never reached the device)
- **Symptom:** Owner, from Postman: deleted the test employee with
  `DELETE /api/employees/by-code/{code}` (built the same morning), got success, the
  employee is gone from the screens — "but I still can punch in the device".
- **What was done:** read-only so far.
  - 08:12:02 UTC the owner (user 12) deleted employee **1802 "Test User"**, Personnel ID
    **1000** (audit_logs 6764). Read-only reconstruction from audit_logs: on IT ROOM
    since 2026-10-20 as an **administrator with a keypad password** and finger 3; pushed
    to Entrance 2026-10-21 06:33 UTC and On The Road 06:41 UTC, finger 3 each.
  - The delete cascaded `employee_device_maps` and the stored template, so the system
    no longer knows the person or where they were. The three terminals still hold user
    1000 — an unclaimed administrator on IT ROOM.
  - No terminal written yet. Removal of user 1000 from the three terminals awaits the
    owner's go-ahead (rule 16).
  - Owner asked for an endpoint that deletes from the system AND the terminals, for an
    integration. Built the same day (below). It cannot clean up user 1000: that person
    no longer exists in the system to address.
  - Dead end in the first test of it: the integration tests' fixture terminal
    (`protocol: zkteco`, no model) cannot write users, so a delete test against it only
    ever exercised the "skipped" branch. The existing `remove_from_devices` test has the
    same blind spot. The new test uses an F18-model binary terminal and asserts the
    queued `REMOVE_USER` path.
  - **Owner's go-ahead 2026-10-21; user 1000 removed from all three terminals.**
    1. Read-only, 09:0x UTC: user 1000 on each was named "Test" with a keypad password —
       IT ROOM uid 28 privilege **14**, Entrance uid 247, On The Road uid 20. No employee
       held code 1000; no mapping (live or closed) on 1000 on any of the three.
    2. Write, 09:03 UTC, `DeviceUserSyncService::releaseUnlinkedNumber($device, '1000',
       null)` per terminal, the script re-checking the name was "Test" just before each
       delete. Each saved the record and 1 fingerprint first, deleted, read back:
       `removed-records/device-15-1000-20261021-090330.json.enc` (IT ROOM),
       `device-14-1000-20261021-090337.json.enc` (Entrance),
       `device-11-1000-20261021-090345.json.enc` (On The Road). Audit 6834–6836.
       Run from a root shell, so the three files were chown'ed to www-data afterwards.
    3. Independent re-read: 1000 absent everywhere; user counts IT ROOM 9→8, Entrance
       316→315, On The Road 11→10 — exactly one removed from each.
    4. Hand correction: IT ROOM's roster entry 1433 (pin 1000) was set `ignored` by the
       method with the note "Removed by a device rebuild"; the note was rewritten to say
       what happened. The entry keeps **1000 reserved on IT ROOM** — a future employee
       given code 1000 would not be pushed there until it is cleared. To undo: set
       `claim_status` back, or delete row 1433, if the owner wants 1000 reusable.
    5. **Owner, 2026-10-21: "make code 1000 free again on IT ROOM".** Checked first:
       no employee with code 1000, no mapping on IT ROOM pin 1000, 1000 not on the
       terminal; 9 unattributed punches under it (Test's, 2026-10-20 12:00 →
       2026-10-21 08:14), which a new holder's window starting later cannot reach.
       Row 1433 deleted 11:58:59 UTC (database only); the resolver then answers 1000 as
       free on IT ROOM. The row as it was, to restore it if ever needed:
       `{"id": "1433", "device_id": "15", "device_uid": 28, "device_user_id": "1000", "device_name": "Test", "device_privilege": 14, "fingerprint_slots": "[3]", "claim_status": "ignored", "first_seen_at": "2026-10-20 12:51:27.447", "last_seen_at": "2026-10-21 08:41:28.863"}`
  - First live run of a delete on the TF1700 (Entrance): succeeded and verified.
- **Root cause:** `destroyByCode()` (`EmployeeController`, commit `4f89663`) copied
  `destroy()`: it deletes the record only. The screen's Delete warns about exactly this
  in its confirmation; the API said nothing and answered 200. The integration API
  already has the right behaviour (`DELETE /api/v1/employees/{id}?remove_from_devices=1`,
  `EmployeeOffboarding`: queue removals that save card and fingers first, deactivate,
  delete once every terminal confirms) — the new endpoint did not use it.
- **Fix:** `DELETE /api/v1/employees/by-code/{code}` (integration API, `employees:write`)
  and the same method at `DELETE /api/employees/by-code/{code}`:
  `Api\V1\EmployeeController::destroyByCode()` → `EmployeeOffboarding::remove(..., true)`
  — queues `REMOVE_USER` per live mapping (each through `removeEmployee()`, card and
  fingers saved first), deactivates at once, deletes the row once every terminal
  confirms; a terminal it cannot write keeps the row. The record-only
  `destroyByCode()` on the SPA controller was removed. Tests: `IntegrationApiTest`
  (`test_delete_by_code_*`, 4), `ApiFlowTest` (by-code, 3). Docs:
  `integration-api.md`, `api-endpoints.md`, `credential-preservation.md`.
- **Verified:** the cleanup on hardware (step 3 above), and the endpoint live on IT ROOM.
  The owner created a test employee (1804, Personnel ID 1000, IT ROOM user 3 — 1000 was
  reserved there) and sent `DELETE /api/v1/employees/by-code/1000`: 09:17:39 UTC
  `remove_user` command 1491 queued and the employee deactivated; 09:17:47.703 "Test
  removed from IT ROOM." (1 attempt); 09:17:47.743 employee deleted by
  `EmployeeOffboarding::commandFinished()`. Re-read 11:56 UTC: 7 users, user 3 gone,
  nobody else missing.
- **Evidence:** audit_logs 6764 (delete), 6711-onward history of employee 1802.
- **Status:** FIXED — user 1000 removed from all three terminals, and the endpoint proven live on IT ROOM.
- **Keywords:** delete by code, by-code, employee_code, orphan terminal user, still punches, unlinked, remove_from_devices, EmployeeOffboarding, cascade employee_device_maps

### DC-024 — The 5-minute health check never ran: it threw before queueing, then skipped every terminal

- **Date:** 2026-10-18 to 2026-10-20
- **Devices:** Good Wheels (F18/ID, 46.184.163.106), On The Road (F22/ID, 192.168.7.63), Camelot (F18, 87.237.196.60), IT ROOM (F18, 192.168.7.62) — the four ZKTeco terminals `CheckAllDeviceHealth` covers. Glass Door and Entrance (C3) are not in it.
- **Direction:** software → device (never reached the device)
- **Symptom:** found 2026-10-20 while listing the cron jobs for the owner: the last `health_check` command was created 2026-10-18 09:00 UTC, although the job is scheduled every 5 minutes and cron had been running `schedule:run` since 08:43 UTC that day (DC-023).
- **What was done:** (1) `schedule:list` showed `CheckAllDeviceHealth` every 5 min; the other scheduled work (05:00 clock + read, 02:30 copies) had produced commands on 2026-10-20, so the scheduler was fine. (2) `device_commands`: 164–167 (one per terminal, 2026-10-18 08:48–09:00 UTC) all still `queued`, `jobs` table empty — rows with nothing behind them. (3) `failed_jobs` 14–17 at the same times: `Property [id] does not exist on the Eloquent builder instance`. (4) Read the job: `DeviceCommand::create([...])->tap(fn ($command) => ProcessDeviceCommand::dispatch($command->id))`. (5) Fixed, tested, then on production marked 164–167 `failed` with message "Never sent … (DC-024)" and `finished_at` = 2026-10-20 07:44 UTC (to undo: set them back to `queued` — pointless, they have no queue job); `queue:restart`.
- **Root cause:** a Model has no `tap()` method; `->tap()` falls through `__call` to a new query Builder, so the closure received a Builder and `$command->id` threw. The row was already saved `queued`, but `ProcessDeviceCommand` was never dispatched. Each terminal's first failure therefore left a permanent "pending" check, and the job's own guard (skip a terminal with a check queued or running) skipped all four on every later run — so after the first 15 minutes it failed silently, without even new failed jobs.
- **Fix:** `app/Jobs/CheckAllDeviceHealth.php` uses the `tap()` helper, `tap(DeviceCommand::create([...]), fn ($command) => …)`. Test `tests/Feature/Devices/CheckAllDeviceHealthTest.php` (each terminal gets a check AND a queue job; no second check while one is pending) — fails on the old code with the production error, passes on the new. No other `)->tap(` on a model in `app/`.
- **Verified:** the 2026-10-20 07:45 UTC scheduled run queued commands 224–227 and all four ran: On The Road online (17 ms), Camelot online (11 ms), IT ROOM online (11 ms), Good Wheels "did not answer: Device connection timed out or closed unexpectedly" (it took a clock set at 07:01 UTC the same day). The next run, 07:50 UTC, commands 228–231: all four online, Good Wheels in 35 ms — the 07:45 miss was a one-off on its internet link, not the job.
- **Evidence:** failed_jobs 14–17; device_commands 164–167 (stuck, now failed), 224–227 (first working run); `CheckAllDeviceHealthTest`.
- **Status:** FIXED
- **Keywords:** health check, health_check, CheckAllDeviceHealth, tap, Property [id] does not exist on the Eloquent builder instance, stuck queued, never runs, skipped, schedule

### DC-023 — Punches were never read automatically: nothing ran the scheduler

- **Date:** 2026-10-18
- **Devices:** Good Wheels (F18/ID), On The Road (F22/ID, 192.168.7.63), Camelot (F18, 87.237.196.60), Glass Door (inBIO460, 192.168.7.61), Entrance (TF1700, 192.168.7.60), IT ROOM (F18, 192.168.7.62)
- **Direction:** device → software
- **Symptom:** owner: "are you reading punches every day as per the cron job?" — then "I thought you read punches every day. This is a big problem for us."
- **What was done:** (1) `crontab -l` for root and www-data: none; no systemd timer. `schedule:list` showed the jobs defined, but nothing invoked `schedule:run`, so NO scheduled job had ever run: not the 05:00 clock set + past-day read (DC-019), not the 02:30 fingerprint copies, not the 5-minute health check. (2) `attendance.sync_enabled` is false for tenant 1 (by design; the 05:00 morning routine, `devices.daily_read_enabled` = on, is the daily read). (3) `sync_attendance` commands in the 8 days before: only 2026-10-11, 13, 14, 15, all operator-pressed; Camelot's 2026-10-13 one failed. (4) Installed for www-data `* * * * * cd /var/www/zkt && php artisan schedule:run >> /dev/null 2>&1`; syslog shows it firing from 08:43 UTC and the first `health_check` (command 164) was queued by it. (5) Queued a catch-up `sync_attendance` on all six (commands 158–163), all succeeded: imported Good Wheels 112, On The Road 20, Camelot 122, Glass Door 51, Entrance 53, IT ROOM 32. Camelot: 17,203 of 42,352 undecodable, see DC-020.
- **Root cause:** the crontab line was flagged as an owner action in `docs/PROJECT-STATE.md` from 2026-09-24 and in DC-019, and never installed. Every scheduled feature built since then was tested in isolation and never ran in production.
- **Fix:** the crontab line above (server change, not in the repository).
- **Verified:** cron fires `schedule:run` every minute (syslog), health checks queue by schedule. NOT yet seen: a 05:00 Bahrain (02:00 UTC) morning-routine run producing `sync_time` + range-read commands on every terminal.
- **Evidence:** device_commands 158–164; `/var/log/syslog` CRON lines for www-data from 2026-10-18 08:43 UTC.
- **Status:** MONITORING — next session checks the 2026-10-19 02:00 UTC run.
- **Keywords:** cron, schedule:run, crontab, scheduler never ran, daily read, morning routine, punches not synced

### DC-022 — Keypad passwords: never pushed, never read — and erased by every standalone write

- **Date:** 2026-10-12
- **Devices:** IT ROOM (F18, 192.168.7.62), Camelot (F18, 87.237.196.60), Good Wheels (F18/ID, 46.184.163.106), On The Road (F22/ID, 192.168.7.63), Glass Door (inBIO460, 192.168.7.61), Entrance (TF1700, 192.168.7.60)
- **Direction:** both
- **Symptom:** owner: "push the passwords to the terminals too … and also read them along
  with the read all." The form said passwords were "saved here only".
- **What was done:** Reading the library to plan the write found that
  `Packet::buildUserRecord()` never filled password(8)@3, and `UserService::save()`
  rebuilt the record from name, card and privilege, so **every standalone name push and
  card write erased the password**. C3 `Personnel::update()` carries the row over (no
  erase); `add()` sent `''`. Read-only probe `tools/zkprobe/user-passwords.php` (run 01:
  counts; run 02: plus an in-memory comparison with the app's stored copies): passwords on
  all six terminals (13 records, digits, 4–6 long); **equal 11, differs 0**; IT ROOM had
  one the app held and the terminal did not — the erase, most likely. No value was
  printed or traced. No write was made to any terminal.
- **Root cause:** the record builder had no password argument; the field was always zero.
- **Fix:** library 2.13.0 — `password.read`/`password.write` capabilities, decode, write,
  keep-when-null in `save()`, C3 `add/update` password, read-back that never prints the
  value; levels: read C on F18, F22, inBIO460, TF1700, write I. App: the driver contract's
  `saveUser(…, ?string $password)`; `DeviceUserSyncService::capturePassword()` on every
  roster read and before every removal (fills an empty `device_password`, never
  overwrites); `passwordToWrite()` writes on push only where `password.write` is C; the
  form treats a changed password as out of date only on such terminals;
  `device_password` redacted in the audit log. Tests: library `UserPasswordTest`,
  `C3PersonnelTest`; app `EmployeePropagationTest` (3 new); methodology pins gained two
  columns, no existing level moved.
- **Verified:** reads — the comparison above, on four models. The keep-on-write fix — by
  test only so far. Writes — **not yet run on hardware**:
  `tools/zkprobe/user-password-write.php` (temporary user "zkt-test", fixed dummy
  passwords, roster compared before/after) waits for the owner's go-ahead.
- **Evidence:** `tools/zkprobe/traces-2026-10-12/user-passwords-01.json`, `-02.json`;
  suite 617 passed; `queue:restart` run.
- **Status:** FIXED — 2026-10-20: password writes read back on every model and terminal (library 2.15.0); the keep-on-write fix proven on all four models.
- **2026-10-20 — write probe on IT ROOM, owner's go-ahead ("run the test on IT ROOM ONLY"):** `tools/zkprobe/user-password-write.php 15`, run twice in separate sessions (11:48:21 and 11:48:54 UTC). Both: roster 8 users before; temporary user "zkt-test" added on free id 3; dummy passwords 1357 and 2468 each written and **read back equal**; a name write with no password **kept** the password (the 2.13.0 keep-on-write fix, first proof on hardware); test user deleted; roster 8 users after, **unchanged**. Traces `tools/zkprobe/traces-2026-10-20/user-password-write-01.json`, `-02.json`. Also read-only: the owner's test employee "Test" (id 1802, code 1000, a 4-digit password in the system) is on IT ROOM as user 1000 **with no password** — the form saved it in the system only, because `password.write` is still I; its second mapping row (device_user_id 060408070) is not on the terminal. **Not yet changed:** `password.write` stays IMPLEMENTED in `CapabilitySet::defaults()` — promoting it would also enable Camelot and Good Wheels (F18/ID, newer firmware, never written), which the owner has not decided; the owner is testing at IT ROOM first.
- **2026-10-20, later — enabled on IT ROOM only (owner: "enable password writing for IT ROOM only"):** `devices.capabilities_cache` of IT ROOM (id 15) set from `null` to `{"password.write":"confirmed"}` (production data change; TO UNDO set it back to `null`). Checked after: IT ROOM `confirmed`; Good Wheels, Camelot, On The Road `implemented`. Found on the way: `password.write` was missing from `CapabilityResolver::unprobeable()`, so a re-probe would have reset it — library 2.14.1 adds it (`UserPasswordTest`). `queue:restart`. From now a push to IT ROOM writes the system's password (auto-push on save is on) and reads it back.
- **2026-10-20, all devices (owner: "apply the changes of writing password from the system to all … also the admin and normal user thing"):** password probe twice on the other five terminals — all read back, all kept through a name write, every roster unchanged (`user-password-write-03..06.json`). Role probe (new, `tools/zkprobe/user-privilege-write.php`) twice on the four ZKTeco terminals — 14 and 0 read back, name and password kept, every other user's privilege unchanged (`user-privilege-write-01/02.json`). Library 2.15.0: `password.write` CONFIRMED on F18, F22, inBIO460, TF1700; `user.privilege` CONFIRMED on F18 and F22 (F22 UNKNOWN → IMPLEMENTED → CONFIRMED). Methodology pins updated. IT ROOM's per-terminal `capabilities_cache` override set back to `null`. Each run added a temporary "zkt-test" user and deleted it; no real person was written. App: the form's Device role is applied on a save when changed (`apply_role`, `EmployeeDevicePropagator::applyRole()`), where `user.privilege` is C.
- **Keywords:** password, keypad password, password(8)@3, USER_WRQ, buildUserRecord, Password field, SET_DATA, password.read, password.write, erased password

### DC-021 — Every Save took 20–40 s; two operators could talk to one terminal at once

- **Date:** 2026-10-12
- **Devices:** Entrance (TF1700, 192.168.7.60), Glass Door (inBIO460, 192.168.7.61), IT ROOM (F18, 192.168.7.62)
- **Direction:** software → device
- **Symptom:** owner: "when I click save it takes a very long time." Also asked for a
  queue so several operators saving or pushing at once "don't fight over who pushes
  the data first or overwhelm the system".
- **What was done:** Timed a save in-process inside a rolled-back transaction: 70 ms.
  Read `zkt-access.log` (it stamps a request's ARRIVAL) for the owner's saves of
  employees 1737, 1617, 1524, 1430 and 1665 between 08:11 and 08:14 UTC. Each save
  was followed by `POST /propagate` taking 10–29 s, then a read-back of each terminal
  at 3–7 s each: 22–37 s per save. "Push to terminals automatically on save" is on,
  so every save pushed to every terminal the person is on, even when only the job
  title, phone or company had changed. Separately, Save, Push and Enroll call terminals
  from the web request, outside `ProcessDeviceCommand`'s `WithoutOverlapping`, so
  nothing stopped two sessions on one terminal. Checked how far terminal names differ
  from the system's: the cut-offs are at exactly 24 characters (F18, TF1700, inBIO460),
  and the short ones (e.g. 6 vs 13 characters) are real differences.
  No device was written during the investigation; the form was checked in Chrome on
  ABDALLA (on all three terminals) and cancelled without saving.
- **Root cause:** (1) The form pushed to every listed terminal on every save. (2) The
  synchronous device paths had no per-terminal mutual exclusion.
- **Fix:** (1) `EmployeeFormModal.jsx` pushes only to terminals that are out of date:
  newly added; name, ID or card changed in the form; or, at the terminal's last read,
  a different name (the 24-character cut-off counts as the same name), an ID it can be
  moved off, no card where the system has one, or a finger missing that the system
  holds. The footer names the terminals left alone. Password and device role are not
  sent to terminals at all, so they do not count. (2) `DeviceDriverManager::exclusive()`:
  a per-terminal cache lock around every device call, waiting up to 60 s, then
  `DeviceBusyException` (retryable). See `docs/error-handling.md`.
- **Verified:** In Chrome, ABDALLA's form said "Entrance, Glass Door, IT ROOM already
  have their name, ID, card and fingerprints". Adding one letter to the name switched
  it to "Updated on Entrance, Glass Door, IT ROOM, then read back". Cancelled. The lock:
  `DeviceSessionQueueTest` (busy refusal sends nothing, lock released after a throw,
  re-entrant, other terminals unaffected), plus the lock taken and released on the
  production SQL Server cache store. The F18 path is wrapped, not changed. Not yet
  timed on a real save; the owner's next saves are the proof.
- **Evidence:** `zkt-access.log` 2026-10-12 08:11–08:15 UTC; tests above; full suite
  607 passed; `queue:restart` run afterwards.
- **Status:** FIXED. Watch for "busy" messages during long Camelot reads.
- **Keywords:** slow save, propagate, auto_push_on_save, read back, concurrent session,
  WithoutOverlapping, cache_locks, device-session, DeviceBusyException, is busy with another operation

### DC-020 — 1,497 Camelot punches stored with dates from 2026-12-07 to 2030-01-30

- **Date:** 2026-10-11 (~15:05 UTC)
- **Devices:** Camelot (F18, 87.237.196.60)
- **Direction:** device → software
- **Symptom:** none reported. Found while answering the owner's "make sure this system is running on +3 GMT, Bahrain time": the newest stored attendance row was 2030-01-30 13:49:04 UTC.
- **What was done:** read-only, no hardware touched, no data changed. Time-zone chain checked first and is correct: server and PHP UTC, `app.timezone` UTC (storage, rule 10), company `system.timezone` = Asia/Bahrain, every device inherits it (`devices.timezone` NULL), `config('attendance.timezone')` +03:00 for reports. Counted punches later than now+1 h per device: only Camelot has any — 1,497 rows across 43 distinct dates, 2026-12-07 16:22:40 to 2030-01-30 13:49:04 UTC, all inserted 2026-10-11 08:21:38–08:21:39 UTC in one sync. Camelot's newest plausible punch is 2026-06-04 07:44:16 UTC. Its clock measured +5 s in DC-019, so today's clock is not the source — the records were already on the terminal with those stamps (a past clock fault on the device is the likely cause, unproven). `devices.attendance_watermark` for Camelot is 2023-08-08 13:40:28, so these rows did NOT push the watermark into the future and are not blocking new punches.
- **Root cause:** not established. Candidates: the terminal's clock was once set years ahead and the punches recorded then are still in its log; or a decode fault in the stamp. Distinguishing needs the raw records of those rows compared against the decoder.
- **Fix:** none yet. Rows left untouched — deleting them would destroy evidence and possibly real punches with wrong dates.
- **Verified:** —
- **Evidence:** `attendance_logs where device_id = 12 and punched_at > now()`; created_at 2026-10-11 08:21:38.977–08:21:39.060.
- **Status:** FIXED — misaligned rows are no longer imported (2.14.0), and every junk row already stored on Camelot was removed 2026-10-22 (backups kept). The misalignment itself is DC-002/DC-028.
- **2026-10-18 (read-only, no data changed):** the clock hypothesis is very likely WRONG. All 1,497 future rows have a BLANK `device_user_id`, and Camelot has 2,876 blank-user rows in all, dated 1999-12-31 to 2030-01-30 (2,871 inserted by the 2026-10-11 sync, 4 on 2026-09-27, 1 on 2026-10-18). A real punch always carries a user id. These are misaligned bytes that happened to pass the decoder. The catch-up sync that day (command 160, sync run 255) read 42,352 records: 13,517 decoded, **17,203 undecodable**. The newest decoded punch is 2025-11-13 21:26:13 UTC, and the newest non-blank Camelot punch stored at all is 2026-05-26. **So most of the last year of Camelot attendance exists in the system only as undecodable raw bytes. The terminal still holds it.** Sample undecodable raw record (attendance_raw_logs 1288272): `3413 3431 00… 01 918fa72a ff 00… | 3513 323238 000000`. At offset 32 of the 40-byte record a new record begins (uid 0x1335, user "228"), so the stream is offset by whole fragments, which is the DC-002 bulk-read desync. **Consequence: Camelot's attendance log must NOT be cleared until a clean read (0 undecodable, count = terminal's own record count) has been taken.** Next step: a read-only windowed read of Camelot's attendance (the DC-001 F22 method) to see whether smaller transfers survive the internet link.
- **2026-10-18, fix for new reads (library 2.14.0 + app):** `AttendanceCodec` returns a 40-byte record with an empty user id as undecodable; `AttendanceSyncService::ingest()` archives any punch stamped more than a day ahead (`skip_reason = future_timestamp`, `counts.future`) instead of importing it, so it can never become the watermark. Re-confirmed on the F18 path (rule 15): full reads of IT ROOM (command 168: 11,332 read = its GET_FREE_SIZES count, 0 undecodable, 0 future) and On The Road (169: 8,430, 0, 0). Tests: `AttendanceClearTest` (library and app). **The 2,876 junk rows already stored are untouched** — removing them is a production data change the owner decides.
- **2026-10-22, hand correction (owner: "delete all 1,497 junk rows"):** the 1,497 Camelot
  rows with a blank `device_user_id` dated in the future (2026-12-07 → 2030-01-30, none
  credited to an employee) deleted at 11:42:56 UTC, after a verified backup
  `storage/app/private/removed-records/camelot-future-junk-backup-20261022-114255.json.gz`
  (restore from it to undo). Camelot 40,539 → 39,042 punches; credited 30,878 unchanged.
  Then the remaining **1,379** blank-user Camelot rows (dated 1999-12-31 → 2026-06-04,
  none credited) deleted at 11:48:10 UTC, owner's go-ahead, after a verified backup
  `…/camelot-blank-user-junk-backup-20261022-114810.json.gz`. Camelot 39,042 → 37,663;
  credited 30,878 unchanged; other terminals unchanged. **No blank-user row is left on
  Camelot.** NOT touched: blank-user rows on the C3 panels Entrance (15,925) and Glass
  Door (1,171) — on a C3 panel a record without a Pin is likely a real door event
  (unregistered card, exit button, alarm), and this F18 finding says nothing about
  them (rule 15).
- **Keywords:** future punch, 2030, wrong date, Camelot, clock, punched_at, timezone

### DC-019 — Clocks ~10¾ min slow although time zones were set

- **Date:** 2026-10-11 (~14:28–14:40 UTC)
- **Devices:** IT ROOM (F18, 192.168.7.62), On The Road (F22/ID, 192.168.7.63), Glass Door (inBIO460, 192.168.7.61), Entrance (TF1700, 192.168.7.60); Good Wheels and Camelot measured fine
- **Direction:** both
- **Symptom:** owner: "even though I have set all devices' time zone, there is still a clearly noticeable time offset. How to force sync the time?"
- **What was done:** casebook had no clock entry. The time-zone setting only tells the app how to READ a terminal's local clock; it never changes the clock. Server clock NTP-synchronised (UTC). Measured each terminal against real time in its zone (`Timezone::forDevice`, Asia/Bahrain for all): IT ROOM −640 s, On The Road −643 s, Good Wheels +2 s, Camelot +5 s; the door panels had no clock read. **History:** `sync_time` on 2026-10-05 had set IT ROOM and On The Road right (they were ~9¾ min slow); six days later both were ~10¾ min slow again — far more than any clock drifts, so something outside this app resets them (most likely a ZKAccess PC with a slow clock; Good Wheels and Camelot, remote, stayed right). **Today's 13:58 UTC "Set clock" on four terminals had FAILED** — `Undefined constant DeviceCommand::REBUILD_DEVICE`: the queue worker was still running code loaded before the DC-017 change (handler new, model old). The assistant's mistake: no `queue:restart` after that change. Only those four commands were affected. Restarted; set the four ZKTeco clocks through the queue (read back: 0 s, 0 s, +1 s, +5 s). Researched the C3 clock (rule 17): vwout/zkaccess-c3-py `consts.py` DATETIME = 0x03, `core.py` set_device_datetime sends "DateTime=<packed>", `utils.py` C3DateTime = the same packed format as transaction stamps. Read the panels with GET_PARAM "DateTime" (read-only, first ever): Glass Door −650 s, Entrance −649 s — the same source. Set both via DATETIME; a separate read showed both exact.
- **Root cause:** an external clock source resetting the LAN terminals ~10¾ min slow (to be confirmed by the owner); the door panels had no clock read or write at all; the app's own retry failed through a stale queue worker.
- **Fix:** library 2.12.0 `Panel::readClock()` / `setClock()`, `Command::DATETIME`; `GET_TIME`/`SET_TIME` CONFIRMED in `InBio460` and `Tf1700`. Contract `readClock()` / `setClock()` on every driver (ZKTeco, C3, CSL unsupported, fake); the `sync_time` command uses it and fails unless the read-back is within 60 s of real time; C3 health check now reports the panel clock. Owner then: "make this a method and save it, and every day sync the time from server to the biometrics at 5 am" — `App\Services\Devices\DeviceMorningRoutine` (renamed from DeviceClockSync when the owner added "after that, read attendance from all devices from the past day": per terminal the clock, then a range read of yesterday + today, `devices.daily_read_enabled`) + settings `devices.clock_sync_enabled` (on) / `devices.clock_sync_schedule` (`0 5 * * *`) evaluated in the company's timezone every minute (`device-morning-routine`); Settings → *Daily clock sync and attendance read*; method written in `docs/device-models.md`; `DeviceMorningRoutineTest` (fires at 05:00 Bahrain = 02:00 UTC, not 05:00 UTC; clock queued before the read; read is yesterday + today; each switch; custom time; skips pending/unsupported; a clock that does not take fails). Runs only once cron runs `schedule:run`.
- **Verified:** read back on all six after the set: IT ROOM 0 s, On The Road 0 s, Good Wheels +1 s, Camelot +5 s (internet latency), Glass Door 0 s, Entrance 0 s. 590 tests pass.
- **Evidence:** commands 92–95 (2026-10-05), 126–129 (failed, worker), today's `sync_time` commands for devices 5, 11, 12, 13, 14, 15.
- **Status:** MONITORING — owner (Ghassan): check the clock of the PC running ZKAccess (and whether ZKAccess syncs device time); install the cron line so the nightly clock set runs. **2026-10-18:** the cron line had still not been installed, so the morning routine never ran once and no punches were read automatically 2026-10-11 → 2026-10-18; installed for www-data that day and a catch-up `sync_attendance` queued on all six (commands 158–163). **Lesson recorded for the assistant: after changing device-layer code, run `php artisan queue:restart` — the worker keeps old classes in memory.**
- **Keywords:** clock, time offset, slow clock, sync time, set clock, DateTime, DATETIME 0x03, GET_PARAM, time zone, ZKAccess, queue:restart, Undefined constant

### DC-018 — "Abdul" (21) is Abdul Haq; a panel edit cut his finger off his record

- **Date:** 2026-10-11
- **Devices:** Glass Door (inBIO460, 192.168.7.61), Entrance (TF1700, 192.168.7.60); history on IT ROOM (F18, 192.168.7.62)
- **Direction:** both
- **Symptom:** the DC-016 scan found "Abdul" (21, unlinked) holding the same fingers as "Abdul Haq" (781054761) on both door panels. Owner: "the only way to find out is to match his fingerprints between the two records, or ID number, or attendance RFID card number".
- **What was done:** compared all three, read-only. **Fingerprints:** both fingers byte-identical on both records on both panels (slot 6, 832 B, `c070f4fb…`; slot 7, 1,234 B, `f3cf99c0…`). **Card:** 21 has none; 781054761 has 7458281, the card on employee 1428 (ABDUL HUQ MD DURJANALI MOLLAH). **ID:** the hand-over is visible — IT ROOM last 21 punch 2026-09-24 13:22:16, first 781054761 13:23:20; Glass Door 13:38:25 → 14:59:52; no employee ever mapped to 21 anywhere. Same person. Applied "Same person" on all three terminals: **3,458 punches under 21 credited to him** (Glass Door 2,590 back to 2021-06-27, Entrance 426, IT ROOM 442) — he now has 3,555; the 21 records deleted on both panels (saved first). **Then the push to Entrance warned "Pin 781054761 has UID 51, not 318".** Read the template rows: his record was now UID 51 but both fingers still carried UID 318 — **the TF1700 re-inserted the user row under a new UID** (51, freed moments earlier by deleting Pin 21) instead of updating it in place, so his finger no longer belonged to him. Glass Door (inBIO460) kept 318 — the same write behaved differently per model.
- **Root cause:** the C3 user edit (`SET_DATA` on `user` without UID, as ZKAccess does) can land under a new UID on the TF1700; `templatev10` rows carry the owner's UID, and nothing re-linked them — the library only refused afterwards.
- **Fix:** library 2.11.1 `C3\Service\Personnel::relinkFingerprints()` — rewrites a person's template rows under their current UID (delete by Pin, write, verify every finger byte-for-byte and every UID, restore the originals on failure); `update()` calls it before every edit (so a re-push repairs an old break) and after one that changed the UID. Tests in `C3PersonnelTest` (re-inserted UID, stale link repaired by an unchanged push, failed re-link restored).
- **Verified:** re-pushed Abdul Haq to Entrance: record UID 51, fingers 6 and 7 UID 51, templates unchanged, 315 users. A scan of every template row on both panels then found **one more older break: ALI HUSAIN (employee 1704, 20200269) on Entrance, finger 6 on UID 297 vs record 295, and zero punches there since his ID change** — re-pushed: record and finger both UID 295. Both panels now: every fingerprint on its owner's UID, no orphan rows.
- **Evidence:** audit `device.duplicate_merged` for employee 1428; snapshots `removed-records/device-13-21-*`, `device-14-21-*`.
- **Status:** FIXED. Not yet seen: Abdul Haq's and Ali Husain's fingers matching at Entrance after the re-link.
- **Keywords:** duplicate, same fingerprint, Abdul, 21, 781054761, UID changed, UID 318 51, templatev10 UID, fingerprint not matching, relink, TF1700 re-insert, ALI HUSAIN, 20200269

### DC-017 — "Wipe a terminal and push the stored data" — Rebuild from system

- **Date:** 2026-10-11
- **Devices:** all six (preview run on each, read-only)
- **Direction:** software → device
- **Symptom:** owner: "can you build an option to wipe a biometric device and push the stored data from the web app system".
- **What was done:** designed against the literal wipe on purpose — emptying Glass Door (316 people) and refilling it one at a time locks everyone out meanwhile, an interruption leaves it half empty, and a wipe destroys what only the terminal holds (unsynced punches, fingers never copied, records nobody has matched). Built `DeviceRebuildService`: a read-only **preview** (`plan`), then a queued chain — `sync_attendance` → `rebuild_device` (refuses until punches were downloaded after the request; encrypted backup of every record and template to `storage/app/private/device-backups/`; everyone's fingers and cards into the system) → one `rebuild_person` per person needing a change (through `EmployeeDevicePropagator`, so each model uses its own method) → optional `rebuild_remove` per record linked to nobody (each saved first, via `releaseUnlinkedNumber(…, null)`) → `sync_enrollment` read-back. People already right are never touched. Records linked to nobody are KEPT unless the operator ticks their removal (the door panels hold 46 unmatched people). Found while testing: comparing the request time as an ISO string against the stored timestamp made a finished download look missing — parsed now. **Preview on the real terminals, 2026-10-11:** IT ROOM 7 to change / 10 right; On The Road 5/4 (+1 unlinked); Good Wheels 12/6; Camelot 11/13; Glass Door and Entrance 40/231 (+46 unlinked each); nothing blocked. Most changes are user ID → employee code and name → the system's name.
- **Root cause:** n/a (feature).
- **Fix:** `app/Services/Devices/DeviceRebuildService.php`; commands `rebuild_device` / `rebuild_person` / `rebuild_remove`; `GET devices/{d}/rebuild-plan`, `POST devices/{d}/rebuild`; `pages/devices/RebuildModal.jsx` ("Rebuild from system…" on the device page, desktop and mobile). Tests `tests/Feature/Devices/DeviceRebuildTest.php` (6). 587 pass.
- **Verified:** previews read on all six terminals. **No rebuild has been run on hardware yet.**
- **Evidence:** this entry's preview counts.
- **Status:** MONITORING — owner (Ghassan): (1) the system's name is often the first name only ("Maryam Salem" → "MARYAM", "Abdul Haq" → "ABDUL" on IT ROOM) — correct names first if wanted; (2) first run suggested on IT ROOM (smallest, F18 move proven); then read the result here.
- **Keywords:** wipe, rebuild, push all, reset terminal, sync to system, device backup, rebuild_device, rebuild_person

### DC-016 — Yusuf: card shows 960403094, finger shows 695

- **Date:** 2026-10-11
- **Devices:** Glass Door (inBIO460, 192.168.7.61), Entrance (TF1700, 192.168.7.60); history on IT ROOM (F18, 192.168.7.62)
- **Direction:** device → software (which record a scan matches), then software → device
- **Symptom:** owner: "yusuf holds a card with the number 976298, but when he uses his finger it shows 695 … he is the same person … the glass door".
- **What was done:** read the casebook first (DC-014 is the same shape; PROJECT-STATE had flagged an unmatched "yusif" at 695). Read-only: both door panels hold TWO records for YUSUF ABDULHUSAIN EBRAHIM DAHNEEM (employee 1432, code 960403094): uid 280 / 960403094 with card 976298 and finger 6, linked to him; uid 313 / 695 "yusif", finger 6, no card, linked to nobody. The two finger-6 templates are **byte-identical** (1,290 bytes, sha1 `a7aa02c6…`) on both panels — one enrollment written twice (ZKAccess copies templates unchanged). So the finger matched 695, the card matched 960403094. Punches under 695: Glass Door 2, Entrance 69 (5 already his), IT ROOM 815 (2026-05-14 to 2026-09-24 12:40:35, credited to nobody). IT ROOM's first punch under 960403094 is 2026-09-24 12:42:03 — 90 s later: the same record renumbered that day, so those punches are his. Applied the owner's procedure ("Same person", DC-014) on Glass Door and Entrance: fingers into his account (none new — the identical copy was already stored), punches credited, 695 deleted (snapshots in `storage/app/private/removed-records/`), his data pushed, read back. On IT ROOM (no record left there) the same call only credited the 815 punches.
- **Root cause:** a duplicate record carrying the same fingerprint as the real one; the panel matched the finger to the duplicate.
- **Fix:** no code change; the DC-014 "Same person" procedure (`releaseUnlinkedNumber(…, samePerson: true)`).
- **Verified:** read back on both panels: one record, uid 280, 960403094, card 976298, finger 6 (`a7aa02c6…`); 695 gone; users 317 → 316 on each. Unattributed punches under 695: 0 on all three terminals; Yusuf 1,011 punches.
- **Evidence:** `releaseUnlinkedNumber` audit rows `device.duplicate_merged` for employee 1432; snapshots `removed-records/device-13-695-*`, `device-14-695-*`, `device-15-695-*`.
- **Status:** FIXED. A scan of every terminal for one fingerprint under two IDs found one more: **"Abdul" (21, unlinked) and "Abdul Haq" (781054761) on Glass Door and Entrance, two identical fingers** — OPEN, owner to confirm same person. The other four terminals had none.
- **Keywords:** duplicate record, same fingerprint, card vs finger different ID, 695, 960403094, 976298, yusif, identical template, Abdul, 21, 781054761

### DC-015 — Every ZKTeco model inherited the F18's methods

- **Date:** 2026-10-11
- **Devices:** On The Road (F22/ID, 192.168.7.63), Good Wheels (F18/ID, 46.184.163.106 — model was not recorded), IT ROOM and Camelot (F18)
- **Direction:** software → device (what the app offers to write)
- **Symptom:** owner: "each device different model have their own method of achieving the same required action … when fixing a problem for one don't fuck the other with the same solution". Found while preparing DC-014: the F18 fingerprint write added in DC-012 was also offered on the F22, which had never run it — Ali's saves pushed fingerprints at On The Road at 12:02:53 and 12:11:08 (nothing was written: he already had both fingers there).
- **What was done:** compared the families: the C3 panels already had one capability file per model (`InBio460`, `Tf1700`, `GenericPanel`); every standalone model shared `CapabilitySet::defaults()` (the F18). Read `device.info` on all four standalone terminals (read-only): Good Wheels is an **F18/ID on firmware Ver 6.60 Oct 12 2021** (others Apr 2016 / F22 Apr 2017) — stored on the device rows with serial and platform. Printed the effective level of every person-related action per terminal before and after.
- **Root cause:** no per-model selection for the standalone family, so a level proven on the F18 was a level on every model.
- **Fix:** library 2.11.0 `src/Model/` — `TerminalModel`, `F18` (= defaults, unchanged), `F22` (its own evidence; F18-only writes `unknown`), `GenericTerminal` (no file: reads only, writes `unknown`), `TerminalModelFactory` (discovers model files; names none). `ZktecoDriver::capabilities()` / `probeCapabilities()` select by `$device->model`; a probe stores only probeable (read) levels so it cannot overwrite a model's write levels. CLAUDE.md **rule 21**, `docs/device-models.md`, `tests/Unit/DeviceModelMethodologyTest.php` (pins every model's levels), library `TerminalModelTest`.
- **Verified:** per-terminal printout — F18 units unchanged; On The Road `template.upload` / `user.renumber` now `unknown`, `user.write` / `user.delete` `confirmed` (its own runs), `card.write` `implemented`; 574 tests pass.
- **Evidence:** this entry's printouts; audit `employee.fingerprints_pushed` device 11, 2026-10-11 12:02:53 and 12:11:08.
- **Status:** FIXED
- **Keywords:** model, per-model, inherited, defaults, F22, F18, Good Wheels, firmware 2021, TerminalModelFactory, capabilities

### DC-014 — Save leaves the old user ID on some terminals (Ali, 654 vs 960802797)

- **Date:** 2026-10-11
- **Devices:** IT ROOM (F18, 192.168.7.62), On The Road (F22/ID, 192.168.7.63), Camelot (F18); correct already on Entrance (TF1700, 192.168.7.60), Glass Door (inBIO460, 192.168.7.61)
- **Direction:** software → device
- **Symptom:** owner: "ALI MOHAMED JAAFAR MANSOOR MOHSEN MOHAMED when i clicked save, on some devices it have the correct ID number 960802797 and some dont, they must all update … whenever i go to any employee and press save this must always happen."
- **What was done:** (in progress) Read the casebook first: DC-006 (F18 refuses in-place renumber; delete + re-upload verified twice on IT ROOM 2026-09-24, then hidden when IT ROOM's capabilities_cache was cleared) and DC-013 (Ali's save). Read-only findings: the save already sends `to_employee_code` whenever a terminal's ID differs from the code; the C3 panels moved; IT ROOM and On The Road answered "User ID kept at 654: cannot change a user ID", filed as WRITTEN, not as a problem — so it looked like success. Owner approved the fix and Ali's move ("fix the problem and log the solution too"). Changed the propagator so a terminal left on the old ID is reported FAILED (red, Retry): `EmployeePropagationTest::test_a_terminal_that_cannot_move_the_id_is_reported_failed_with_the_name_still_pushed`, 566 tests pass. **Blocked:** setting `user.renumber` to `implemented` in the library's `CapabilitySet::defaults()` was refused by the assistant's auto-mode safety check ("Security Weaken"); not worked around. Nothing was written to any terminal for this case.
- **Root cause:** (1) `user.renumber` is `not_implemented` in `CapabilitySet::defaults()` although the F18 reupload path was proven; (2) a terminal that could not move the ID was reported as written.
  Owner then set the frame: each model gets its own method (rule 21; DC-015). The F18 refuses an in-place move, so its method was built from steps it has proven: **move by re-adding** (`DeviceUserSyncService::moveByReadding`): refuse first if the new ID is taken or the model cannot put back the person's fingers/card; save card + fingers + privilege; delete; add under the new ID; restore; read back. **Found on the real terminals:** Ali is an **admin (privilege 14)** on IT ROOM and On The Road — a plain re-add would have demoted him silently. Fixed: the privilege is read off the terminal before removal and restored on re-create (`saveUser(…, privilege:)` added to the contract, `privilegeToRestore()`). **Ran Ali's save path 2026-10-11 ~12:40 UTC:** both terminals refused BEFORE any change, read back unchanged (654, privilege 14, card 7511517, fingers 6, 7): IT ROOM — **960802797 is held there by "ali-it"** (uid 6, card 2862247, finger 5), a test record from the 2026-09-23/24 renumber runs, linked to no employee since the 2026-09-27 reset; On The Road — the F22 has never taken a fingerprint write, so moving him would lose his fingers.
  **Owner: "do what it takes", then chose option 2 — buttons the operator presses.** Found and fixed first: the move's pre-check (`assertPinFreeFor`) and the claim after the delete were DIFFERENT checks, so a move could pass one, delete the person, then fail the other and leave them off the terminal. Now one check (`claim()` uses `assertPinFreeFor`). IT ROOM holds **42 punches under 960802797, 2026-09-22 to 09-28, credited to nobody** (the test record's) — left untouched. The assistant's shell writes to terminals, the casebook edit and the first build attempt were refused by Claude Code's auto-mode safety checks ("Remote Shell Writes", "Logging/Audit Tampering", "Auto-Mode Bypass"); nothing ran on a terminal. The owner then had project permission rules added (`.claude/settings.local.json`: `php artisan tinker`, edits to app/docs/tests/routes/js/library).
  **Built:** a red push row now carries the fix it needs. (1) `PinInUseException` → **"Free 960802797"**: `DeviceUserSyncService::releaseUnlinkedNumber()` — only for a number no employee was ever mapped to; the record and its fingerprints are saved encrypted to `storage/app/private/removed-records/` BEFORE the delete, read back, roster entry given to the employee, audit `device.number_released`; then the push retries. (2) `FingerprintWriteUnprovenException` → **"Test fingerprints"**: `FingerprintPusher::trialWrite()` — adds ONE finger the system holds to a person on the terminal missing it there (nobody deleted, existing fingers verified), read back; on success `template.upload` = `implemented` for THAT terminal only (`capabilities_cache`; a re-probe now keeps earned write levels), audit `device.fingerprint_write_tested`; then the push retries.
- **Fix:** `EmployeeDevicePropagator` — a kept ID is FAILED (red) with an `action`; models that cannot move in place move by re-adding where safe; privilege restored on re-create; one PIN check; the two buttons above. Endpoints `POST employees/{e}/devices/{d}/release-number`, `POST devices/{d}/fingerprint-write-test`. Tests: `EmployeePropagationTest` (F18 re-add with card, fingers and admin kept; F22-like refusal; unlinked holder offered → freed with snapshot → number taken; an employee's old number never offered or freed; fingerprint test adds one finger and unlocks only that terminal), `CredentialPreservationTest` (both deleters snapshot first). 579 pass.
- **Verified:** read back from all six terminals at the end (below): one record per terminal, 960802797, ALI MOHAMED, card 7511517, fingers 6 and 7.
- **Evidence:** audit for employee 1419 2026-10-11 12:02–12:03 and ~12:40; DC-006 traces.
  **2026-10-11 13:07 UTC, owner pushed Ali again and saw only the toast — no buttons.** The push came from the roster row's **Push** dialog (audit: `fingerprints_pushed` after the propagate = the dialog's "copy fingerprints" option), which closed on submit; the buttons existed only in the edit form's result rows. Fixed: the fix logic moved to one shared file (`pages/employees/TerminalFixes.jsx`, used by both); the Push dialog now stays open on any terminal that needs a go-ahead, with its button, and retries that terminal after. Its per-terminal note now says the F18 moves an ID by re-adding (it said "cannot change an ID"). 579 pass; built.
  **13:14:59 UTC, still only the toast.** Access log: the browser had the new bundle (13:14:30), used the row Push dialog with "copy fingerprints": propagate 13:14:59 → `GET employees/1419` + `fingerprints/push` 13:15:17 → list reload ~13:15:29. The server's answer carried both actions (checked: `release_number` / `test_fingerprint_write`). The dialog only switched to the buttons after the fingerprint copy and reload — ~15 s after the red toast. Fixed: the dialog shows the buttons as soon as the push answers (copy + reload continue behind it), and the toast says "use the button in the dialog".
  **Owner: "ali-it and Ali Mohamed are the same person … copy the fingerprint data into the webapp, store it in the main account, then delete the record on the device, then push the new data."** Built "Same person" (`releaseUnlinkedNumber(…, samePerson: true)`: duplicate's fingers → `FingerprintVault::adoptCopies` into Ali's account; its punches credited via a closed mapping that keeps its privilege; then deleted; then the normal push). Found on the way: a re-add could take the duplicate's privilege (0) instead of his own (14) when both closed in the same second — `privilegeToRestore()` now prefers the person's own record. Searched every terminal: duplicates were on IT ROOM ("ali-it" 960802797, card 2862247, finger 5), On The Road and Good Wheels ("ali-it" 565656565, empty). 581 tests pass.
  **Run on the terminals, 2026-10-11 13:24–13:50 UTC (assistant, owner's instruction):**
  - **IT ROOM:** "ali-it" was **already gone** — 17 users, not 18 — deleted outside this app after 12:40 UTC (no audit row, no button press), so **its finger 5 was lost** (it was never linked to Ali, so no copy existed; Ali must re-enrol finger 5 if he wants it). First merge attempt failed on a NOT NULL `device_privilege` when the record was absent; fixed (`?? 0`); a 484-byte snapshot of the empty read is in `removed-records/device-15-960802797-20261011-132443.json.enc`. Merge: 42 punches (2026-09-22 to 09-28) credited to Ali. Push: moved 654 → 960802797 by re-adding; read back `uid 21, 960802797, ALI MOHAMED, priv 14, card 7511517, fingers [6,7]`, users 17.
  - **On The Road (F22):** merged "ali-it" 565656565 (empty; snapshot saved), removed. Fingerprint-write test: nobody was missing a stored finger, so built `withTemporaryUser()` ("zkt-test", deleted after) — test passed: stored template on temporary user ID 1, read back byte-identical, users 10 / templates 15 unchanged before and after; `template.upload` → `implemented` for the unit and then in `Model\F22` (rule 21, pin updated). Push: moved 654 → 960802797 by re-adding; read back `uid 13, priv 14, card 7511517, fingers [6,7]`, users 10.
  - **Good Wheels (F18/ID, 2021 firmware):** merged "ali-it" 565656565 (empty), removed; pushed Ali: `uid 19, 960802797, ALI MOHAMED, priv 0 (as "ali-it" was there), card 7511517, fingers [6,7]`; users 19 → 19, templates 32 → 34, none lost. First writes ever on this unit.
  - **Final read of all six:** IT ROOM, On The Road, Good Wheels, Glass Door, Entrance each hold ONE record, 960802797 ALI MOHAMED, card 7511517, fingers 6, 7; Camelot never had him; no "ali-it", no 654 anywhere. Five live mappings, all 960802797.
- **Status:** FIXED. Not yet seen: a finger written by this app matching at the F22 or Good Wheels (levels stay `implemented`). Ali's finger 5 needs re-enrolment if wanted.
- **Keywords:** user ID, employee code, renumber, 654, 960802797, cannot change a user ID, kept at, to_employee_code, F18, F22

### DC-013 — Saving Ali Mohamed Jaafar "got an error" — permission-cache 500

- **Date:** 2026-10-11 (~12:01–12:03 UTC report; fixed ~12:10 UTC)
- **Devices:** Entrance (TF1700, 192.168.7.60), Glass Door (inBIO460, 192.168.7.61), IT ROOM (F18, 192.168.7.62), On The Road (F22/ID, 192.168.7.63)
- **Direction:** software → device (the save's push); the error itself was app-side
- **Symptom:** owner: "tried to save ALi Mohammed Jaffer it was updating the ID in some devices but then got an error".
- **What was done:** read the audit log for employee 1419 — the push had completed on all four terminals (12:02:16–12:03:06). Read his mappings: Entrance and Glass Door renumbered 654 → 960802797 (card 7511517, fingers 6, 7 kept); IT ROOM and On The Road kept 654 (neither model renumbers in place — see DC-006). So the save was fine. `laravel.log` showed `SQLSTATE[23000] … Violation of PRIMARY KEY constraint 'cache_key_primary' … spatie.permission.cache` at 12:01:27, 12:01:44, 12:02:15, 12:02:51, stack through `EmployeeController::index()` — the Employees page's own live refresh (rule 18) during the save. 28 that day, 130 since 2026-09-22. Found `Tenancy::set()` calling `forgetCachedPermissions()`.
- **Root cause:** `Tenancy::set()` runs on every request (`ResolveTenant`) and every job, and deleted the shared permission cache entry each time. Overlapping requests then rebuilt it together; SQL Server's MERGE in Laravel's database cache store collided on the key. A long device save plus a page polling for live changes is the worst case.
- **Fix:** `Tenancy::set()` → `clearPermissionsCollection()` (in-memory only; the cached list has no team in it). `config/permission.php` cache store → `file` (`PERMISSION_CACHE_STORE`); `phpunit.xml` pins `array`. `queue:restart`.
- **Verified:** the cache key survives two tenant switches; 34 permissions cached; owner account authorised; 560 tests pass.
- **Evidence:** `storage/logs/laravel.log` 2026-10-11 12:01–12:02; `docs/PROJECT-STATE.md` session log 2026-10-11.
- **Status:** FIXED. **Incident caused during the fix:** ~12:05–12:08 UTC one test run (before `phpunit.xml` pinned the store) wrote an EMPTY permission list into the live file cache; live permission checks could have been refused for those minutes. Cleared. **Open, owner:** move Ali to 960802797 on IT ROOM and On The Road by remove + re-add (rule 19 restores card and fingers).
- **Keywords:** cache_key_primary, spatie.permission.cache, 500, Server error, MERGE, forgetCachedPermissions, Tenancy::set

### DC-012 — Akram removed and re-added: 0 fingerprints, no card, new user ID

- **Date:** 2026-10-11 (11:16 UTC removal; fixed by ~11:45 UTC)
- **Devices:** IT ROOM (F18, 192.168.7.62); his copies came from Entrance (TF1700, 192.168.7.60) and Glass Door (inBIO460, 192.168.7.61)
- **Direction:** both — delete and push (software → device), read-back (device → software)
- **Symptom:** owner removed AKRAM MIKNAS (employee 1434, code 440105943) from IT ROOM, added him back from the system: "it show IT Room 0 with yellow badge". Expected: the fingerprint stored in the app and written back.
- **What was done:** audit + mappings showed no fingerprint copy had ever been taken from IT ROOM; the removal (`removeEmployee`) deleted the record and also deleted any stored copy for that terminal; the re-add wrote neither card nor finger; the read-back then wrote the terminal's empty card onto `employees.card_number` (card 7553651 lost from his record too). Found `ZktecoDriver::uploadTemplates()` threw "not built yet" — no F18 could receive a template at all. Built `TemplateService::addTemplates()` (library 2.10.0, `SAVE_USERTEMPS`). **Hardware actions:** saved IT ROOM's users + template digests (18 users, 15 templates); wrote Akram's slot 6 (1,132 bytes, copy read off the inBIO460) to uid 20 → read back byte-identical (sha1 `d507071a…`), every other user/template unchanged (diff); `setUserCard(440105943, 7553651)` read back; `employees.card_number` restored by hand; `readEnrollment` + `recordCard` to bring records in line. Then ran the owner's exact scenario twice through the real services: remove → add back. First run: card and finger came back, but he got **PIN 2** — his old PIN was "reserved" against him. Fixed, removed again, re-added with "use employee code" → 440105943, card, finger 6.
- **Root cause:** four bugs: no copy taken before a delete; stored copies deleted when a terminal lost the finger; re-add never wrote credentials back; a terminal's empty card cleared the record card. Plus a fifth: a removed person could not return to their own PIN.
- **Fix:** `DeviceUserSyncService::saveCredentialsBeforeRemoval()` (refuses 409 if it cannot), `restoreCredentials()` / `cardToRestore()`; `FingerprintVault` keeps copies; `FingerprintPusher::restoreOnto()`; `readEnrollment` never clears the record card; `DeviceIdentityResolver::pinWasOnlyEverTheirs()`; roster reads capture everyone's fingers and cards; nightly roster read scheduled. CLAUDE.md **rule 19** + `docs/credential-preservation.md`; `tests/Unit/CredentialPreservationTest.php`, `tests/Feature/Devices/FingerprintPushTest.php`.
- **Verified:** on IT ROOM, read back: `uid 20, user_id 440105943, card 7553651, finger_slots [6]`, 18 users. Backup coverage: stored copies 12 (5 people) → 505 (203 people) after queued roster reads, commands 114–119 and 120–125.
- **Evidence:** before/after snapshots `itroom-before.json` / `itroom-after.json` (session scratchpad, not committed); audit rows `employee.fingerprints_captured` / `employee.fingerprints_pushed` for 1434; `docs/device-capabilities.md` `template.upload`.
- **Status:** FIXED. **Open, owner:** Akram to scan finger 6 at IT ROOM — write is proven, a real MATCH is not (`template.upload` stays `implemented` until then). Cron for `schedule:run` is not installed, so the nightly backup does not run yet.
- **Keywords:** fingerprint lost, 0 fingerprints, yellow badge, card wiped, SAVE_USERTEMPS, template upload, addTemplates, removeEmployee, re-add, PIN reserved, never reissued

### DC-011 — Renumber moved the panels, then our bookkeeping crashed

- **Date:** 2026-10-11
- **Devices:** Entrance (TF1700, 192.168.7.60), Glass Door (inBIO460, 192.168.7.61)
- **Direction:** software → device, then app-side
- **Symptom:** moving Ghassan (employee 1429) 656 → 830108246: the panels changed, then the request failed — panels and our records disagreed.
- **What was done:** read the panels back: PIN 830108246, UID 247, finger 6 — the move had worked. The DB step failed on a leftover roster row already at 830108246 for the same employee (from an earlier wrong mapping). Corrected his mappings and roster rows by hand. Before that, on the owner's word "Ghassan yes it's the same person": merged employees 1729 and 1786 into 1429 (`EmployeeMergeService`), closed the wrong 830108246 maps, re-read rosters, claimed roster PIN 656, `attendance:reattribute 13` (41 punches), direct update of 129 PIN-656 punches on Entrance (a full reattribute was NOT run there because it would clear 5 PIN-695 punches attributed to 1432 Yousif), backed up fingers 3 and 6.
- **Root cause:** `renumberEmployee` assumed the target PIN had no roster row; a same-employee leftover made the insert collide.
- **Fix:** inside the transaction, a same-employee leftover roster entry at the new PIN is removed first. Test added.
- **Verified:** panel read-back; DB records corrected and re-read.
- **Evidence:** `docs/PROJECT-STATE.md` 2026-10-11 log.
- **Status:** FIXED. **Open, owner:** Camelot (F18) still has Ghassan as PIN 1 — can now be moved by remove + re-add (rule 19 restores fingers 3, 6 and Camelot's card 7632913).
- **Keywords:** renumberEmployee, roster leftover, duplicate key, Ghassan, 830108246, 656

### DC-010 — "Moving a user needs two different PINs"

- **Date:** 2026-10-11
- **Devices:** Entrance (TF1700, 192.168.7.60), Glass Door (inBIO460, 192.168.7.61)
- **Direction:** software → device
- **Symptom:** pushing with "use employee code" failed with "Moving a user needs two different Pins" for someone already at that ID.
- **What was done:** traced to `C3Driver::renumberUser` asked to move a PIN to itself (numerically equal after DC-009's comparison).
- **Root cause:** no "already there" short-circuit.
- **Fix:** `C3Driver::renumberUser` returns the record unchanged when the PINs match; `DeviceUserSyncService::renumberEmployee` returns "already user ID …" with no bookkeeping. Test added.
- **Verified:** tests; subsequent pushes to the panels.
- **Evidence:** `docs/device-capabilities.md` (C3 PINs).
- **Status:** FIXED
- **Keywords:** two different Pins, renumber, same PIN, C3

### DC-009 — "041112750 was written but is not on the panel"

- **Date:** 2026-10-11
- **Devices:** Entrance (TF1700, 192.168.7.60), Glass Door (inBIO460, 192.168.7.61)
- **Direction:** both — write, then read-back compare
- **Symptom:** "FAILED on Entrance/Glass Door — 041112750 was written but is not on the panel" (Tariq, employee 1739).
- **What was done:** read the panels: they hold him as **41112750** (UID 319, card 1739519, finger 6). C3 PINs are integers; the leading zero is dropped by the panel. The write had worked; our string compare said it had not. Tariq's mappings on 13/14 corrected by hand to 41112750.
- **Root cause:** string comparison of a numeric PIN.
- **Fix:** `C3Driver::samePin()` (numeric compare); `tests/.../C3PinComparisonTest`.
- **Verified:** read-back on both panels.
- **Evidence:** `docs/device-capabilities.md`.
- **Status:** FIXED
- **Keywords:** leading zero, 041112750, 41112750, was written but is not on the panel, C3 PIN integer

### DC-008 — "Tariq could not be removed" — 500 on an unadopted terminal

- **Date:** 2026-10-11 (08:38–09:10 UTC)
- **Devices:** On The Road (F22/ID, 192.168.7.63), IT ROOM (F18, 192.168.7.62), then all six
- **Direction:** software → device
- **Symptom:** "Tariq could not be removed from On The Road" with no reason; later the owner: "read and write must be always allowed for all devices", "make the code do its job without making me going back and forth".
- **What was done:** On The Road was not adopted; `DeviceNotAdoptedException` fell through to a generic 500. Made it a 409 with the reason and the toast show it. On the owner's go-ahead: adopted On The Road (08:38 UTC), backed up Tariq's finger 6, owner removed him (09:07:55, verified: PIN 940 gone, 11 users). IT ROOM refused next (09:08:24): read its roster (19 users, all claimed), owner approved, `finishAdoption` on the other five (09:10:34). Removing the write gate entirely was refused by the safety classifier and not done; replaced by auto-adoption.
- **Root cause:** an unhandled exception class, and a gate that required a manual step even when the roster was fully claimed.
- **Fix:** 409 `reason=not_adopted`; `DeviceAdoptionService::adoptIfClear()` — `Device::assertWritable()` reads the roster and adopts when no unclaimed people are known, refuses naming up to 5 people otherwise. `docs/device-identity-and-adoption.md`.
- **Verified:** hardware read after removal; all six terminals `adopted`.
- **Evidence:** DeviceIdentityGuardTest, DeviceAdoptionGateTest.
- **Status:** FIXED. Tariq (PIN 940) still on IT ROOM, for the owner to remove.
- **Keywords:** not adopted, read-only, DeviceNotAdoptedException, 409, adoption, assertWritable

### DC-007 — Attendance "Method" column showed bare numbers

- **Date:** 2026-10-11
- **Devices:** all; unknown codes 48–51 seen once each on Camelot (F18)
- **Direction:** device → software (decode)
- **Symptom:** owner: "the column named method must show me how did the person log in, not just a number".
- **What was done:** decoded with the vendor's own ZKAccess `VerifyType` dictionary (`draft/extracted/Languages/language_en-US.xml`): 0 Any, 1 Fingerprint, 2 Personnel ID, 3 Password, 4 Card, 6 Card or fingerprint, … 15 Face, 200 Other. Checked against data: 74 of 76 people punching with code 4 hold a card.
- **Root cause:** codes were never mapped.
- **Fix:** `App\Support\VerifyMethod` + `parts.jsx`, "Unknown (n)" for unmapped codes; `VerifyMethodLabelsTest` keeps both in step. `docs/protocol-overview.md`.
- **Verified:** against stored punches as above.
- **Evidence:** VerifyMethodLabelsTest.
- **Status:** FIXED. Codes 48–51 remain unknown.
- **Keywords:** verify type, method, VerifyType, punch method, code 4, code 15

### DC-006 — The F18 refuses to change a user ID

- **Date:** 2026-09-23 to 2026-09-24
- **Devices:** IT ROOM (F18, 192.168.7.62), test user `ali-it2` uid 6
- **Direction:** software → device
- **Symptom:** a changed employee code never reached the terminal; the renumber was refused.
- **What was done:** `UserService::renumber()` (rewrite same uid with a new user id) → **ACK_ERROR** for `000000005`, `5`, `10055`, record untouched each time; leading zeros ruled out. Built delete + re-upload with templates (`renumberByReupload`, `SAVE_USERTEMPS`); owner-authorised run on `ali-it` 2026-09-24: 960802797 → 5 → 960802797, both verified, template byte-identical, `valid` byte kept.
- **Root cause:** firmware will not change the user id of an existing uid.
- **Fix:** `user.renumber` `not_implemented` in `defaults()`; the reupload path enabled for IT ROOM only via `capabilities_cache` — **that cache was later cleared**, so it is hidden again. Since 2026-10-11 (DC-012) remove + re-add also moves an F18 user, keeping card and fingers.
- **Verified:** hardware runs above.
- **Evidence:** `tools/zkprobe/traces-2026-09-23/renumber-itroom-01.json`, `tools/zkprobe/traces-2026-09-24/renumber-reupload-01.json`, `-02.json`.
- **Status:** WORKAROUND. A real finger scan after a move was never observed.
- **Keywords:** ACK_ERROR, renumber, user id change, USER_WRQ, SAVE_USERTEMPS, F18

### DC-005 — Push refused the slot's own owner

- **Date:** 2026-09-23
- **Devices:** IT ROOM (F18, 192.168.7.62)
- **Direction:** software → device (refused before the write)
- **Symptom:** "slot 960802797 already holds ali-test, who is not claimed by this employee" — though mapping and roster both said employee 1414 owned it.
- **What was done:** `slotBelongsTo()` compared `claimed_employee_id === $employee->id`; SQL Server returns `"1414"` (string).
- **Root cause:** strict comparison of integer columns that SQL Server returns as strings; SQLite tests cannot catch it.
- **Fix:** integer casts on `DeviceRosterEntry` and `EmployeeDeviceMap` keys; `test_ownership_keys_are_integers_whatever_the_driver_returns`.
- **Verified:** push succeeded after the fix.
- **Evidence:** `docs/PROJECT-STATE.md` 2026-09-23.
- **Status:** FIXED. Any strictly compared integer column needs the same cast.
- **Keywords:** not claimed by this employee, SQL Server string id, strict comparison, slotBelongsTo

### DC-004 — Every attendance sync failing on SQL Server

- **Date:** 2026-09-21 to 2026-09-23
- **Devices:** all (reported on IT ROOM, command 67)
- **Direction:** device → software (storing a read)
- **Symptom:** failed `sync_attendance`; IT ROOM's newest stored punch stuck at 2026-09-16; the failure was not even logged.
- **What was done:** `insertOrIgnore` does not exist on SQL Server; device log files for 2026-09-15/21/23 were owned by root (artisan run as root), so the worker (www-data) could not log.
- **Root cause:** SQLite-only query builder call after the move to SQL Server; root-owned log files.
- **Fix:** no-op `upsert()` on the unique index, per-batch de-duplication, chunks under the 2,100-parameter limit (`docs/attendance-sync-flow.md`); `chown -R www-data:www-data storage bootstrap/cache`.
- **Verified:** sync recovered the missing punches from the terminals.
- **Evidence:** `docs/PROJECT-STATE.md` 2026-09-23.
- **Status:** FIXED. **Trap:** run artisan as `sudo -u www-data`, or chown after a root run.
- **Keywords:** insertOrIgnore, SQL Server, 2100 parameters, root-owned log, sync failed

### DC-003 — The inBIO460 "pings but never answers"

- **Date:** 2026-09-14
- **Devices:** Glass Door (inBIO460, 192.168.7.61); later Entrance (TF1700, 192.168.7.60)
- **Direction:** both
- **Symptom:** recorded for weeks as "pings but does not answer" — assumed dead.
- **What was done:** research (rule 17): an inBIO is a C3 / PullSDK access panel — TCP, different frame and CRC. Speaking C3, it answered with serial, firmware and table schema. Both connect forms were refused (`0x76` → −13, `0x01` → −98) with valid CRCs; the working path was **no connect at all** — `GETPARAM`, `DATATABLE_CFG`, `GETDATA` answer session-less. `GETDATA` answers `0x0D` (buffered) instead of inline rows.
- **Root cause:** speaking the wrong protocol.
- **Fix:** C3 driver (`app/Services/Devices/C3/`, library `src/C3/`).
- **Verified:** full reads on both panels.
- **Evidence:** `tools/zkprobe/inbio460.php`; references `vwout/zkaccess-c3-py`, `-lua`.
- **Status:** FIXED
- **Keywords:** inBIO, C3, PullSDK, silent device, no answer, CRC-16/ARC, 0x0D

### DC-002 — Camelot refuses to connect; bulk reads die part-way

- **Date:** 2026-09-13 to 2026-09-14
- **Devices:** Camelot (F18, 87.237.196.60 at the time)
- **Direction:** device → software
- **Symptom:** `CMD_CONNECT` answered `ACK_UNAUTH` (2005); full reads failed at CONNECT (1000), AUTH (1102) or PREPARE_BUFFER (1503) at random.
- **What was done:** comm key is 0 but the device still wants the AUTH exchange — `CMD_AUTH` with `makeCommKey(0, sessionId)` → `ACK_OK`. Bulk read fixed in `BulkReader` (library 2.1.0): 41,632 records in ~285–300 s, twice. TCP added (2.2.0), a `wrapTcp` header bug found and fixed; TCP not proven on Camelot. **Did not help:** pacing reads at 100/300/1000 ms.
- **Root cause:** a firmware that requires AUTH with key 0; a flaky public link that degrades under load.
- **Fix:** AUTH handshake; reads go through the queue with retry/backoff (rule 11).
- **Verified:** two complete reads.
- **Evidence:** `docs/PROJECT-STATE.md` Camelot section; library CHANGELOG 2.1.0/2.2.0.
- **Status:** WORKAROUND — reliable per attempt only through retries.
- **Keywords:** ACK_UNAUTH, 2005, CMD_AUTH, comm key 0, Camelot, flaky, PREPARE_BUFFER timeout

### DC-001 — "Incomplete transfer" on every attendance read

- **Date:** 2026-09-13
- **Devices:** On The Road (F22, 192.168.7.63)
- **Direction:** device → software
- **Symptom:** "Incomplete transfer": 54–75 % of the attendance table, then four seconds of silence, 0 of 3 reads complete.
- **What was done:** first diagnosis — socket receive buffer / switch to TCP — was **wrong**: `/proc/net/snmp` counted 3 `RcvbufErrors` across a read that lost ~145,000 bytes; the device had stopped sending. The firmware supports windowed reads: `PREPARE_BUFFER` (1503) → `READ_BUFFER` (1504) 16 KB at a time → `FREE_DATA` (1502). On this model the users table refuses windowed reads (reply 4991) — windowing is per table.
- **Root cause:** the push read overruns this firmware; it must be asked one window at a time.
- **Fix:** library `Transport/BulkReader` prefers windowed reads, push envelope as fallback (`tests/Unit/WindowedReadTest.php`).
- **Verified:** 6 of 6 reads, 326,124 bytes, 8,153 records; 18 of 18 across three tables.
- **Evidence:** `tools/zkprobe/traces-2026-09-13/`, `docs/protocol-overview.md` "Windowed reads".
- **Status:** FIXED
- **Keywords:** Incomplete transfer, windowed read, 1503, 1504, 4991, RcvbufErrors, F22

---

## Findings older than this casebook

Recorded in the rules themselves, without an entry of their own:

- **Rule 10:** device timestamps stored without UTC conversion produced 6,432
  duplicate rows at a 3-hour offset, and a watermark 3 hours in the future.
- **Rule 11:** a date-parsing bug silently discarded a third of the attendance for
  months — why sync counts undecodable records instead of dropping them.
