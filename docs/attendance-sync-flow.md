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
        ├── ZktecoDriver → Device::run() → attendance()->all()
        │       device sends its ENTIRE log; there is no server-side filter
        ├── archive raw bytes           → attendance_raw_logs
        ├── decode                      → Codec\AttendanceCodec + Codec\Timestamp
        ├── filter to > watermark
        ├── map device user id → employee
        ├── insertOrIgnore in 500-row chunks, punched_at explicitly ->utc()
        ├── advance watermark (UTC)
        └── close run with five counts
```

## Incremental means what it stores, not what it transfers

The protocol has no "records since X" request. The device always sends
everything it holds. Incremental sync therefore reads the whole log every time
and discards what it already has — which is why a healthy run on a settled device
looks like:

```
read=9582  imported=0  duplicate=9582  unmatched=0  undecodable=0
```

That is **correct**, not a failure, and the UI says so explicitly. Anyone reading
"imported 0" as broken will start firing full re-reads at a working system.

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

`{command: sync_attendance, full: true}` ignores the watermark and reads
everything the device still holds. Existing punches are recognised and skipped, so
nothing duplicates. It is slower, and the UI confirms before firing it.

Use it after a decoder fix, or when the watermark is suspect.

## Performance

Early versions opened one `DB::transaction` per punch. `insertOrIgnore` in 500-row
chunks replaced that.

Per-punch `PunchRecorded` events were also removed from the bulk path: firing them
during a historical sync would flood MQTT with thousands of "just clocked in"
notifications for punches from 2023. Real-time notification belongs to the
real-time path, not the backfill.
