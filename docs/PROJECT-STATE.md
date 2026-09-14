# Project state — read this first

**This file is the answer to "where were we?"**

It is maintained under CLAUDE.md rule 14. If you have just arrived on a different
machine, or after a long gap, read this before reading code.

Assistant memory does **not** travel between machines. This file does, because it
is committed. That is the whole reason it exists.

**Last updated: 2026-09-14**

---

## ⚠ Before you trust this file on a new machine

Several things currently break the "arrive on another machine and see everything"
promise. Fix them or the rest of this document is describing work that machine
cannot see:

| Blocker | State on 2026-09-13 |
|---|---|
| **The live library is untracked and outside git** | Since 2026-09-14 it lives at **`/var/biometric-protocol/`**, with `vendor/promoseven/biometric-protocol` a symlink to it (composer's own doing — see the session log). It is still **not a git repository**: no history, no tags, no remote. **The windowed-read protocol fix lives only there**, on this box's filesystem, outside every repository. This is still the worst of the three. |
| The tracked copy is the **wrong** one | `packages/zkteco-protocol/` (`Zkteco\Protocol`) is tracked but superseded and loaded by nothing. Reading it will mislead you. |
| ~~App depends on a path that does not exist~~ **FIXED 2026-09-14** | The path repository `../../biometric-protocol` resolves to `/var/biometric-protocol`, which was absent; **any** `composer require` failed outright, not just `composer install`. The library was copied there and composer now resolves and symlinks it normally. The copy is still nobody's git repository, so this only unblocks composer — it does not make the library safe. |
| App repo not pushed | `origin` is `git@github.com:GhassanYusuf/biMetric.git`, with a large uncommitted working tree and nothing recent pushed. |
| **The whole C3 driver of 2026-09-14 is UNCOMMITTED, and half of it is OUTSIDE GIT** | The app half (`app/Services/Devices/C3/C3Driver.php`, the manager, the model constant, the form, the docs) is in this machine's working tree on branch `camelot`. **The protocol half is worse: `Promoseven\Biometric\C3` lives in `/var/biometric-protocol/`, which is not a git repository at all** — no history, no tags, no remote. Two dead panels became readable today and that work currently survives only on this box's filesystem. This is the same blocker as the row above, now carrying considerably more. |
| **The vendor SDK dump is deliberately NOT in git** | `draft/` (the owner's ZKAccess 3.5.5 install and `draft/analysis/`) is gitignored because it holds real employee names, card numbers and fingerprint templates. Every conclusion drawn from it is written into `docs/`; the source is not portable and must be re-supplied by the owner if needed again. |

Sequence to fix: **back up `vendor/promoseven/biometric-protocol/` first** → put it
in its own repo with a tag → repoint the path repository (or a private registry) →
retire `packages/zkteco-protocol/` → commit and push the app. Only then does a
clone-and-go work.

---

## Repository layout

```
Projects/
├── biometric-protocol/     ← THE PRODUCT. Own repo, v1.0.0, proprietary licence.
│                             No remote yet. 43 files, ~4,700 lines of src/.
└── payroll/app/            ← Reference consumer + demo. origin = biMetric.git
                              Consumes the library via composer path repository.
```

**That is the intended layout, and this machine only half has it.** Since
2026-09-14 the live library sits at `/var/biometric-protocol/` — the path the
composer path repository has always pointed at — and
`vendor/promoseven/biometric-protocol` is a symlink to it. So composer works. But
that directory is **not a git repository and has no remote**, so it is still the
only copy of the library in existence, backed up by nothing. See the blockers
above before touching composer.

---

## What the product is

**Middleware, not an HRMS.** This was clarified on 2026-09-02 and it reframes
everything earlier in `docs/`.

```
Biometric terminals  →  OUR PRODUCT (the bridge)  →  customer's HR platform
```

- **Our customer is the HRMS vendor**, not the employer. Their customer is the employer.
- `payroll/app` is a **reference consumer and demo. It is not for sale.**
- We supply device connection, firmware handling, attendance collection and
  enrollment plumbing. The HRMS owns employees, shifts, payroll rules and the UI.

### Commercial decisions taken

| Decision | Detail |
|---|---|
| Pricing unit | Per connected terminal, per year, billed to the HRMS vendor, who marks it up. OEM/redistribution licence. |
| Delivery | On-prem container ("the bridge") that runs inside the employer's network. **Relay mode recommended** — container dials out, so no inbound firewall rules. |
| Protection | Don't ship source. The container is the protection. Composer/source access is a premium, contract-only tier with per-customer build fingerprints. |
| Naming | `promoseven/biometric-protocol`, namespace `Promoseven\Biometric`. The vendor trademark is out of the package name and namespace; it remains in the description and keywords as descriptive/nominative use. |
| Licence enforcement | An expired licence blocks new terminals and updates. **It must never stop attendance collection.** Bricking payroll costs more than the piracy. |

---

## Where we stopped

### Reporting: PDF exports and a Reports screen shipped (2026-09-14)

Done, tested, **not committed**: three PDF exports, the attendance row → employee
dialog, a `/reports` screen, and a timezone fix to the attendance date filters.
See the session log for what each one covers.

Open, in order of how much it matters:

1. **Arabic names will not render in a PDF.** dompdf's bundled DejaVu Sans does no
   right-to-left shaping, so those names come out as disjoint glyphs. Needs a
   shaping font registered with dompdf. Nobody has checked whether this tenant's
   roster actually contains any — do that before deciding it matters.
2. **The date-filter fix changes existing results.** Ranges now include the whole
   local end day. Anybody comparing a new export against an old screenshot will
   see more rows, correctly.
3. Payroll exports read every active employee's summary in one request. Fine at
   this roster size; it is the first thing to feel slow at a few hundred people
   over a long range.

### "Incomplete transfer" on 192.168.7.63 is FIXED — windowed reads (2026-09-13)

**Fixed and verified on hardware. Attendance sync on device 6 works: 8,153 records,
0 undecodable, watermark advanced.**

An earlier pass the same day concluded the answer was TCP, and recorded that
conclusion here. **That has been superseded.** The answer was in the UDP protocol
all along: this firmware supports a flow-controlled *windowed* read, and the
library now prefers it.

```
CMD_PREPARE_BUFFER (1503)  → device stages the table, answers with its size
CMD_READ_BUFFER    (1504)  → one explicit 16 KB window at a time
CMD_FREE_DATA      (1502)  → release
```

Because the device sends only what was just asked for, there is no burst to race
and the receive buffer stops mattering.

| | Attendance read on 192.168.7.63 |
|---|---|
| Before (push path) | 0 of 3. Typically 54-75% then four seconds of silence. |
| **After (windowed)** | **6 of 6, 326,124 bytes, 8,153 records every time.** |

18 of 18 library reads across all three tables.

**The earlier diagnosis was wrong on one point, and it matters.** It blamed the
socket receive buffer. Measurement says otherwise: `/proc/net/snmp` counted **three**
`RcvbufErrors` across a read that lost ~145,000 bytes. The kernel delivered
essentially everything it received — the device stopped sending. That is why
raising `SO_RCVBUF` and draining faster both only ever helped at the margins.

**Support is per TABLE, not per device.** The same F22 stages attendance (326,124)
and templates (18,486) but refuses users (reply 4991). The library negotiates on
every read and falls back to the push path on refusal, so this needs no
configuration and no capability flag.

**TCP is no longer needed for this, but is still worth having.** The earlier
finding stands on its own merits — TCP was measured at 326,084 bytes in 0.03 s,
3 of 3 — and would remove the whole class of problem for devices that refuse to
stage a table. It is now a *nice-to-have*, not the blocker it was recorded as.
The framing details, confirmed on hardware, are preserved in the session log entry
below so they are not lost.

### Camelot (device 9) — handshake FIXED, bulk read COMPLETES over UDP but is flaky (2026-09-14)

**A full read completes over UDP: 41,632 records, ~285–300 s, achieved twice.** Not
committed. The fix is in the library (`BulkReader`), recorded as **2.1.0** in its
`CHANGELOG.md`.

**Honest status: complete reads are proven possible, not yet reliable per attempt.**
Camelot is a flaky public device — across many attempts this session it failed at
`CONNECT` (1000), `AUTH` (1102), and `PREPARE_BUFFER` (1503) at different times,
worse the more it was hammered. Reliability comes from the queue's per-command
retry+backoff (rule 11); full Camelot reads must be **queued**, not run on the
synchronous controller path (~285 s ≈ its 300 s limit).

**TCP transport was added (`TcpTransport`, library 2.2.0) as the more robust option,
but is NOT yet proven on Camelot.** A frame-header bug (`wrapTcp` wrote the second
magic word as zero — the device closed the connection) was found and fixed; TCP then
read an F22 whole (8,162 records). On Camelot TCP got through `CONNECT`+`AUTH` but
`PREPARE_BUFFER` timed out — the same device-flakiness, not a transport fault. **The
default transport stays `udp`** (`attendance.device_transport`), which is the proven
path; set it to `tcp`/`auto` to try TCP on Camelot once the device can be tested on a
clean, unloaded connection. Camelot was heavily loaded by this session's testing and
needs to rest.

**Handshake (solved 2026-09-13).** Camelot answers `CMD_CONNECT` with `ACK_UNAUTH`
(2005) even though its comm key is **0** — it wants the AUTH exchange performed, not
a secret. `CMD_AUTH` with `Packet::makeCommKey(0, $sessionId)` returns `ACK_OK`.

**Bulk read (solved 2026-09-14).** The table is **1,664,804 bytes** (~41,620
records). A packet capture of a failing full read (2,069 packets, kernel counters at
5 Hz) settled the cause and killed the earlier theories:

- **Not the receive buffer** — `RcvbufErrors` delta 0, socket rx-queue max 0.
- **Not corruption** — `InErrors`/`InCsumErrors` delta 0.
- The device streamed ~490 KB smoothly (windows ~28 ms apart), then a 16 KB window
  went silent >8 s. The reader used to discard the whole transfer on that first
  silence and restart from offset 0, so across five whole-request attempts it read
  ~⅓ of the table and never finished. A stall also **wedges the whole staged
  session** — retries at the same offset in that session fail too.

**The fix (library 2.1.0), gated on `DeviceConfig::$bulkWindowRetries` (default 4,
`attendance.device_window_retries`, env `DEVICE_WINDOW_RETRIES`):**

1. Re-request a stalled window at the same offset (the staged copy is intact).
2. If that is spent, **reopen the session with a backoff, re-stage, and resume from
   the same offset** — `PREPARE_BUFFER` stages the whole table and `READ_BUFFER` is
   offset-addressed, so a fresh session continues rather than restarting. The device
   serves ~490 KB per session, so the table completes in ~4 reconnects.
3. A `READ_BUFFER` request timeout counts as a stall; a `FREE_DATA` release timeout
   no longer fails a completed read.

This also produced the first CONFIRMED evidence that the **F18 supports windowed
reads**. F18 LAN re-confirmed unaffected after the shared-code change (10,512
records, 13 s — rule 15).

**A complete read is now POSSIBLE; per-attempt reliability is not yet 100%.** One
full read completed cleanly (41,632 records, 285 s). Two other attempts failed on a
handshake — a back-to-back read on its INITIAL connect, and a gap-then-read on a
MID-READ reconnect — both command 1000 (`CONNECT`) timeouts. Camelot refuses a new
session for a variable few seconds after one closes, and each windowed read reopens
~4 times, so any of those reconnects can hit the refusal. The reader now retries a
reconnect up to 6 times with escalating backoff (was 4), which improves the odds but
does not guarantee a single attempt.

**What makes it reliable is the queue, not the library.** Device work is
one-command-at-a-time with whole-command retry and backoff (rule 11), so a failed
read is re-queued and a later attempt lands when Camelot is cooperative. **Full
Camelot reads must run as a queued job, not the synchronous controller path** — a
clean read already takes ~285 s, close to that path's 300 s `set_time_limit`, and a
run with reconnect backoff will exceed it. Do not read a single manual failure as a
regression.

**Still worth doing:** the Good Wheels control (`46.184.163.106`, device 5) — a
second REMOTE device — to separate "remote link" from "this device". Owed by whoever
picks up device work.

**Tooling — moved out of `storage/`, but ⚠ STILL NOT COMMITTED:**
`tools/zkprobe/harness.php` (instrumented read harness: per-datagram timestamps,
sizes, receive-queue depth, and UDP counter deltas per run; `push` and `windowed`
modes; handles the AUTH step), `Probe.php` (the shared instrumentation, extracted
2026-09-14) and `experiment.php` (the Camelot pacing/flush/recover experiments),
with **43 traces** across `traces/`, `traces-2026-09-13/` and `traces-2026-09-14/`.

It was first written under `storage/app/zkprobe/`, which is entirely gitignored, and
moved to `tools/` to survive. **It has not actually survived anything yet:**
`git status` reports the whole of `tools/` as untracked, so every trace behind every
claim in this section exists on this box only. Rule 16 says traces are committed
precisely because a conclusion whose evidence lives on one machine is an assertion.
Commit `tools/` with the rest of the working tree.

### ⚠ The library change is in `vendor/`, which is GIT-IGNORED (2026-09-13)

**This is the most important thing on this page right now.**

The fix above was made in `vendor/promoseven/biometric-protocol/`, because that is
where the running library actually lives on this machine. `.gitignore` line 21
ignores `/vendor`. **The fix is therefore not tracked by any repository and exists
only in this machine's working tree.** A `composer install`, a `composer update`, or
a fresh clone loses it.

What is actually on this box, as opposed to what the layout section below claims:

| | |
|---|---|
| `/var/biometric-protocol/` | The **live** library, `Promoseven\Biometric`, since 2026-09-14. **Not a git repository** — no history, no tags, no remote. |
| `vendor/promoseven/biometric-protocol/` | A **symlink** to the above, created by composer on 2026-09-14. Git-ignored. Was a plain directory holding the only copy before that. |
| `packages/zkteco-protocol/` | An **older, superseded** copy, namespace `Zkteco\Protocol`. Tracked in git. **Nothing loads it.** |
| `../../biometric-protocol` | The composer path repository target — `/var/biometric-protocol`. **Exists since 2026-09-14.** |

So `composer install` cannot currently reconstruct `vendor/` from source, and the
tracked copy is the wrong one. **Before anything else next session:** get the live
library into a repository of its own at `/var/biometric-protocol`, tag it, and
delete or clearly retire `packages/zkteco-protocol/`. Until then, back up
`/var/biometric-protocol/` before running any composer command — composer will
now happily replace what is there.

Files changed there this session, for whoever reconstructs it:

- `src/Protocol/Command.php` — added `PREPARE_BUFFER` (1503), `READ_BUFFER` (1504)
- `src/Transport/BulkReader.php` — `readWindowed()`, `readWindow()`, fast drain,
  buffer figures in the `Incomplete transfer` context
- `src/Transport/UdpTransport.php` — cached read timeout, `readRawPayload()`
- `src/Contracts/SupportsRawDrain.php` — new
- `tests/Unit/WindowedReadTest.php`, `tests/Unit/BulkDrainTest.php` — new
- `README.md` — windowed reads, F22 compatibility

Version was **not** bumped: the package has no `version` field and no tags to bump
from in this location. It needs doing once the repository question is settled.

### Done and verified (2026-09-02)

1. **Two SPA crashes fixed** — see the session log below.
2. **Per-terminal enrollment attribution** shipped across roster, modal and API.
3. **Library extracted** into its own repo with a clean history, `LICENSE`,
   copyright headers on all 36 PHP files, `README.md`, `.gitattributes`, and tag
   `v1.0.0`. Renamed off the trademark. Verified to run on bare PHP 8.2 with no
   Composer, no framework and no `vendor/`.
4. **API surface drafted** for the bridge — 15 endpoints across 5 resources.
   Published as an artifact:
   <https://claude.ai/code/artifact/24caee9e-aeed-4f72-97ed-35ed4c80cc78>

   Four rules the API is built on, and these are settled:
   - identity-agnostic — we key on the terminal's own `user_id` and never learn
     who anyone is; the HRMS owns the mapping
   - ack-then-forget retention — punches are held only until acknowledged;
     fingerprint templates never leave the terminal
   - capability-driven — four levels, unsupported actions hidden not failed
   - every write is a job — a capture waits up to 30s for a human, so it can
     never be a held-open HTTP request

### Agreed next step, NOT started

The **Docker/HTTP bridge service** implementing the drafted API. Nothing exists
for it yet. The library, capability gating, queueing and error reporting it will
wrap all exist already.

### Open questions — need Ghassan's decision

1. **Relay or direct-only?** Relay gives HRMS vendors one API address and needs no
   firewall rules, but makes us infrastructure with uptime obligations.
2. **Do we offer device-user → employee mapping as a convenience, or refuse it** to
   stay strictly identity-agnostic?
3. **Extract the vendor-neutral vocabulary?** `Capability`, `CapabilitySet` and
   `DeviceException` live in the ZKTeco package but are shared vocabulary — which is
   why `CslHttpDriver`, which speaks only HTTP, must import from it. Recorded in
   `ProtocolLibraryBoundaryTest`. **Blocks a third vendor.**
4. **Legal.** EULA, trademark posture, and the reverse-engineering question need a
   real lawyer before the first sale. The `LICENSE` in the library repo is a sound
   conservative default, not legal advice.
5. **Hosting.** Where the library repo lives, and which private Composer registry
   (Private Packagist, self-hosted Satis, or plain git access per licensee).

---

## Session log

### 2026-09-14 — C3/PullSDK driver BUILT; both dead panels now read through the app

Follows directly from the entry below. The owner said build it, so the protocol
went into the library and a third driver went into the app, in rule 8's order.

**Library — `Promoseven\Biometric\C3`, version 2.3.0.** A second protocol family
beside the standalone one, sharing no frame, checksum, session model or constant
with it. `Frame` (CRC-16/ARC), `Command`, `C3TcpTransport`, `BufferedReader` (the
`0x0D`/`0x0E`/`0x0F` staged read), `Catalogue`, `RecordCodec`, `Timestamp`,
`C3Capabilities`, and per-model classes under `C3\Model`. The package name stopped
being aspirational: it now speaks two biometric protocols rather than one.

**App — `App\Services\Devices\C3\C3Driver`**, the third implementation of
`BiometricDeviceDriver`. Adding it touched the driver manager, the `Device`
protocol constant, the three request rules and the device form. **No service, no
job, no controller, no route** — rule 8's claim held for a second time.

**Working, end to end, through the app's own driver:**

| | inBIO460 `.61` | TF1700 `.60` |
|---|---|---|
| records | 87,756 in 12.8 s | 21,005 in 4.0 s |
| with a person | 86,512 | 5,795 |
| door-only events | 1,244 | 15,210 |
| timestamps decoded | all | all |

**The finding that nearly shipped a silent bug.** The first driver run read the
inBIO460 perfectly and returned **zero records** for the TF1700. A controlled
comparison — four connect sequences against both panels, one variable at a time —
showed the TF1700 **defaults to session framing** and needs `CONNECT_SESSION_LESS`
(`0x01`) to leave it. Without that, every reply carries four extra session bytes
(catalogue 1180 B against 1176 B), so a session-less reader is shifted four bytes
on every payload and `GET_DATA` answers a well-formed `0xC8` with ten useless
bytes. **It does not error. It reports zero records.** A panel holding 21,005
punches would have looked reachable, healthy and empty, and an operator would have
read that as "nobody clocked in". Only the control read caught it. The inBIO460 is
the exact opposite and refuses `0x01` with −98 — which is why the two models have
separate classes rather than a shared file with a flag (rule 15).

**Tests: 257 passing**, 21 of them new and none needing a device. Two fixtures are
real captures chosen because they carry no personal data (a staging header, a table
catalogue); the record stream is synthetic with real structure, because a real one
is full of card numbers (rule 16). Three tests exist purely to keep the capability
levels honest: an unread panel confirms nothing, a read panel confirms only what
was exercised, and no write is ever offered — including the door relay.

**Rule 15 pushed back and improved the design.** `DeviceModelIsolationTest` rejected
model names as string literals in the factory and the capability class. The fix was
to move each model's name match AND its capability set into the file named for that
model, leaving the factory holding class references and no model name at all.

**Debt now formally due.** This is the THIRD driver importing `Capability`,
`CapabilitySet` and `DeviceException` from the ZKTeco package. Rule 8 said that
shared vocabulary must move to a vendor-neutral package "before a third vendor
arrives". It has arrived. Moving it was deliberately not bundled into the same
change as making two dead panels readable; it is recorded in
`ProtocolLibraryBoundaryTest` as the next structural job.

**Not done, and each is a deliberate stop:**

- **No device row has been switched to protocol `c3`.** Both panels hold years of
  history — the inBIO460 back to 2021 — which would land in attendance on the first
  sync. That is the owner's call, not a code change. **Owed to Ghassan.**
- **Every write is unbuilt**: enrollment, `SetDeviceData`, and the door relay
  (`0x05`). All `not_implemented`, so the UI hides them. Rule 16 wants explicit
  authorisation and a terminal out of service before any is probed.
- **No realtime, and no incremental read** — there is no cursor, so a sync transfers
  the whole table every time.
- **The roster has never been read**; `USER_READ` stays `implemented`, not
  `confirmed`.

### 2026-09-14 — Glass Door (.60) is a C3 panel, not standalone; C3 buffered read SOLVED from the vendor SDK

The owner supplied a zip of their own licensed **ZKAccess 3.5.5** installation
(`draft/ZKTimeNet.UI.Interfaces.zip`, 82 MB) and asked that it be used to understand
the device protocols. It contained far more than DLLs: both native SDKs side by side
in `NewSDK/` (`plcommpro.dll` = PullSDK, `zkemkeeper.dll` = standalone), the managed
assemblies **with their `.pdb` symbol files**, the live 53 MB `Access.mdb`, and the
vendor's log.

**First action: `/draft` was added to `.gitignore`.** It was a tracked path, and that
`.mdb` holds real employee names, card numbers and fingerprint templates. Analysis
notes live in `draft/analysis/` on this machine only. Rule 16: decoded structure is
committed, the capture is not.

**Finding 1 — `.60` was misdiagnosed, twice over.** The vendor's `Machines` table has
a `DevSDKType` column: F18 and F22 are `2`, **inBIO460 and TF1700 are `1`**. The
selection is four IL instructions in `MachinesManager.dll` — `_SDKType == 2` builds a
COM client of `zkemkeeper`, otherwise a P/Invoke client of `plcommpro`. Our existing
C3 probe was pointed at `.60` and read its identity on the first attempt. The
previous entry here — "comm/SDK service switched off, menu action owed to Ghassan" —
was wrong; **nothing is owed and nothing needs touching on the device.**

**Finding 2 — the `0x0D` buffered pull, open since 2026-09-14 morning, is closed.**
Recovered from `plcommpro.dll` disassembly (dispatch `0x10002be0`, pull loop
`0x100029b0`) and cross-checked against the managed layer: `0x0D` PREPARE_DATA →
`0x0E` DATA → `0x0F` FREE_DATA, 17-byte header, request is a bare 4-byte LE offset,
reply echoes it, CRC-32/ISO-HDLC over the assembled bytes. Implemented in
`tools/zkprobe/inbio460.php` and **both panels read end to end with the checksum
matching** — 87,756 rows off `.61` and 21,005 off `.60`.

Verified three independent ways, not one: the device's own CRC-32; row counts
against the vendor DB (87,738 / 20,931, the deltas being events since the vendor
last synced); and the first TF1700 record decoding to `2025-08-10 17:28:11`, which is
**exactly** the earliest TF1700 row in `Access.mdb`. Control per rule 16: the F18 at
`.62` was sent identical C3 frames and refused them, breaking the pipe.

**Finding 3 — a per-model difference inside one family.** The TF1700 **accepts**
CONNECT; the inBIO460 **refuses both forms** and must run session-less. Rule 15: that
is a selection at construction, never a branch in a read loop.

**Finding 4 — `-4991` is named.** The vendor error dictionaries are not in the DLLs
but in `Languages/language_en-US.xml`. `-4991` = "Operation database has no
corresponding data" — so the F22's long-unexplained `4991` reply is a **semantic
refusal about that table's contents, not a transport failure**, consistent with our
per-table reading. `docs/protocol-overview.md` updated; our fallback is unchanged.

**Finding 5 — timezone, confirmed the hard way.** `acc_monitor_log.time` is bare
device-local datetime with no conversion anywhere, and `Machines.TZAdj` is still the
factory default `8` on a UTC+3 site. Evidence: the vendor log records a thread
starting `09:06:23.586` and stopping `09:06:25.772` by the PC clock, and the F22's
last two events are stamped `09:06:24`, inside that window. Rule 10 confirmed on the
vendor's own data.

Also recovered and recorded for later: the literal PullSDK **write** vocabulary from
`devcmds.CmdContent` (`update|user$`, `update|templatev10$`, `update|userauthorize$`)
with field lists and an enforced teardown order; that the two families use
**different template tables** (`templatev10` on the panels, `pers_biotemplate` on
F18/F22); and that the vendor's own `acc_monitor_log` ids are not stable across their
backups (12,289 vanished, 963 reused, 54 events silently lost), so their ids must
never be used as a watermark.

Docs updated: `docs/device-capabilities.md` (TF1700 section rewritten, verification
table corrected, full `0x0D` layout), `docs/protocol-overview.md` (family scope note,
`4991` partly resolved, C3 pointer).

**Not built, and deliberately so:** no C3 driver, no capability set, no UI, no
writes. ~108,761 punches sit on those two panels that the app still cannot read.

### 2026-09-14 — 524 on Camelot read root-caused: sync ran inline + no worker

**Uncommitted, on branch `camelot`.** (The systemd worker service IS installed on
this box — see below.)

The app returned **HTTP 524** (front-proxy gateway timeout) when reading Camelot.
Two causes, both now fixed:

1. **`DeviceController::syncAttendance` ran the read INLINE** in the web request. A
   full Camelot read is ~285 s; the proxy times out (~100 s) long before it
   finishes → 524. It now **queues** a `SYNC_ATTENDANCE` command via
   `DeviceCommandBus` and returns at once (rule 11 — a device read is not a
   web-request-shaped operation). The devices screen already polls `sync-progress`;
   `Devices.jsx` now keeps the row busy on the queued response and finishes it when
   the run reaches a terminal status. NOTE the terminal statuses are
   `succeeded`/`partial`/`failed`, NOT `completed` — the first cut of the frontend
   checked `completed` and would have spun forever; fixed.

2. **No queue worker ran**, so even correctly-queued commands never executed —
   app-wide, not just attendance. Installed `zkt-queue.service` (systemd,
   `queue:work database` as `www-data`, enabled + `Restart=always`). Verified: a
   queued F18 sync ran to `succeeded` (read 10,525, imported 21).

Net effect: the app no longer 524s on a slow read, and Camelot goes through the same
queued path — the worker's row-level retries+backoff (rule 11) absorb its session
flakiness over time, which is the only thing that makes an unreliable public device
usable. Full suite 236 green; frontend rebuilt.

### 2026-09-14 — TCP transport added (library 2.2.0); Camelot still flaky

**Uncommitted, on branch `camelot`.** `tools/` still untracked.

The user reported Camelot still failing to read fully. UDP windowed-resume (2.1.0)
completes it but only ~half of attempts — Camelot is a flaky public device. TCP was
the recorded real fix (a 2026-09-13 note: an F22 read whole over TCP where UDP could
not), so a `TcpTransport` was built.

Found and fixed a real bug in the process: `Packet::wrapTcp` wrote the TCP frame's
second magic word as **zero**, and a device rejects that outright (closes the
connection, no reply) — so the TCP path had never actually worked. Both words are
required (`0x5050`, `0x7d82`). After the fix, TCP read an **F22 whole (8,162
records)** — the transport is correct.

**On Camelot, TCP is not yet proven.** Across many attempts (both transports) it
failed at `CONNECT`, `AUTH`, and `PREPARE_BUFFER` at different times — the device is
unreliable at session setup on a congested public link, and this session's heavy
testing degraded it further. TCP got through `CONNECT`+`AUTH` but staging timed out.
So the **default transport stays `udp`** (proven to complete Camelot); TCP is opt-in
(`attendance.device_transport` = `tcp`/`auto`) to try on a rested, unloaded Camelot.

Shipped: `TcpTransport`, `DeviceConfig::$transport`/`usesTcp()`, transport selection
in `DeviceClient`/`SessionManager`, app auto-select (`ZktecoDriver::transportFor`,
public IP → TCP under `auto`), `attendance.device_transport`. Eight new library tests
(`TcpFrameTest`, `TcpTransportTest`). Full suite 236 green. Docs: library
`CHANGELOG.md` (2.2.0), `library-api-reference.md`, this file.

**The honest bottom line for Camelot:** a complete read is possible and proven over
UDP; per-attempt reliability is a property of the device, not the code, and belongs
to the queue's retry+backoff (rule 11). Let Camelot rest before the next test.

### 2026-09-14 — Camelot bulk read FIXED (windowed resume); library 2.1.0

**Uncommitted, on branch `camelot`.** Not committed; `tools/` still untracked.

A live full read from Camelot was captured on the wire (tcpdump, 2,069 packets +
kernel counters at 5 Hz — the raw pcap stays in the scratchpad, PII; the structural
summary is `tools/zkprobe/traces-2026-09-14/camelot-fullread-diagnosis-01.json`).
It proved the failure was **not** the receive buffer (`RcvbufErrors` delta 0, rx-queue
0) and **not** corruption — the device streamed ~490 KB then a window went silent,
and the reader discarded the whole transfer and restarted from zero, so it never
finished a 1.66 MB table.

Fixed in the library (`BulkReader`), recorded as **2.1.0** (`CHANGELOG.md`): a stalled
window is re-requested at the same offset; if that is spent — a stall wedges the whole
staged session on this firmware — the session is reopened with a backoff (Camelot
refuses an immediate reconnect), re-staged, and resumed from the offset. A
`READ_BUFFER` timeout counts as a stall; a `FREE_DATA` timeout no longer fails a
completed read. New `DeviceConfig::$bulkWindowRetries` (default 4, env
`DEVICE_WINDOW_RETRIES`); `0` restores the old behaviour.

**Result: 41,632 records in 285 s — the first complete Camelot read.** F18 LAN
re-confirmed unaffected (10,512 records, 13 s — rule 15). Six new `WindowedReadTest`
cases; full suite 228→ green. Also the first CONFIRMED proof the **F18 supports
windowed reads**.

Operational note: a complete read is proven possible but not yet reliable per single
attempt — Camelot refuses a new session for a variable few seconds after one closes,
so any of a read's ~4 reconnects (or the initial one) can time out on `CONNECT`.
Reconnect retries were raised 4→6 to improve the odds; reliability comes from the
queue's per-command retry+backoff (rule 11), and full Camelot reads must be queued,
not run on the synchronous controller path (a clean read ~285 s ≈ its 300 s limit).

Docs updated (rule 12): library `CHANGELOG.md`/`README.md`; app
`protocol-overview.md`, `attendance-sync-flow.md`, `error-handling.md`,
`library-api-reference.md`, `change-log-or-roadmap.md`.

### 2026-09-14 — TF1700 on .60 identified (standalone family); comm service off

**Uncommitted, on branch `camelot`**, like everything below it.

Owner asked to add a TF1700 device, research it, and test the unit at
`192.168.7.60` (the "Glass Door" that was recorded as permanently silent).

**Finding.** The TF1700 is a **standalone binary terminal in the F18 family**, not a
C3/inBIO panel — the `.60` telnet banner (`ZEM560` Linux-MIPS) confirms it, and it
corrects the earlier weak "shares the inBIO OUI → probably inBIO" guess (that OUI is
ZKTeco's company-wide one). So it needs **no new driver and no new library** — it
routes through the existing `ZktecoDriver` exactly like F19/F16/K40, per rules 13/15
(nothing about it behaves differently from the F18, and there's no evidence it does
until it answers).

**The unit at `.60` cannot be read: its comm/SDK service is not serving.** Port scan:
only 23 (telnet) and 4370 open. UDP 4370 closed (ICMP unreachable). TCP 4370 accepts
but answers nothing — to CONNECT, AUTH, VERSION or garbage — while the **F18 control
on `.62` read full identity over the identical code**. The F18 also *closes* the
socket on garbage; `.60` never reacts at all — the signature of a TCP port the kernel
accepts but no application services. **Owed to Ghassan: enable PC-communication / SDK
on the terminal menu (and confirm 4370 isn't NAT-forwarded elsewhere).** Then it
works with no code change.

**Shipped this session:**
- `TF1700` added to the add-device dropdown under standalone binary (status
  `family, untested`), with a comment pointing at the finding.
- `docs/device-capabilities.md`: TF1700 matrix row + a full "TF1700 on .60" finding
  (banner, port scan, wire results, the F18 control, the diagnosis).
- `docs/PROJECT-STATE.md`: terminal-table row 8 rewritten, the wrong inBIO inference
  corrected, this entry.
- **New probe `tools/zkprobe/tf1700.php`** — READ-ONLY, standalone protocol over TCP,
  with a built-in F18 control run. Traces in `traces-2026-09-14/tf1700-*.json`.
  Like the rest of `tools/`, **still untracked** (see housekeeping).

### 2026-09-14 — Honest model matrix + auto-detect on add

**Uncommitted, on branch `camelot`**, like everything below it.

Owner pushed back on the model dropdown: "do you really have all these protocols and
do all operations?" Correct to push back — the dropdown implied five protocols. There
are **two families**: standalone binary (F-/K-series) and C3/PullSDK (inBIO/C3).
Within a family the protocol is the same; models differ only at the edges.

Verified read-only through the app's own driver, 2026-09-14, against the three
devices we physically have:

- **F18** @ .62 — info, users (18), attendance (10,510): all clean.
- **F22** @ .63 — info, users (11), attendance (8,162): all clean, same binary driver.
- **inBIO460** @ .61 — binary `info()` **times out** (it is a C3 panel, not a
  terminal). Only the standalone `tools/zkprobe/inbio460.php` probe reaches it.

F19/F16/K40 are the same binary family (pyzk tests F18/K40; the ZK spec came off an
F19) but we have no such hardware, so they stay listed as "same protocol, untested".
The C3 variants stay listed as "untested". Nothing is claimed that was not seen.

Two changes shipped:

1. **The model dropdown now tells the truth.** Each option carries its real status —
   tested / same-protocol-untested / reads-only / untested — instead of a bare name.
   Untested models stay in the list so the choice exists when the device arrives.
2. **Auto-detect on add.** New `POST /api/devices/identify` reads a device's identity
   before it is saved (`DeviceHealthService::identify()`, no persistence) and selects
   the matching model ("F18/ID" -> F18). Works for the binary family; an inBIO/C3
   panel times out and the API says so honestly. **C3 auto-detect needs a C3 driver,
   which does not exist** — same blocker as reading inBIO data at all.

The full matrix and the endpoint are documented in `docs/device-capabilities.md`.
Still open, unchanged: the inBIO `0x0D` buffered-pull, and there is no C3 driver in
the app (only the probe tool). Both owed by whoever picks up device work.

### 2026-09-14 — inBIO460 on .61 cracked; CLAUDE.md rule 17 added

**Uncommitted, on branch `camelot`**, like everything below it.

Owner asked to test `192.168.7.61` (model inBIO460): research first, connect and
read if possible, reverse-engineer in the worst case — and make the approach a rule.
Both happened.

**The finding.** `.61` was recorded here as "pings but protocol silent" and assumed
dead. It is not: an **inBIO460 is a C3 / PullSDK access panel**, not a standalone
terminal, and speaks a different protocol from the F18 — TCP, different frame,
CRC-16/ARC. Fifteen minutes reading two open-source reimplementations
(`vwout/zkaccess-c3-py` and `-lua`) gave the exact wire format. Speaking it, the
"silent" panel answered on the first try:

- Identity, all parameters, and the **full data-table schema** read cleanly.
- Connect is *refused* (0x76 → −13, 0x01 → −98) and turns out to be unnecessary —
  commands answer session-less. Reading the refusal replies, whose CRCs were all
  valid, is what revealed that; treating the refusal as "device down" is the trap
  the previous diagnosis fell into.
- `GETDATA` answers `0x0D` — a buffered-size + pull-token handshake (7,708 bytes
  staged for the user table), not `0xC8` inline rows as the references assume. The
  buffered pull that fetches the actual rows is **the next step and is not built.**

Corrected the wrong 2026-09-13 diagnosis of `.61` in the operational-facts section
(rule 14: correct, don't append). `.60` (Glass Door) shares the inBIO MAC OUI and
should be tried with the same C3 approach before assuming a fault.

**Tooling:** `tools/zkprobe/inbio460.php` (new, READ-ONLY, C3 protocol; CRC
transcribed literally from the reference) and its traces in `traces-2026-09-14/`.
The door-relay command (0x05 CONTROL) is deliberately NOT implemented — it is a
write and rule 16 forbids it without the owner's go-ahead and a panel out of
service. **`tools/` is still untracked in its entirety** (see housekeeping).

**Rule 17 added to CLAUDE.md — "Research before you reverse-engineer."** The whole
episode is its worked example: identify the model, find the protocol in public
sources, transcribe rather than invent, try the known thing and *read the refusal*,
and reverse-engineer from zero only the narrow gap research did not cover. When a
real inBIO integration is built it is a **new driver implementing the contract**
(rule 8, second vendor-shaped protocol), in **model-named files** (rule 15).

### 2026-09-14 — CLAUDE.md rule 16: learning a device on the wire

**Uncommitted, on branch `camelot`**, like everything below it.

Owner's instruction, and the deliberate fallback for a model with no SDK and no
documentation: speak the known protocol at it, vary one thing at a time, then try
the unknown — and **read the failures**, because a refusal carries the reply code,
the size the device thinks it has and how far it got. This is not new practice; it
is what actually solved Camelot's handshake on 2026-09-13 and found the F22's
per-table windowed reads. Rule 16 writes the method down so the next person does not
reinvent it, and fences the two parts that can hurt:

- **Read-only until the owner says otherwise.** A write probe needs an explicit
  go-ahead and a terminal out of service, because a destroyed enrollment can only be
  recollected with the employee standing at the device.
- **Never commit raw captures.** Capturing the vendor's own app talking to a
  terminal is the single highest-value source and the capture contains names, card
  numbers and fingerprint templates. Decoded structure and a redacted fixture go in
  the repo; the `.pcap` does not.

A control run against the F18 in the same session is required for any claim — that
is what the `control-itroom-*` traces already are — and nothing graduates into the
library on one lucky run: reproducible, documented with hardware and date, fixture
and test, then a model-named file per rule 15, then `implemented`, then `confirmed`
only after it succeeds against the real terminal.

`tests/Unit/ProbeDisciplineTest.php` checks the mechanical part: every probe script
declares `READ-ONLY` or `WRITES TO THE DEVICE` in its header, every trace carries
`label`/`host`/`started_at`, and no `.pcap` is tracked. Verified by injecting an
undeclared script; it fails. It cannot check that a control was run.

**Correction made to this file while writing it:** the "Tooling, deliberately placed
where it survives" note above was wrong. `tools/` is untracked in its entirety — the
probe apparatus and all 43 traces live on this box only.

### 2026-09-14 — CLAUDE.md rule 15: one model per file

**Uncommitted, on branch `camelot`**, like everything below it.

Owner's instruction, taken as a standing rule rather than a one-off: the **F18 is
the only model working with no open issues**, and no other model is to be absorbed
into its code path. A model that needs different behaviour gets its own file named
for it — `packages/zkteco-protocol/src/<Layer>/<Model>/…` for protocol differences,
`app/Services/Devices/<Vendor>/` for another protocol entirely, as `Csl/` already
is — and the choice is made once, where the driver is built.

The reasoning worth keeping: the F22 already needs windowed reads where the F18
does not, Camelot needs an AUTH exchange the LAN F18s do not, and each of those is
one `if` away from living inside the working read path. Three of those and the one
device that works becomes the device nothing can be safely changed on.

`tests/Unit/DeviceModelIsolationTest.php` enforces the half that can be: a model
name may appear in a **comment** anywhere — that is how firmware findings get
recorded, and rule 12 asks for it — but not in executable code outside a file named
for that model. Verified both ways: an injected `if ($model === 'F22')` fails it, a
`CONFIRMED on a ZKTeco F18` comment does not. Rules are never renumbered now, since
code comments and `docs/` cite them by number; new ones are appended.

Not enforced, and deliberately: that the F18 was actually re-read after a shared
file changed.

### 2026-09-14 — Sync history can be cleared

**Uncommitted, on branch `camelot`, in this machine's working tree**, like
everything below it.

`DELETE /api/device-sync-runs` plus a danger button on `/app/device-sync-runs`.
It clears the same `device_id`/`status` filter the listing takes, so the screen
erases exactly what it is showing.

Three decisions worth keeping:

- **Gated on `devices.delete`, not `devices.sync`** (new `DevicePolicy::clearHistory`,
  class-level because there is no device instance). An operator who may run a sync
  should not be able to destroy the record of every sync before it. `operator` has
  sync and not delete, so this is a real distinction in the seeded roles.
- **The raw payload archive goes with the runs.** `attendance_raw_logs` is what made
  the 34% recovery possible, so the confirm dialog names that loss instead of saying
  "cannot be undone", and the toast reports the actual payload count deleted.
- **Runs still `running` are never deleted**, or `SyncProgressRecorder` and the
  progress poller end up writing to a row that is gone.

Imported punches and `devices.attendance_watermark` are untouched — the watermark
lives on the device row, so clearing history cannot make the next sync skip
records. `tests/Feature/Api/SyncHistoryClearTest.php` asserts all of that; every
clear writes a `syncrun.cleared` audit row, by hand, because a mass delete fires no
model events.

### 2026-09-14 — PDF exports, a Reports screen, and a date-filter timezone bug

**All of this is uncommitted, on branch `camelot`, in this machine's working tree.**

**1. Attendance rows open the employee behind the punch.** Clicking a row (or a
mobile card) opens `PunchEmployeeModal`: the punch that was clicked, the employee
fetched from `/api/employees/{id}`, their enrollment drawn with the roster's own
cells, and their last 8 punches. Punches with a null `employee_id` stay inert —
there is no record to open.

**2. Three PDF exports** (`ExportController`, `resources/views/pdf/`): the punch
log as filtered, one employee's attendance record, and the payroll summary. Added
`barryvdh/laravel-dompdf`. Rendered synchronously — these are read-only queries,
unlike device work. The punch log caps at 5,000 rows and prints a warning naming
the true total rather than truncating silently.

Each export re-applies the permissions of the screen it mirrors, and each is
computed by the SAME class the screen reads (`PunchLogQuery`,
`AttendanceSummaryService`, `PayrollReport`). A printout that disagrees with the
screen is the copy that gets filed, so there is deliberately no second code path.

**3. A Reports screen** (`/reports`, permission `reports.view`). The
`/api/reports/attendance` endpoint had existed since 2026-09-02 with nothing in
the SPA reaching it.

**4. A real bug found on the way: attendance date filters were shifted.**
`from`/`to` were compared as bare `YYYY-MM-DD` strings against the UTC
`punched_at` column, so with `ATTENDANCE_TIMEZONE=+03:00`:

- `from=2026-09-01` dropped every punch between 00:00 and 03:00 local on the 1st
- `to=2026-09-14` meant midnight, so it dropped **the whole of the 14th**

Both bounds now convert through `config('attendance.timezone')` in
`PunchLogQuery`, the same zone `AttendanceSummaryService` already used — so the
punch list, a summary and an export finally agree on what a date means. Covered by
`tests/Feature/Api/ExportTest::test_a_one_day_range_covers_that_whole_local_day`.
This is rule 10's trap in its filtering form and worth watching for elsewhere.

**5. Composer was unusable on this box, and fixing it moved the library.**
`composer require` of anything failed: the path repository `../../biometric-protocol`
resolves to `/var/biometric-protocol`, which did not exist. The vendor directory was
copied there first (so nothing could be lost), then composer installed normally and
replaced `vendor/promoseven/biometric-protocol` with a symlink to it. The library is
now at the path the layout always assumed — but it is still not a git repository and
still has no remote, so the blockers at the top of this file stand.

`php artisan test`: 206 passed.

### 2026-09-13 — Camelot (device 9) handshake solved; bulk read investigation paused

Stopped at the owner's request, mid-investigation.

Camelot had never completed a protocol session. Cause: it answers `CONNECT` with
`ACK_UNAUTH` and needs the `CMD_AUTH` step with comm key 0. Confirmed working over
UDP and TCP. The network path is fine (4.4 ms, no loss) — this was never a
connectivity problem, and the stored "timed out" error was misleading.

Its attendance table is 1,664,804 bytes, far larger than anything else in the
fleet, and the bulk read still fails. Two mechanisms identified from instrumented
traces, neither of them the receive buffer (`RcvbufErrors` zero throughout): the
device's send pacing degrades ~5x over the course of a session, and an aborted read
leaves it streaming to a closed port (`NoPorts` 682 in one run) so the next attempt
finds it busy.

Three agents (reader / network observer / control) were launched to test pacing,
session reset, flush duration and a second remote device, and were stopped before
producing results. See *Where we stopped* for the full experiment list, which is
written to be picked up as-is.

Harness and traces preserved at `tools/zkprobe/`.

### 2026-09-13 — "Incomplete transfer" FIXED with windowed reads; React #310 on device detail

Two unrelated faults, both fixed and verified.

**1. `/app/devices/6` crashed with React error #310.** `DeviceDetail.jsx` called
`useSyncProgress` *below* two early returns, so the first render (loading skeleton)
ran fewer hooks than the render after the fetch resolved. Moved the call above the
returns. Rebuilt assets (`node_modules` was absent; `npm install` then
`npm run build`). Swept the rest of `resources/js` per-function for the same
pattern — this was the only instance.

**2. Attendance sync on device 6 (F22, 192.168.7.63) now works.** Superseded the
earlier "the fix is TCP" conclusion — see *Where we stopped*. The library now
negotiates the protocol's windowed read (`CMD_PREPARE_BUFFER` / `CMD_READ_BUFFER`,
16 KB windows) per read and falls back to the push envelope when a device will not
stage a table. 0 of 3 → 6 of 6; full sync read 8,153 records, 0 undecodable.

The earlier buffer diagnosis was wrong, and the measurement that settles it is
cheap to repeat: `/proc/net/snmp` counted **3** `RcvbufErrors` across a read that
lost ~145,000 bytes. The kernel was not dropping anything; the device stopped
sending.

Also from the same investigation, both measured: the read timeout was being
re-applied with a syscall per datagram, and reassembly built a `Reply` object per
datagram. Removing both took capture from 67-69% to 100% in isolation
(`Contracts\SupportsRawDrain`). They are no longer decisive now that the windowed
path exists, but they still apply to every fallback read.

**The TCP findings are preserved, not discarded** — TCP remains a good idea for
devices that refuse to stage a table, and the confirmed framing is in the entry
below.

**Caveat that outranks both fixes:** the library change is in git-ignored
`vendor/`. It is not in any repository. See the blockers at the top of this file.

### 2026-09-13 — "Incomplete transfer" root-caused to UDP; TCP proven to fix it

Third report of the same failure on `192.168.7.63`. Root cause is the transport,
not the reader: the library is UDP-only and the transfer has no flow control. A
full read over TCP returns all 326,084 bytes in 0.03 s, 3 of 3 runs, 8,152 records
decoding clean. Full evidence and the implementation sketch under "Where we
stopped". The earlier 2026-09-13 sysctl conclusion is superseded.

Not implemented — the library source is not on this machine. No code was changed.

### 2026-09-13 — Entrance and Glass Door found never-commissioned; no queue worker

Separate investigation from the buffer one below, triggered by
`could not reach the device ... 192.168.7.60:4370 Glass Door did not answer`.

Not a code fault and unrelated to the `Incomplete transfer` issue. Both new
terminals ping and are genuine ZKTeco, but neither has ever answered the SDK on
4370 by any transport. Full evidence under operational facts above. Also found: no
queue worker configured on this machine. No code was changed.

### 2026-09-13 — "Incomplete transfer" on 192.168.7.63 diagnosed (NOT fixed)

Full attendance re-reads on `192.168.7.63` fail with
`Incomplete transfer: got <varies> of 326084 bytes` — observed at 179,652 / 183,296 /
184,320 / 185,344 / 218,564 / 250,880 across runs.

**Cause: the socket receive buffer, and nothing in the code.** Ruled out, with
measurements:

- Not the progress listener. It fails with no listener attached, and
  `canReportFreely` already evaluates to FALSE here, so `BulkReader` is in its safe
  coarse-reporting mode.
- Not drain speed. The loop reads 180 datagrams in **16 ms** (~11.6 MB/s), roughly
  30x faster than needed. The tail is already discarded by the kernel before PHP's
  first read — the device blasts the whole table with no flow control.
- Not a mis-set config. `attendance.device_receive_buffer` is 4 MB and reaches
  `DeviceConfig` correctly.
- The clamp: `rmem_max` 212992 -> `getsockopt` reports 425984 (Linux doubles it) ->
  the library correctly halves that to 212992 usable. Real capacity is lower still,
  ~183 KB, because each ~1029-byte datagram is charged ~2.4 KB of `skb` truesize.

**SUPERSEDED the same day** by the TCP finding — the sysctl was the wrong fix.
Kept here for the measurements, which stand. No app or library code was changed.

Newest first. Absolute dates only — never "yesterday" or "last week".

### 2026-09-02 — crash fixes, device attribution, library extraction, product pivot

**Started from** "run the project", which surfaced two live SPA crashes recorded in
`storage/logs/client-*.log` by the `/api/client-errors` endpoint.

**Crash 1 — `error is not defined`.** `EmployeeDetailModal.jsx` referenced an
undeclared `error` variable, left over from before rule 2 replaced inline banners
with toasts. Threw for any employee never read from a terminal. Fixed by removing
the dead condition.

**Crash 2 — `Objects are not valid as a React child`.** `error` is cast to `array`
on `AttendanceSyncRun` and `DeviceCommand`, so it reaches the browser as
`{exception, context}`. Six screens rendered it directly, which blanked the page
via the ErrorBoundary and made `/app/device-sync-runs` unopenable whenever any run
had failed. Fixed with `formatDeviceError()` / `<DeviceError>` in
`pages/devices/statusParts.jsx`, plus a guard test
(`test_structured_device_errors_are_never_rendered_as_a_react_child`) because six
files shipped the same mistake at once.

**Per-terminal enrollment attribution.** `employees.fingerprint_count`,
`fingerprint_slots`, `card_number` and `enrollment_synced_at` are a flat snapshot of
whichever terminal was read last — unattributable with two terminals. Added
`Employee::deviceMaps()`, exposed it as `enrollments` on `EmployeeResource`, added an
"Enrolled on" column, and scoped the detail modal to the selected device.

This closed a real hazard: the modal previously showed the last-read snapshot while
its `Delete` and `Remove from terminal` buttons addressed the *selected* device, so
an operator could read IT Room's slots `[1,6]` with Good Wheels selected and delete
against a terminal holding `[6,7]`.

A follow-up correction the same day: the roster cells still read the flat columns,
so every Good-Wheels-only employee showed `—` for fingerprints and card despite
having real slots in `employee_device_maps`. Replaced with `rosterEnrollment()`,
which sources glyph, count, card and label from the same most-recent row.
`syncedAgo()` had the same blind spot and reported "last read July" while a
terminal had been read that morning.

**Library extraction.** Renamed `Zkteco\Protocol` → `Promoseven\Biometric` and
`promoseven/zkteco-protocol` → `promoseven/biometric-protocol`: 253 replacements
across 83 files, leftover scan clean. Extracted to its own repo, added licence,
headers, README, `.gitattributes` (tests `export-ignore`d out of dist archives),
tagged `v1.0.0`. App repointed at it via path repository; `phpunit.xml` and
`ProtocolLibraryBoundaryTest` now resolve the library through
`vendor/promoseven/biometric-protocol`, so the boundary rules check what a licensee
actually receives.

**Corrections made to existing docs.** CLAUDE.md rule 8 claimed exactly one class in
`app/` may import the library; the enforcing test's allowlist has ~24 entries. Rule 8
now distinguishes *speaking* the protocol (only `ZktecoDriver`) from *naming* its
types (documented allowlist). The same stale claim was corrected in `README.md` and
`docs/change-log-or-roadmap.md`.

**Verification:** 178 tests passing, production build clean, every affected screen
driven in a real browser, client error log empty afterwards.

**Not committed.** All app-side changes are left in the working tree deliberately,
on top of a large pre-existing refactor, for review as one set.

---

## Operational facts a new machine needs

### Running it

```bash
php artisan serve --host=0.0.0.0 --port=8010     # or start-server.bat
npm run dev                                       # Vite on 5173
```

- `.env` has `APP_ENV=production`, `DB_CONNECTION=sqlite`, `APP_URL=http://192.168.1.180:8010`.
- `QUEUE_CONNECTION=database`. A worker now runs as the systemd service
  **`zkt-queue.service`** (`systemctl status zkt-queue`) — `php artisan queue:work
  database` as `www-data`, enabled at boot, added 2026-09-14. Every device action is
  queued (rule 11), so this must stay up; if queued commands stop running, check it
  first (`journalctl -u zkt-queue`). Remove with
  `systemctl disable --now zkt-queue` + delete `/etc/systemd/system/zkt-queue.service`.
- Stopping Vite deletes `public/hot`, after which the app serves the built assets
  from `public/build`. Both states are valid; rebuild with `npx vite build`.

### The terminals

| # | Terminal | Address | Model | Attendance table | State |
|---|---|---|---|---|---|
| 2 | IT Room | `192.168.7.62:4370` | F18 | 420,004 B | LAN. Reads clean, windowed, 100%. |
| 5 | Good Wheels | `46.184.163.106:4370` | ? | not measured | **Public IP.** Not yet re-tested. The most valuable untested control — a second remote device. |
| 6 | On The Road | `192.168.7.63:4370` | **F22** | 326,124 B | LAN. Push reads fail; **windowed reads fixed it**, 6 of 6. |
| 7 | Entrance | `192.168.7.61:4370` | **inBIO460** | **87,756 rows** | **WORKING 2026-09-14** through `C3Driver`. Session-less over TCP. Busiest terminal in the estate. Reads only; writes unbuilt. Device row not yet switched to protocol `c3`. |
| 8 | Glass Door | `192.168.7.60:4370` | **TF1700** | **21,005 rows** | **WORKING 2026-09-14** through `C3Driver` — and it is a C3/PullSDK panel, NOT standalone. **Requires `0x01`**; without it it reports zero records without erroring. Reads only. Device row not yet switched to protocol `c3`. |
| 9 | Camelot | `87.237.196.60:4370` | F18 | **1,664,804 B** | **Public IP.** Handshake solved 2026-09-13 (needs AUTH, key 0). Bulk read still fails — see *Where we stopped*. |

**All five reachable terminals now answer.** Two protocol families: the F18, F22 and
Camelot speak the ZKTeco **standalone** protocol; Entrance and Glass Door speak
**C3/PullSDK**. A model name does not tell you which — the TF1700 is sold as a
standalone fingerprint terminal and is not one.

Comm key is `0` on every standalone terminal that answers, including both public
ones. Note that "comm key 0" is not the same as "no auth": Camelot **demands the
AUTH exchange be performed** with key 0 and refuses the session otherwise, which is
exactly what made it look unreachable for so long.

**Unresolved security issue, now worse than recorded.** *Two* terminals are
reachable from the internet — Good Wheels and Camelot — and both accept comm key
`0`. Anyone who can reach those ports can read the staff list, card numbers and
attendance, and can delete enrollments. Camelot additionally answers on **TCP**
4370. Put both behind a VPN and close the port forwards. Raised 2026-09-02 for
Good Wheels; Camelot added 2026-09-13. Still not fixed.

### Entrance (.61) and Glass Door (.60) are BOTH C3/PullSDK panels — reads SOLVED

**Two earlier diagnoses in this file were wrong and are corrected here** (rule 14:
correct, don't append).

`.61` was never silent — it was being spoken to in the wrong protocol. An
**inBIO460 is a 4-door access-control PANEL**, speaking **C3 / PullSDK** over TCP
with a CRC-16/ARC frame. Solved 2026-09-14 by researching the model before probing
(rule 17).

**`.60` was the same mistake made twice.** This file previously said the TF1700's
comm/SDK service was switched off and owed Ghassan a menu action on the device.
**That was wrong. Nothing is owed and nothing needs touching.** The TF1700 also
speaks C3/PullSDK; it answered our existing C3 probe on the first attempt. The
telnet `ZEM560` banner is real but says nothing about which protocol the comm
service serves, and that inference is what produced the wrong answer.

Confirmed 2026-09-14, all READ-ONLY, traces in
`tools/zkprobe/traces-2026-09-14/inbio-*.json`:

| | Entrance `.61` | Glass Door `.60` |
|---|---|---|
| Model | inBIO460 | TF1700 |
| Serial | `AK2J183860073` | `ESK5242000009` |
| Firmware | `AC Ver 5.2.4 Apr 19 2016` | `Ver 6.64.0013 Oct 16 2018` |
| MAC | `00:17:61:12:34:9B` | `00:17:61:10:96:FD` |
| CONNECT `0x76`/`0x01` | **refused** (−13 / −98), runs session-less | **accepted** |
| Doors / readers | 4 / 4 | 1 / 1 |
| `transaction` pulled | 1,717,298 B, 337 chunks, 87,756 rows | 367,768 B, 72 chunks, 21,005 rows |
| CRC-32 | `3ee6a1f9` verified | `031eaee7` verified |
| Range (device local) | 2000-10-04 .. 2026-09-14 13:04 | 2025-08-10 17:28 .. 2026-09-14 14:46 |

**The `0x0D` buffered read is solved.** `GETDATA` stages its answer instead of
returning it: `0x0D` PREPARE_DATA → `0x0E` DATA (repeated) → `0x0F` FREE_DATA, with
a 17-byte header carrying a **CRC-32 that makes the whole read self-verifying**.
Recovered from the owner's own licensed ZKAccess 3.5.5 install (see the session log)
and implemented in `tools/zkprobe/inbio460.php`. Full byte layout in
`docs/device-capabilities.md`. No public C3 reimplementation has this path.

**What this does NOT mean.** There is still **no app driver for C3**, no capability
set, and no UI. Every write — enrollment, `SetDeviceData`, the door relay `0x05` —
is unbuilt and unprobed, and per rule 16 needs the owner's explicit go-ahead and a
terminal out of service first. The honest status is: the protocol is solved, the
integration is not built. Roughly **108,761 punches** sit on those two panels that
this app cannot yet read.

### Queue worker — FIXED 2026-09-14 (`zkt-queue.service`)

Was: no worker, no systemd unit, so every queued `DeviceCommand` sat unprocessed
and any UI action that queued one hung. Now a systemd service `zkt-queue.service`
runs `php artisan queue:work database` as `www-data`, enabled at boot and
`Restart=always`. Verified end to end: a queued F18 attendance sync ran to
`succeeded` (read 10,525, imported 21).

This unblocked the whole queued subsystem — device-detail syncs, group commands,
employee pushes — not just attendance. If queued commands stop, check the service
before the code.

### Local environment gotchas

- `devsense.php.ls.exe` (the VS Code PHP language server) holds directory handles
  and blocks renames and deletes under `packages/`. Close the editor first.
- **`packages/zkteco-protocol` is a stale leftover copy** of the library from before
  the extraction. Composer no longer references it. Delete it
  (`rm -rf packages/zkteco-protocol`) once the editor is closed, or it will drift
  and confuse you.

- **This app runs inside an UNPRIVILEGED LXC container, and that caps the UDP
  receive buffer.** `/proc/sys` is mounted read-only, so `net.core.rmem_max` is
  stuck at the 212992 default and cannot be raised from inside. `SO_RCVBUFFORCE`
  is refused too (CAP_NET_ADMIN is held only in the container's own user
  namespace). The library asks for 4 MB and the kernel clamps it. This is real and
  worth knowing, but it is NO LONGER the blocker for bulk reads — see the TCP
  finding under "Where we stopped". Raising the sysctl on the Proxmox host is now
  a fallback, not the plan.
- **`/etc/sysctl.d/99-zkteco-udp.conf` inside the container is INERT.** It sets
  `net.core.rmem_max = 16777216` and looks like the fix is in place. It is not, and
  it never can be — `sysctl -p` on it returns `Invalid argument`. Always check the
  RUNNING value (`sysctl net.core.rmem_max`), never the file. The fix belongs on the
  Proxmox host, not here.

---

## Housekeeping debt

- Stale `packages/zkteco-protocol` directory, above.
- **The library is UDP-only, and that is the root of "Incomplete transfer".**
  See the TCP finding under "Where we stopped" — this is now a known fix awaiting
  implementation, not open debt.
- `employee_device_maps` binds device users to *this app's* `employees` table. In the
  product that mapping must belong to the caller — the single biggest change still
  needed for the middleware model.
- Library test coverage is `TemplateCodec` and `BulkReader` only. Transport,
  sessions and the four services are exercised in production, not by unit tests.
- `composer audit` reports 12 advisories affecting 2 packages. Not reviewed.
- `docs/` was written when this repo was the product. Several files still read as
  though the payroll app is the deliverable; they need a pass under the middleware
  framing.
- **`tools/` is untracked in its entirety** — `Probe.php`, `harness.php`,
  `experiment.php` and 43 traces. Rule 16 requires traces to be committed; they are
  the evidence for the pacing, flush and windowed-read findings in this file.
- The library's one fixture, `tests/Fixtures/fingerprint-template-table.bin`, is an
  F18 capture but is not named for the model it came off. Rule 15 asks for that from
  now on; renaming this one means a library version bump, so it is left as is until
  a second model's capture lands beside it.
