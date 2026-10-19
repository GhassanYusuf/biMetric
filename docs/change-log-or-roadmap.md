# Change log and roadmap

> This file is the retrospective: what changed in the rebuild, and what is planned.
> For **where the work stopped and what is in flight right now**, read
> `docs/PROJECT-STATE.md` (CLAUDE.md rule 14).

## What changed in this rebuild

### An integration API for other software (2026-10-08)

`/api/v1` lets an HRMS/ERP/payroll system manage employees (with its own `external_id`
and idempotent upsert), remove people from terminals, trigger fingerprint/card
enrollment, read punches as a gap-free cursor feed, and receive signed webhooks.
Scoped Sanctum tokens, per-token rate limit, `Idempotency-Key` replay. Device work is
queued and reported as command ids. See [integration-api.md](integration-api.md) —
including what was deliberately left out.

### The protocol became a library

Device code was spread through controllers and services. It is now
`promoseven/biometric-protocol` — 31 classes, zero framework dependencies, installable
into a CLI tool or another platform.

Only `ZktecoDriver` may *speak* the protocol; a documented allowlist in
`tests/Unit/ProtocolLibraryBoundaryTest.php` covers the classes that merely *name*
its types, and the build fails if either changes.

As of 2026-09-02 the library is no longer in this repository at all — it is its own
repo, licensed separately, consumed through composer. See `docs/PROJECT-STATE.md`.

### The duplicate protocol implementation was retired

`app/Services/Zkteco/` held a SECOND, older implementation of the same binary
protocol — `ZktecoDevice`, `ZktecoProtocol`, `ZktecoConnection` — plus
`DeviceSyncService`, which was still the engine behind the legacy Inertia screens
and the SPA's own device endpoints. Roughly 2,700 lines, live, in parallel with the
library. It is gone.

What moved where:

| Was | Now |
|---|---|
| `ZktecoDevice` / `ZktecoProtocol` / `ZktecoConnection` | `promoseven/biometric-protocol` (already existed) |
| `DeviceSyncService` attendance | `AttendanceSyncService` |
| `DeviceSyncService` users/enrollment reads | `DeviceUserSyncService` |
| `DeviceSyncService` captures and deletions | `EnrollmentOrchestratorService` |
| `DeviceSyncService::testConnection` | `DeviceHealthService::check` |
| `CslWebPortalClient` | `App\Services\Devices\Csl\CslWebPortalClient`, behind `CslHttpDriver` |

Two seams were added to make the port possible without reaching around the
contract: `Transport::selectableStream()` in the library, so one process can wait
on many terminals; and `BiometricDeviceDriver::open()`, a session the caller owns,
for the realtime listener alone.

### A second vendor, for real

`CslHttpDriver` implements the same contract over the CSL web firmware, and
`DeviceDriverManager` dispatches by `devices.protocol`. Nothing above the contract
changed to gain it — no service, controller or route. `AttendanceSyncService` gave
up knowing how a device is read (`driver->readAttendance()`) and works for both.

### The legacy Inertia app was retired

Devices, Employees, Attendance, Holidays and the dashboard existed twice: once in
the SPA and once in Inertia, on two different device stacks. The Inertia versions
are deleted. `/` and `/dashboard` redirect to `/app`.

Auth and profile remain on Inertia, because the SPA has no password-reset,
email-verification or account-deletion screen yet. That is the last piece to
migrate.

### Device work became queued and visible

Previously an operation ran inside the HTTP request. Now every one writes a
`device_commands` row and returns its id. New tables: `device_commands`,
`attendance_sync_runs`, `attendance_raw_logs`, `device_status_logs`,
`employee_device_maps`, `enrollment_sessions`, `access_cards`,
`biometric_templates`, `device_groups`.

The practical difference: a fingerprint capture no longer holds a web worker for
30 seconds, and closing the browser no longer destroys all record that an
enrollment was attempted.

### Capabilities replaced assumptions

Four levels — `confirmed` / `implemented` / `not_implemented` / `unknown` — gate
every device action at the API (422 before queueing) and in the UI. An unproven
feature is offered and labelled *"unproven"* rather than being presented as
working or hidden as missing.

### 34% of attendance history was recovered

The timestamp decoder used `createFromFormat('Y-n-j-G-i-s', …)`. `i` and `s`
require two digits, so any punch with a minute or second below 10 returned `false`
and was silently dropped: **3,236 of 9,582 records**, for the life of the code,
with nothing counting it.

Fixed, re-synced, **2,823 records recovered**, table now matches the device at
9,582 exactly.

**These records go back to 2023 and change historical payroll totals.** Any report
run before this fix understated hours.

### Timezone handling was corrected twice

- `insertOrIgnore` bypasses Eloquent casts → device-local time written as UTC →
  **6,432 duplicated rows** at a 3-hour offset. 6,364 deleted; 3-hour pairs fell
  from 6,432 to 6 (genuine in/out punches).
- The sync watermark had the same bug: `17:59+00:00` where the truth was `14:59`,
  which would have skipped three hours of real punches on every sync.

### Bulk reads became flow-controlled (2026-09-13)

A second terminal (**F22**, device 6) could not complete an attendance read. The
326,124-byte table arrived 54-75% of the time and then the device went silent.

The receive-buffer fix that solved this on the F18 did nothing here, and
measurement said why: `/proc/net/snmp` counted **three** kernel drops across a read
that lost ~145,000 bytes. Nothing was being dropped — the device was stopping.

`BulkReader` now prefers the protocol's windowed read (`CMD_PREPARE_BUFFER` /
`CMD_READ_BUFFER`), requesting one 16 KB window at a time so there is no burst to
race, and falls back to the push envelope when a device will not stage a table.
Support turned out to be **per table**: the same F22 stages attendance and
templates but refuses users.

Attendance on that device went from 0 of 3 to 6 of 6; 18 of 18 library reads across
all three tables. Two supporting changes came out of the same investigation: the
read timeout is no longer re-applied per datagram, and reassembly can take a
datagram's payload without building a `Reply` (`Contracts\SupportsRawDrain`) — worth
67% → 100% capture in isolation.

### Windowed reads resume across a stall on a public link (2026-09-14)

The **Camelot F18** (device 9, public IP, 1,664,804-byte attendance table) could not
complete a read. A packet capture of the failing read settled the cause: **not** the
receive buffer (`RcvbufErrors` delta 0, socket rx-queue never backed up) and **not**
corruption (`InErrors` 0) — the device streamed ~490 KB smoothly, then a 16 KB window
went silent for >8 s. The reader used to throw the whole transfer away on that first
silence and restart from offset 0, so across five whole-request attempts it read ~⅓
of the table and never finished.

`BulkReader` now **resumes** instead of restarting, in two escalating steps gated on
`DeviceConfig::$bulkWindowRetries` (default 4, env `DEVICE_WINDOW_RETRIES`): re-request
the stalled window at the same offset; and if that is spent — because a stall wedges
the whole staged session on this firmware — reopen the session (with a backoff, since
Camelot refuses an immediate reconnect), re-stage, and continue from the same offset.
A `READ_BUFFER` request timeout counts as a stall; a `FREE_DATA` release timeout no
longer fails a completed read. This is the ninth new library test group; see
`WindowedReadTest`. It also produced the first CONFIRMED evidence that the **F18
supports windowed reads**, not only the F22.

Recorded as library **2.1.0** (`CHANGELOG.md`). The raw packet capture stays out of
the repo (attendance PII, rule 16); the structural summary is in
`tools/zkprobe/traces-2026-09-14/`.

### Session hygiene fixed the template reads

Two bulk reads on one connection returned the first read's data, because the
device keeps sending after `CMD_PREPARE_DATA` and the leftovers look like valid
replies. Symptoms: "fingerprints unknown" for everyone, a probe reporting 0 users
on a 19-user device, template probes shifted by one request.

Fixed structurally with a fresh session per bulk read plus dirty-tracking.

### Five screens were added

Device detail, device groups, sync history, enrollment sessions, reconciliation —
each with separate mobile and desktop presentations, on the existing design
system.

### Documentation became part of the change

`CLAUDE.md` gained rules 8–13 covering the library boundary, capability honesty,
queueing, and a change → documentation mapping table.

---

### The legacy ZKAccess database is importable

`10-06-2026.mdb` — the old ZKAccess attendance-software backup (a Jet 4 .mdb on the
Windows host) — can now be brought into this system with a two-step pipeline that
never talks to a stopped source of truth twice:

1. `scripts/export-zkaccess.ps1` on Windows (Access ODBC driver) dumps the MDB into
   one canonical `zkaccess-export.json`.
2. `php artisan zkaccess:import file.json` merges it: dry-run by default, `--apply`
   to commit, `--tenant`, `--no-create-new`, `--skip-attendance` as levers.

Hard facts the importer encodes, all measured against the live database:

- People match on `Badgenumber` == `employee_code` (304/338), then by normalised
  name (a name shared by two employees is neither merged nor created).
- Punches are attributed by ZKAccess `USERID` (stable across PIN renumbering) but
  written with the PIN as `device_user_id`, so the existing unique index
  `(device_id, device_user_id, punched_at, verify_method)` dedupes against live rows.
- `CHECKTIME` is local Iraq time (UTC+3); `punched_at` is stored UTC, exactly like
  live sync — 99.2% of the export cancels out to existing rows at that offset.
- `VERIFYCODE` is copied verbatim (proved 1:1); `privilege` is deliberately skipped
  (no table to our string enum).

First run against production: 7 departments created (was an empty table), 20
employees created, profiles filled up to the measured fill rates, 673 attendance
rows inserted (the export was ~10 days ahead of the stopped live sync). Everything
idempotent — a re-run converges to "0 created, 0 inserted".

---

## Known unknowns
Carried deliberately, not forgotten. Each is a claim we have **not** earned.

| | |
|---|---|
| **Fingerprint capture never completed** | The trigger is confirmed — the device beeps and the trail proves the command lands. A successful capture requires a person at the terminal at the moment the job runs, and every attempt so far ended in `timeout`. |
| **Template decoding unverified elsewhere** | Correct against one 22,066-byte table from one F18 on one firmware. Fails safe to "unknown" on any misalignment. |
| **`verify_method` 3 and 4** | Emitted by this device, meaning unknown. Stored verbatim rather than mapped to a guess. |
| **Card `4294967295`** | `0xFFFFFFFF` on a real employee record. Flagged as a sentinel; we do not know if the device means "none", "error", or a real value. |
| **The device clock is ~6.4 minutes fast** | And drifting. Every punch is stamped early. `SET_TIME` is `implemented`, never confirmed, so the correction has **not** been fired — writing a wrong clock would misstamp everything after it. |
| **1,417 unattributed punches** | From 52 former employees whose records were removed from the terminal. Kept with a null employee so the gap is visible rather than silently deleted. |
| **Access-control capabilities** | 5 keys never probed. They may work perfectly. We claim nothing. |

---

## Roadmap

### Next

1. **Confirm a fingerprint capture on hardware.** Needs a person at the terminal.
   This single event validates the enrollment path end to end and would promote
   several capability levels.
2. **Decide about the device clock.** Either fire `sync_time` under supervision
   and promote it to `confirmed`, or document the offset as accepted. Leaving a
   drifting clock undecided is the worst of the three options.
3. **Verify `template.delete`.** The `CMD_DELETE_USERTEMP` payload is inferred.
   One controlled test on a disposable enrollment settles it.
4. **Probe the access-control capabilities** and replace five `unknown`s with
   evidence.

### After that

5. **A second device.** Everything is calibrated against one F18 on one firmware.
   A second unit is what turns "works" into "works generally" — especially for
   template decoding.
6. **Real-time attendance.** `REG_EVENT` is implemented but a live punch has never
   been observed arriving through it. Confirming it would remove polling latency.
7. **Decode `verify_method` 3 and 4** by correlating punches with known
   authentication methods.
8. **A CSL HTTP driver** behind the same `BiometricDeviceDriver` contract, for
   reseller-branded units that do not speak the binary protocol.
9. **Tag and publish the library** — it is consumed as a path repository today.

### Not planned

- Template download/upload. Transferring biometric blobs between devices raises
  storage and consent questions that should be answered before the code exists,
  not after.
- Automatic reconciliation. Deciding whose version wins either deletes a real
  enrollment or restores a departed employee's building access. It stays an
  operator decision.
