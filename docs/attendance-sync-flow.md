# Attendance sync flow

## The shape of it

```
POST /api/devices/{id}/commands  {command: sync_attendance, full: false}
        │  capability gate: attendance.read must be usable → else 422
        ▼
device_commands row (queued)         ← the operator's handle on this work
        ▼
ProcessDeviceCommand  (WithoutOverlapping per device)
        ▼
AttendanceSyncService::sync()
        ├── open attendance_sync_runs row (running)
        ├── driver->readAttendance()    ← transfer AND decode, both vendor-specific
        │       ZktecoDriver: UDP bulk read → AttendanceCodec + Codec\Timestamp
        │       CslHttpDriver: web form POST → tab-separated export parse
        │       device sends its ENTIRE log; there is no server-side filter
        ├── archive raw payloads        → attendance_raw_logs
        ├── filter to > watermark
        ├── map device user id → employee
        ├── de-dupe on the unique key, then upsert (no-op on match) in
        │   parameter-safe chunks, punched_at explicitly ->utc()
        ├── advance watermark (UTC)
        └── close run with five counts
```

### Where the vendor seam sits, and why there

`readAttendance()` is the only vendor-specific step. Everything after it —
watermarks, employee mapping, dedupe, archiving, the five counts — is identical
whatever the terminal is, so it lives once in `AttendanceSyncService` rather than
twice in two drivers.

The contract requires every returned record to carry its own `raw` payload, and to
be returned even when it did not decode (no `timestamp` key). That is what makes
"archive before parsing" possible without the service knowing whether it is holding
binary chunks or lines of text.

## Live punches

The realtime listener (`zkteco:listen`) does not go through `sync()`. It calls
`AttendanceSyncService::recordRealtimePunch()` per push, which applies the same
employee mapping, the same explicit UTC conversion and the same unique index — and
then, unlike the bulk importer, fires `PunchRecorded`.

That asymmetry is deliberate. `PunchRecorded` drives the live MQTT update; firing
it during a backfill would push thousands of "someone just clocked in" messages for
punches that happened days ago. Immediacy is the listener's job; the importer is an
importer.

Binary-protocol devices only. CSL/HTTP firmware declares `attendance.realtime` as
`not_implemented`, and the listener skips it before opening a connection.

## Incremental means what it stores, not what it transfers

The protocol has no "records since X" request. The device always sends
everything it holds. (The windowed read requests the table in 16 KB pieces, but
that is transport framing — the *table* is still the whole log, so this stays
true.) Incremental sync therefore reads the whole log every time
and discards what it already has — which is why a healthy run on a settled device
looks like:

```
read=8153  imported=0  duplicate=0  unmatched=0  undecodable=0
```

That is **correct**, not a failure, and the UI says so explicitly. Anyone reading
"imported 0" as broken will start firing full re-reads at a working system.

**`duplicate=0` there is not a contradiction.** A record at or below the watermark
is skipped in `ingest()` before it ever becomes a candidate, so it is never counted
as a duplicate — `duplicate` is only `count($candidates) - imported`. The count
therefore means "records we genuinely offered to the unique index and it rejected",
which is what you want when judging whether dedupe is working.

A **full** re-read passes no watermark, so every record becomes a candidate and the
same settled device reports `read=8153 imported=0 duplicate=8153` instead. Comparing
the two across modes is meaningless; compare within a mode.

## The six counts

Recorded per run, because collapsing them hides the only information that
distinguishes a healthy sync from a broken one.

| Count | Meaning | When to worry |
|---|---|---|
| `read` | records the device offered | zero on a device with punches = read failure |
| `imported` | new rows stored | zero is normal on a settled device |
| `duplicate` | already known, skipped | high is normal |
| `unmatched` | device user id maps to no employee | non-zero = former staff or a missing map |
| `undecodable` | **offered but could not be decoded** | **any non-zero value needs attention** |
| `out_of_range` | outside the requested date window | always 0 unless the run was a range read |
| `future` | decoded, but stamped more than a day ahead of now — archived (`skip_reason = future_timestamp`), not imported, never the watermark | any non-zero value: misaligned reads (DC-020) or a clock set ahead |

`future` (2026-10-18) is in the command's `raw_response.counts`; the run row's single
`records_undecodable` column carries `undecodable + future`, since neither is a usable
punch. Before it existed Camelot's misaligned reads stored 1,497 punches dated up to
2030, and the newest of them could have become the watermark — after which every
incremental read would have skipped real punches below it.

`out_of_range` exists for the same reason `undecodable` does: a record the importer
decided not to store is **counted**, never silently dropped. It is what makes a
range read's `read 9,579, imported 640` legible instead of alarming.

`undecodable` rows are archived in `attendance_raw_logs` for reparsing rather than
discarded. That archive is the reason a decoder bug was recoverable rather than
permanent.

## Two timezone bugs, both real, both in production

The device stamps records with **its own local time** (`+03:00`), carrying no
timezone. Everything downstream stores UTC. Two separate mistakes came from that
gap.

### 1. The bulk insert bypasses Eloquent casts

The fast path writes device-local time straight into a UTC column. The result was
**6,432 rows duplicated at exactly a 3-hour offset** — the same punch stored twice,
three hours apart.

Fix: convert explicitly at the boundary, where the fast path cannot skip it.

```php
'punched_at' => Carbon::instance($c['punched_at'])->utc()->format('Y-m-d H:i:s'),
```

6,364 bad rows were deleted; the count of records exactly 3 hours apart dropped
from 6,432 to 6 — and those 6 are genuine in/out pairs.

### 2. The watermark had the same bug

The stored watermark read `17:59+00:00` when the true UTC moment was `14:59`. A
watermark three hours in the future silently skips three hours of real punches on
every subsequent sync — a data loss that leaves no error anywhere.

```php
'attendance_watermark' => $newWatermark === null ? null
    : Carbon::instance($newWatermark)->utc(),
```

**If you change anything in this path, check the watermark is not in the future.**
That single assertion catches the entire class of bug.

## The 34% data loss

This is the most important thing in this document.

The original decoder built timestamps like this:

```php
\DateTime::createFromFormat('Y-n-j-G-i-s', "{$y}-{$m}-{$d}-{$h}-{$min}-{$s}");
```

`i` and `s` require **two digits**. Any punch whose minute or second was less than
10 produced `false`, and the record was silently dropped.

**3,236 of 9,582 records — 34% — were discarded**, from the day the code was
written, with no error logged and no count reported anywhere.

The fix pads every component and anchors to the device's zone:

```php
$date = \DateTimeImmutable::createFromFormat(
    'Y-m-d H:i:s',
    sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
    new \DateTimeZone($timezone),
);
```

A full re-sync recovered **2,823 records**, and `attendance_logs` now holds exactly
9,582 — matching the device.

Two consequences worth stating plainly:

- The recovered records go back to **2023** and **change historical payroll
  totals**. Any report run before the fix understated hours.
- The bug was invisible because nothing counted what it threw away. That is why
  `undecodable` is now a first-class count on every run.

## Attribution resolves through TIME, not through the current mapping

Rewritten 2026-09-15. A punch is attributed to whoever held that PIN **at the moment it
was made** — `EmployeeDeviceMap::scopeInForceAt()`, and `employeeAt()` inside the bulk
loop, which resolves in PHP so a 90,000-record table does not become 90,000 queries.

Taking the *current* mapping instead is wrong in a way nothing on screen would show.
Terminals reassign a departed employee's number to a new hire; resolving old punches to
the present holder credits the leaver's entire history to the joiner.

A mapping adopted off hardware has `valid_from = null` — unbounded start. The person was
already enrolled when the terminal was first read and we do not know since when, so a
null bound is the honest statement. Stamping the claim date instead would orphan every
punch older than it, which on the Glass Door panel is 87,000 records.

### Re-attributing after the fact

Claiming a device user is a human decision that usually lands *after* the sync. So
attribution is recomputable:

```
php artisan attendance:reattribute [device] [--dry-run]
```

It only ever recomputes `employee_id` from the mappings; it never invents, moves or
deletes a punch, and never alters the raw `device_user_id` it recomputes from. Safe to
re-run. Without it, claiming an existing terminal would only affect the future — and
nearly all of such a terminal's attendance is in the past.

## Unattributed punches

Punches whose PIN resolves to nobody are **kept** with `employee_id = null`, counted as
`unmatched`, and never guessed at. A plausible employee id is a lie; "unmatched" is a
true statement about what we know (rule 10). Deleting them would quietly remove real
hours from history.

Measured across the estate on 2026-09-15, after a full re-read:

| Device | Unmatched PINs | Still on the device | Gone from the device |
|---|---|---|---|
| IT Room | 59 | 3 (undecided) | 56 |
| On The Road | 12 | 2 (undecided) | 10 |
| Glass Door | 85 | 16 (undecided) | 69 |
| Entrance | 9 | 0 | 9 |

The large majority are PINs **deleted from the terminal** — leavers whose user record
was removed while their punches remained. That attribution is unrecoverable. The
minority still on the device become attributable as soon as an operator claims them,
via `attendance:reattribute`.

## Full re-read

**It does not change what comes off the terminal.** `ATTLOG_RRQ` always returns the
whole table — this protocol has no "punches since X" — so every sync already
transfers everything. `full` only stops the watermark filtering those records out
before import. It costs database work, not network.

Existing punches are recognised and skipped by the unique index, so re-running is
always safe: it can add rows, never duplicate them.

Use it after a decoder fix, or when the watermark is suspect.

### Four ways to fire one

| Where | How |
|---|---|
| Device detail screen | the **Full re-read** button, which confirms first |
| Devices list | the **Full re-read** menu item, same confirmation, same words |
| API | `POST /api/devices/{id}/commands` `{"command":"sync_attendance","full":true}` |
| API | `POST /api/devices/{id}/sync-attendance` `{"full":true}` |
| Console | `php artisan app:sync-biometric-devices --full [--device=2]` |

**Every one of them queues.** Both HTTP routes return a `DeviceCommand` at once and
the read happens on a worker, so `php artisan queue:work` must be running or nothing
moves. Neither route runs the read inside the request any more: a full re-read of a
public-IP terminal takes minutes, and the front proxy used to time the request out
(a 524) long before the device finished, which made a working read look like a
failure.

A response reports which it did:

```json
{ "mode": "full", "watermark_before": null,
  "counts": { "read": 10303, "imported": 0, "duplicate": 10303 } }
```

CONFIRMED on a ZKTeco F18: a full re-read of a 10,303-record terminal reported
`10,303 already known` and imported nothing, while the same table run incrementally
imported the single new punch. The dedupe index is what makes that safe.

## Date-range read

The third mode, and the one an operator reaches for most often in practice: import
**only the punches recorded between two days**, inclusive of both.

```
POST /api/devices/{id}/sync-attendance  { "from": "2026-08-01", "to": "2026-08-31" }
```

It exists because the two existing modes answer the wrong question when something
needs repairing. An incremental sync will not look below the watermark, and a full
re-read reconsiders 90,000 records to fix one month. A range read fixes the month.

### The three things that make it trustworthy

**1. It is a client-side window, and the docs must not pretend otherwise.** The
terminal still transfers its entire table — `ATTLOG_RRQ` has no date filter, exactly
as it has no "since X". A range does **not** make the read quicker; it bounds what
is imported. The dialog says so on screen, because an operator told a range was
"faster" will report the progress bar as broken.

**2. The window is the DEVICE's calendar day.** Punches carry the terminal's local
offset (`+03:00` here), so the bounds are built in `$device->timezone` — falling back
to `attendance.device_timezone` — and widened to that day's start and end. Built in
the app timezone instead they would be three hours out at both ends, and an evening's
punches would land in the wrong day. That is the same class of bug as the two
recorded below, which is why it has its own test:
`AttendanceRangeSyncTest::test_the_window_is_the_devices_calendar_day_not_the_servers`.

**3. It does NOT move the watermark, and it ignores it.**

The window *replaces* the watermark rather than narrowing it. Narrowing would make
the commonest request impossible — the month being repaired is nearly always one the
watermark has already passed — and the run would report `read 9,579, imported 0`.

Not moving it is the safety property. The watermark means "ordinary syncing has
considered everything up to here". A backfill of last March says nothing about that:
dragged backwards, the next sync merely reconsiders six months; dragged *forwards* by
a window ending today, every punch between the old watermark and today would be
skipped **permanently**. Leaving it alone can cost extra work and can never lose a
punch — the same principle as the dedupe index.

### What the run records

`mode` is `range`, and `range_from` / `range_to` store the two dates as plain days.
Without them the history cannot tell a range read from a full one, and "why did this
import 1 of 9,579?" has no answer on the row. The API returns them as `Y-m-d`
strings, not ISO instants, so a browser cannot shift them into its own timezone and
display the wrong day.

Records outside the window are counted in `out_of_range` and are **not** archived to
`attendance_raw_logs`. That is deliberate: a range read is a targeted import, and
archiving the remainder would write the terminal's whole table as raw rows on every
run. A full re-read is the thing that archives everything.

### Where it is offered

The devices list (**Actions → Read a date range…**) and device detail, from one
dialog file, with presets for last 7 days / this month / last month. Both are gated
on `attendance.read` like every other read (rule 9). A backwards window is refused
with a 422 on the field before anything is queued — it could only ever import
nothing, and a run that reads a whole terminal to import nothing is
indistinguishable on screen from a terminal that has stopped recording.

## The daily past-day read, after the clock sync (2026-10-11)

Owner: "every day at 5 am sync the time from the server to the biometrics … after that,
read attendance from all devices from the past day". `App\Services\Devices\
DeviceMorningRoutine`, checked every minute by the scheduler (`device-morning-routine`):

- **When:** `devices.clock_sync_schedule` (default `0 5 * * *`) in the **company's**
  timezone — 05:00 in Bahrain is 02:00 UTC.
- **Per terminal, in order on its own queue:** `sync_time` (clock set from the server,
  read back), then `sync_attendance` with `from` = yesterday, `to` = today — dates in the
  terminal's own zone. One command at a time per terminal, so the read runs after the
  clock is right.
- **Safe to repeat:** a range read REPLACES the watermark for that run but never moves
  it, and the unique punch index makes a re-read punch a no-op — so it neither
  duplicates nor skips anything, and the scheduled watermark read (above) is unaffected.
- **Switches:** `devices.clock_sync_enabled` and `devices.daily_read_enabled`, both on by
  default; Settings → *Daily clock sync and attendance read*. A terminal with a read
  already queued or running is not given another.
- **Cost:** the protocol cannot filter, so each read still transfers the terminal's whole
  table (minutes on Glass Door) — once a day, in the early morning.
- Runs only once cron runs `schedule:run`.

## Live progress, and why it is coarse

A sync of a 10,000-record terminal takes tens of seconds. `attendance_sync_runs`
now carries an in-flight view alongside its result counters, so a UI can show how
much is left rather than a spinner:

| Column | Meaning |
|---|---|
| `progress_phase` | `reading` (off the device) or `importing` (into the database) |
| `progress_read` / `progress_total` | how far, out of how much |
| `progress_unit` | `records` or `bytes` — see below |
| `progress_attempt` | a retry restarts the count, so the bar can explain itself |
| `progress_updated_at` | lets a poller tell "working" from "the worker died" |

`progress_total` is **nullable and null means the device declared no total** — not
zero. A small table arrives inline with no announced size and a CSL terminal
announces nothing at all. The UI renders that as an indeterminate bar. Rule 10:
"we were not told how much" and "there is none" must never look the same.

### Bytes, records, and who converts

The transport honestly knows only **bytes** — `CMD_PREPARE_DATA` declares a total
and the reassembler counts what arrives. It is generic over users, attendance and
templates, so it cannot know a record width.

`ZktecoDriver` converts, because it is the only layer allowed to know both: it
derives the width with `RecordBuffer::detectRecordSize()` from the declared total,
exactly as the decoder later derives it from the received total. When no known
width divides the total, it reports **bytes** and says so in `progress_unit`
rather than inventing a record count. A screen that ignored the unit would tell an
operator there were 412,084 punches to read.

### THE THROTTLE IS LOAD-BEARING — measured, not guessed

Reporting progress from inside the transfer loop **breaks the transfer**. This
protocol has no flow control: the device streams UDP as fast as it can, and
anything not read promptly overflows the socket buffer and is gone.

Measured on a ZKTeco F18, 412,084-byte / 10,302-record table, same read repeated:

| Listener | Transfers completed |
|---|---|
| none attached | 6 of 6 |
| attached, doing nothing | 2 of 3 |
| attached, one throttled `UPDATE` per event | **0 of 3** |

The write is not slow — instrumentation showed 443ms of listener time across a 46s
read, worst single call 17ms. The damage is indirect: one badly-timed 10ms block
loses datagrams, the reassembler waits out its 8s silence timeout, and all five
whole-request retries burn. A tenth of a second of writing became a forty-second
failure and a `502`.

### The actual root cause: a 64 KB buffer

Chasing that led to the real fault, which was never the progress code. **The OS
default socket receive buffer is 64 KB; the table is 412 KB.** Any pause at all
overflowed it, and the kernel dropped the remainder. The progress write was merely
the most reliable way to cause a pause.

`UdpTransport` now requests **4 MB** (`DEVICE_RECEIVE_BUFFER`), so the whole
transfer fits. Re-measured: **25 of 26** reads complete, against roughly 1 in 3
failing before — and that fixed the baseline failures too, not just the ones the
progress code was provoking.

### The F22 needed a different fix entirely

The buffer fix above is real and it fixed the F18. It did **not** fix the second
device (F22, app device 6). There, a 326,124-byte attendance table failed at
roughly 54-75% on nearly every attempt, and measurement ruled the buffer out
completely: `/proc/net/snmp` counted **three** kernel drops across a read that lost
about 145,000 bytes. The device was not being outrun — it was stopping.

The library now prefers a **flow-controlled windowed read** on every bulk transfer:
`CMD_PREPARE_BUFFER` stages the table, then one explicit 16 KB `CMD_READ_BUFFER`
window at a time, so there is no burst to race. Attendance on that device went from
**0 of 3** to **6 of 6**, 8,153 records every time.

Support is negotiated per read and is **per table** — the same F22 stages
attendance and templates but refuses users — so the push path above is still live
and still matters. Full detail and the measurements are in
[protocol-overview.md](protocol-overview.md#windowed-reads-the-flow-controlled-alternative-confirmed-f22).

### Resuming a stalled window on a lossy link (2026-09-14)

On a LAN a window never stalls. Over a **public link it does**, and a stall on this
firmware wedges the whole staged session — every retry at that offset then fails too.
The windowed read now recovers in two escalating steps, both gated on
`DeviceConfig::$bulkWindowRetries` (`attendance.device_window_retries`, default 4):

1. **Retry the window at the same offset.** The staged copy is intact, so a lost
   window is simply re-requested; the bytes already pulled are kept.
2. **If retries are spent, reopen the session, re-stage, and resume from the same
   offset.** PREPARE_BUFFER always stages the whole table and READ_BUFFER is
   offset-addressed, so a fresh session continues where the wedged one stopped
   rather than restarting from zero.

CONFIRMED on the Camelot F18 over a public IP: the device serves ~490 KB per session
before wedging, so a 1.66 MB table completes across a handful of reconnects. This is
distinct from the receive buffer, which only stops the *kernel* dropping a burst — a
packet capture of the failing read showed zero `RcvbufErrors`; the device was going
silent, not the socket overflowing.

### What that means for reporting, now

On the **windowed** path, progress is reported **once per window**, always. Nothing
is in flight while a listener runs, so reporting cannot cost a datagram and the bar
is smooth regardless of buffer size or `ext-sockets`.

On the **push** path, `BulkReader` **adapts to the headroom it actually has**:

- **buffer ≥ declared size** → report on every chunk. A pause cannot cost a
  datagram, so the bar is smooth. This is the `ext-sockets` path.
- **otherwise** (no `ext-sockets`, or a platform that clamped the request) → report
  only after three consecutive silent polls, plus once before and after the loop.
  Coarse, but it cannot lose punches.

Two defences remain regardless, and must stay:

1. **Live writes go to the file cache, not SQLite.** SQLite has one writer and this
   app's queue, cache and sessions share it. The run row is written at phase
   boundaries only.
2. **Writes are throttled** — ~0.75s or a 5% jump. This was 2s/20% while writes were
   genuinely dangerous; it was loosened once the buffer fix removed the danger.

Do not collapse the two reporting branches. The unbuffered one is what a fresh
install without `ext-sockets` gets.

The throttle still applies on the windowed path. A listener there may be slow
without losing data, but it is still writing to a shared SQLite file, and that
constraint has not changed.

### Reading it back

`GET /api/devices/{device}/sync-progress` returns the newest run for a device,
whether or not it is still going. It is keyed by **device, not run id**, because
the inline sync endpoint does not answer until the work a caller would want to
watch is already over — the browser never learns the run id in time.

It prefers the published cache reading and falls back to the row, which is what
survives after the cache entry expires.

## Performance

Early versions opened one `DB::transaction` per punch. `insertOrIgnore` in 500-row
chunks replaced that.

### SQL Server broke that path (found 2026-09-23)

After the move to SQL Server on 2026-09-21 **every attendance sync failed**:
`RuntimeException: This database engine does not support inserting while ignoring
errors.` Laravel has no SQL Server grammar for `insertOrIgnore`. Observed on IT Room
(F18, `192.168.7.62`), command 67; its newest stored punch was 2026-09-16. Nothing
was lost on the devices — they keep their logs — but no punch reached the app.

Now, in `AttendanceSyncService::ingest()`:

- **`upsert()` on the same unique index** (`attendance_unique_punch`), with the only
  "update" being `device_user_id` set to itself. An existing punch is matched and
  left exactly as it was, attribution included; a new one is inserted. It is still
  the index, not a SELECT, that decides what is a duplicate.
- **De-duplicated on that key first.** SQL Server's `MERGE` refuses a source holding
  the same key twice, which `insertOrIgnore` used to absorb silently.
- **Chunks sized from the row width** (`paramSafeChunk()`), because SQL Server allows
  2,100 parameters per statement: 500 rows x 9 columns would be 4,500. The same
  applies to the `attendance_raw_logs` insert (13 columns).

Verified 2026-09-23 on the production SQL Server inside a rolled-back transaction:
200-row chunk, 199 new rows inserted, an existing IT Room punch left with the same
`employee_id` and `updated_at`, a re-run inserting 0. The tests run on SQLite, where
the same `upsert()` compiles to `ON CONFLICT DO UPDATE`.

Per-punch `PunchRecorded` events were also removed from the bulk path: firing them
during a historical sync would flood MQTT with thousands of "just clocked in"
notifications for punches from 2023. Real-time notification belongs to the
real-time path, not the backfill.

## Clearing punches from a terminal (2026-10-18)

Owner: "trigger a wipe of the punch data stored in the device, but don't delete the
employees — only their punch data". `AttendanceClearService`, command
`clear_attendance`, `POST /api/devices/{device}/clear-attendance` (`devices.update`,
one attempt). ZKTeco standalone terminals only (`attendance.clear`: I on the F18, U on
the F22 and generic terminals, N on C3 and CSL — the UI hides it there).

Why it is worth having: the protocol cannot be asked for "punches since X", so every
read transfers the whole log. Camelot's 42,352 records take ~5 minutes over the
internet and arrive with a third of them misaligned (DC-020). A short log is a fast,
clean read.

Save before you destroy, or refuse:

1. The terminal's own count (`GET_FREE_SIZES`). Unknown → refuse.
2. A full read through `sync()` — every record stored, the unique index deduping.
   Refused unless `read` equals the count AND `undecodable` and `future` are 0 AND
   `imported + duplicate` equals the count.
3. The count again; a punch made since → back to 1 (up to three passes).
4. `clearAttendance($device, $held)` — the driver re-checks the count in the same
   session, clears, and requires the count to be 0 afterwards.
5. `attendance_watermark` → null: every record on the terminal is new from here.

Users, cards, fingerprints and the system's own attendance are untouched. The command's
message says how many punches were cleared and how many of them were new, or exactly
why nothing was. **Camelot cannot be cleared until it reads cleanly** — its 2026-10-18
read had 17,203 misaligned records, and the guard refuses on exactly that.

Confirmed preconditions on 2026-10-18 (read-only): IT ROOM (F18) read 11,332 = its count,
0 undecodable, 0 future; On The Road (F22) 8,430 = its count. The clear itself has never
been run. Covered by `tests/Feature/Devices/AttendanceClearTest.php`.

## Clearing history

`DELETE /api/device-sync-runs` erases run history for the same `device_id` /
`status` filter the listing takes, so the Sync history screen clears exactly what
it is showing and no more. It needs `devices.delete` — deliberately not
`devices.sync`, because an operator who may trigger a sync should not be able to
destroy the record of every sync that came before it. Every clear writes one
`syncrun.cleared` audit row carrying the counts and the filter; a mass delete
fires no model events, so the trail is written by hand.

**What goes with the runs:** their `attendance_raw_logs` rows. That archive is the
pre-parse bytes kept so an undecodable punch can be reparsed after a decoder fix —
the thing that made the 34% recovery above possible — so clearing is a real loss
and the confirmation dialog says so in those words rather than "cannot be undone".
The rows are deleted explicitly rather than left to the foreign key: the count is
part of the answer, and SQLite only cascades when the connection has foreign keys
enabled.

**What does not:**

| Survives | Why it must |
|---|---|
| Imported punches | They live in `attendance_logs`. History is a record of the reads, not the data. |
| `devices.attendance_watermark` | The watermark is on the device row, never derived from the newest run. If clearing moved it, the next incremental sync would skip real records — invisibly, for weeks. |
| A run still `running` | Deleting one mid-transfer leaves `SyncProgressRecorder` and the progress poller writing to and reading from a row that is gone. Always excluded, and reported back as `runs_kept_running`. |

Covered by `tests/Feature/Api/SyncHistoryClearTest.php`, where the assertions that
matter are the negative ones.
