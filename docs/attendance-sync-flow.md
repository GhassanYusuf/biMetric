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
        ├── insertOrIgnore in 500-row chunks, punched_at explicitly ->utc()
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

## The five counts

Recorded per run, because collapsing them hides the only information that
distinguishes a healthy sync from a broken one.

| Count | Meaning | When to worry |
|---|---|---|
| `read` | records the device offered | zero on a device with punches = read failure |
| `imported` | new rows stored | zero is normal on a settled device |
| `duplicate` | already known, skipped | high is normal |
| `unmatched` | device user id maps to no employee | non-zero = former staff or a missing map |
| `undecodable` | **offered but could not be decoded** | **any non-zero value needs attention** |

`undecodable` rows are archived in `attendance_raw_logs` for reparsing rather than
discarded. That archive is the reason a decoder bug was recoverable rather than
permanent.

## Two timezone bugs, both real, both in production

The device stamps records with **its own local time** (`+03:00`), carrying no
timezone. Everything downstream stores UTC. Two separate mistakes came from that
gap.

### 1. `insertOrIgnore` bypasses Eloquent casts

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

## Unattributed punches

1,417 punches from 52 former employees have `employee_id = null`: their records
were removed from the terminal after they had already clocked in, so the device
user id no longer maps to anybody.

They are **kept**, not discarded. Deleting them would quietly remove real hours
from history; keeping them with a null employee makes the gap visible and
countable (`unattributed_punches` on the reconciliation summary).

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
| API, queued | `POST /api/devices/{id}/commands` `{"command":"sync_attendance","full":true}` |
| API, inline | `POST /api/devices/{id}/sync-attendance` `{"full":true}` |
| Console | `php artisan app:sync-biometric-devices --full [--device=2]` |

The queued paths need `php artisan queue:work` running; the inline one does not.

The inline route raises the time limit to 300s when `full` is set — reconsidering
tens of thousands of records against the dedupe index outlives the default 30s, and
timing out *after* the device read has finished throws away the slow part.

A response reports which it did:

```json
{ "mode": "full", "watermark_before": null,
  "counts": { "read": 10303, "imported": 0, "duplicate": 10303 } }
```

CONFIRMED on a ZKTeco F18: a full re-read of a 10,303-record terminal reported
`10,303 already known` and imported nothing, while the same table run incrementally
imported the single new punch. The dedupe index is what makes that safe.

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

Per-punch `PunchRecorded` events were also removed from the bulk path: firing them
during a historical sync would flood MQTT with thousands of "just clocked in"
notifications for punches from 2023. Real-time notification belongs to the
real-time path, not the backfill.

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
