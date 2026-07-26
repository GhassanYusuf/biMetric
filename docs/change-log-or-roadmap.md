# Change log and roadmap

## What changed in this rebuild

### The protocol became a library

Device code was spread through controllers and services. It is now
`packages/zkteco-protocol` — 31 classes, zero framework dependencies, installable
into a CLI tool or another platform.

Exactly one class in `app/` imports it, and
`tests/Unit/ProtocolLibraryBoundaryTest.php` fails the build if that changes.

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
