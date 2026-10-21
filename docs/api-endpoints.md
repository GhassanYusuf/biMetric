# HTTP API reference

73 routes under `/api`. Generated from the live route table, not written from
memory.

> **Integrating another system?** Use the versioned, scoped, documented surface in
> [integration-api.md](integration-api.md) (`/api/v1`). The routes below are the web
> app's own and may change with its screens.

## Conventions

**Envelope** — every response, success or failure:

```json
{
  "success": true,
  "message": "Sync runs retrieved.",
  "data": [ … ],
  "meta": { "pagination": { "current_page": 1, "per_page": 25, "total": 3, "last_page": 1, "has_more": false } },
  "errors": []
}
```

Paginators are unwrapped: rows are in `data`, pagination in `meta.pagination`.

**Auth** — `Authorization: Bearer <token>` from `POST /api/auth/login`. Every
route except login runs `auth:sanctum` then `tenant`.

**Status codes**

| Code | Means |
|---|---|
| 200 / 201 | fine |
| 401 | token missing or expired |
| 403 | permission denied |
| 409 | an enrollment is already active for this employee + device |
| 422 | validation failure **or a capability refusal** |
| 500 | a bug on our side — generic message; the detail is in the log, not the response |
| 501 | the firmware does not support this operation at all |
| 502 | the device was reached and reported a real error |

A 422 on a device action usually means the firmware cannot do it — the message
names the capability and its level. See
[device-capabilities.md](device-capabilities.md).

**502 responses carry `errors.retryable`**, so a client can tell "try again" from
"this will never work" without parsing the message:

```json
{ "success": false, "message": "Attendance sync failed: could not reach the device. Check it is powered on and on the network.", "errors": { "retryable": true } }
```

A **501** means the capability was mis-declared — rule 9 requires the UI to hide
unsupported actions, so a client should never provoke one. Both are set centrally
in `bootstrap/app.php`, so an endpoint that forgets to catch still answers
correctly rather than returning a bare 500. See
[error-handling.md](error-handling.md).

---

## Auth

| Method | Path |
|---|---|
| POST | `/api/auth/login` *(throttled 10/min)* |
| GET | `/api/auth/me` |
| POST | `/api/auth/logout` |

## Diagnostics

| Method | Path |
|---|---|
| POST | `/api/client-errors` *(auth only, throttled 20/min)* |

Crash reports from the SPA's `ErrorBoundary` and global handlers. Deliberately
outside the `tenant` group — the screen that crashed may be the one that was
resolving the tenant. Returns **202**; the client must not retry, because a
retrying error report is how a crash loop starts.

```json
{ "message": "x is not a function", "kind": "render", "stack": "…", "component_stack": "…", "url": "…" }
```

`kind` is one of `render`, `unhandledrejection`, `window.onerror`. Every field is
length-capped server-side and written to the `client` log channel — there is no
table behind this endpoint.

## Devices

| Method | Path | Notes |
|---|---|---|
| GET | `/api/devices` | |
| POST | `/api/devices` | |
| GET | `/api/devices/{device}` | identity, health, capabilities, sync position |
| PUT/PATCH | `/api/devices/{device}` | blank secrets mean "leave unchanged" |
| DELETE | `/api/devices/{device}` | punch history is retained |
| POST | `/api/devices/{device}/test-connection` | synchronous, fast |
| POST | `/api/devices/{device}/sync-attendance` | queues a read: `full`, or `from`/`to` |
| POST | `/api/devices/{device}/sync-enrollment` | legacy direct sync |
| POST | `/api/devices/{device}/import-employees` | |
| GET | `/api/devices/{device}/status-history` | transitions only |

### The device resource

Beyond the obvious fields:

- `is_online` — **derived** from `last_seen_at`, never stored. A device that stops
  answering never gets to mark itself offline, so a stored flag would keep
  claiming it is up. `health_status: "online"` with `is_online: false` is a real
  and meaningful combination (the UI shows *"Stale"*).
- `capabilities` — every key with `level`, `usable`, `confirmed`, `description`.
- `attendance_watermark` — UTC. If this is ever in the future, syncing is silently
  skipping punches.
- `has_comm_key` / `has_web_credentials` — booleans, so the UI can show that a
  secret is set without the secret ever leaving the server.

### Forcing a full re-read

`POST /api/devices/{device}/sync-attendance` accepts an optional body:

```json
{ "full": true }
```

Omitted or false, it is an ordinary incremental sync — unchanged. True, it ignores
the watermark and reconsiders every record the terminal holds.

`full` does **not** change what is transferred: the protocol always returns the
whole table, so this only affects what is imported. Safe to repeat — the unique
punch index prevents duplicates. The response's `mode` field says which ran.

The queued equivalent is `POST /api/devices/{device}/commands` with
`{"command":"sync_attendance","full":true}`, and the console equivalent is
`php artisan app:sync-biometric-devices --full`.

### Reading one range of dates

The same endpoint takes a window instead:

```json
{ "from": "2026-08-01", "to": "2026-08-31" }
```

- Both dates are **inclusive**, `Y-m-d`, and are the **device's** calendar days —
  its punches carry its local offset, and the service widens each date to that day's
  start and end in `device.timezone`.
- Either half may stand alone. `from` only means "everything since"; `to` only means
  "everything up to".
- `to` before `from` is a **422 on the field**. Nothing is queued: it could only ever
  import nothing, which on screen is indistinguishable from a dead terminal.
- Like `full`, it changes **nothing about the transfer** — the terminal always sends
  its whole table. It bounds what is imported.
- **It does not move the device's watermark**, so a backfill never changes where
  ordinary syncing is up to. See `docs/attendance-sync-flow.md` for why that
  direction is the only safe one.

The run comes back with `mode: "range"`, `range_from` / `range_to` (plain `Y-m-d`, so
no browser can shift them a day), and a `counts.out_of_range` saying how many records
the terminal offered from outside the window.

The queued equivalent is `POST /api/devices/{device}/commands` with
`{"command":"sync_attendance","from":"2026-08-01","to":"2026-08-31"}`.

### Live sync progress

`GET /api/devices/{device}/sync-progress` — polled about once a second while a
sync runs. Cheap by design: one indexed row, no eager loads.

```json
{
  "running": true,
  "run_id": 62,
  "status": "running",
  "progress": { "phase": "reading", "read": 3251, "total": 10302,
                "unit": "records", "percent": 32, "attempt": 1 },
  "counts": { "read": 0, "imported": 0, "duplicate": 0, "undecodable": 0, "unmatched": 0 }
}
```

- `progress` is **null once the run has finished** — `counts` is the authority then.
- `percent` is **null, never 0**, when the device declared no total. Render an
  indeterminate bar; do not invent a denominator.
- `unit` is `records` or `bytes`. **Check it.** A byte total shown as records tells
  an operator there are 412,084 punches to read.
- `phase` is `reading` (off the device) or `importing` (into the database). They
  are separate because the read is network-bound and can stall or retry; the import
  cannot.
- `attempt` above 1 means the transfer restarted, so the bar legitimately went
  backwards.

Progress is coarse on purpose — see the throttle section in
[attendance-sync-flow.md](attendance-sync-flow.md). Reporting more often measurably
breaks the transfer.

## The command queue

| Method | Path | Notes |
|---|---|---|
| POST | `/api/devices/{device}/commands` | **the** way to run device work |
| GET | `/api/device-commands` | `?device_id= &status= &command= &per_page=` |
| GET | `/api/device-commands/{command}` | |
| POST | `/api/device-commands/{command}/retry` | reuses the same row |
| POST | `/api/device-commands/{command}/cancel` | |

```http
POST /api/devices/2/commands
{ "command": "sync_attendance", "full": false }
```

`sync_attendance` also accepts `from` / `to` here, with exactly the meaning above.

Accepted: `sync_attendance`, `sync_enrollment`, `import_employees`,
`reconcile_users`, `health_check`, `read_info`, `probe_capabilities`, `sync_time`.

Returns the command; the outcome arrives over MQTT or by polling. Statuses:
`queued`, `running`, `succeeded`, `failed`, `cancelled`.

`message` and `error`/`raw_response` are both exposed on purpose — one is the
sentence an operator reads, the other is for whoever debugs the failure.

## Device groups

| Method | Path |
|---|---|
| GET/POST | `/api/device-groups` |
| GET/PUT/PATCH/DELETE | `/api/device-groups/{deviceGroup}` |
| PUT | `/api/device-groups/{deviceGroup}/devices` *(replace membership)* |
| POST/DELETE | `/api/device-groups/{deviceGroup}/devices/{device}` |
| POST | `/api/device-groups/{deviceGroup}/commands` |

A group command queues **one command per member device**, not one for the group,
so a half-successful run stays legible per terminal and a retry only re-hits what
failed. 422 when the group has no addressable terminals.

Deleting a group never deletes its devices.

## Sync history

| Method | Path | Notes |
|---|---|---|
| GET | `/api/device-sync-runs` | `?device_id= &status= &per_page=` |
| GET | `/api/device-sync-runs/{run}` | |
| DELETE | `/api/device-sync-runs` | `?device_id= &status=` — clears history for that filter |

Statuses: `running`, `succeeded`, `partial`, `failed`. Each run carries five
counts — `read`, `imported`, `duplicate`, `unmatched`, `undecodable` — because
`imported: 0` alone cannot distinguish a healthy incremental sync from a decoder
that is dropping records. See [attendance-sync-flow.md](attendance-sync-flow.md).

`DELETE` needs `devices.delete`, not `devices.sync`. It deletes the matching runs
and the raw payloads those runs archived, keeps any run that is still `running`,
and touches neither imported punches nor the incremental watermark. It answers with
`{ runs_deleted, raw_logs_deleted, runs_kept_running }` and writes a
`syncrun.cleared` audit entry.

## Enrollment

| Method | Path | Notes |
|---|---|---|
| GET | `/api/enrollment-sessions` | `?employee_id= &device_id= &status= &active=` |
| GET | `/api/enrollment-sessions/{session}` | includes the device event trail |
| POST | `/api/enrollment-sessions/{session}/cancel` | **synchronous** — releases the terminal |
| POST | `/api/employees/{employee}/fingerprints/read` | `{device_ids?: int[]}` — copy their fingerprints from their terminals into the system (read-only on the terminals); 503 until the storage migration has run |
| POST | `/api/employees/{employee}/devices/{device}/enrollment/fingerprint` | `{finger_slot: 0-9}` |
| POST | `/api/employees/{employee}/devices/{device}/enrollment/card` | |
| DELETE | `/api/employees/{employee}/devices/{device}/enrollment/fingerprints/{slot}` | |
| DELETE | `/api/employees/{employee}/devices/{device}/enrollment/card` | |

Statuses: `pending`, `sent`, `waiting`, `success`, `timeout`, `failed`,
`cancelled`. 409 if a session is already active for that employee and device.

Progress publishes on `tenant/{tenantId}/module/enrollment/{sessionId}` — the
payload carries `session_id`, `status`, `event` and `message`, **not** the
accumulated trail. Fetch the session for that.

## Adoption — bringing an existing terminal under management

| Method | Path | Notes |
|---|---|---|
| GET | `/api/devices/{device}/roster` | stored roster, **no device contact**; `?claim_status=unclaimed\|claimed\|ignored` |
| POST | `/api/devices/{device}/roster/read` | contacts the device. **Read-only**, repeatable |
| POST | `/api/devices/{device}/roster/{entry}/claim` | `{employee_id}` — binds the entry to an employee |
| POST | `/api/devices/{device}/roster/{entry}/ignore` | `{reason}` — not a person we track |
| POST | `/api/devices/{device}/adopt` | unlocks writes; 422 while anything is undecided |

**A device is read-only until it is adopted.** Every terminal here was in service before
this system existed and already holds people; writing to one before its occupants are
accounted for overwrites them, taking fingerprint templates that cannot be restored.
Push and enrollment endpoints throw until `adoption_status = adopted`.

`GET .../roster` returns `meta.adoption_status`, `meta.pending` and `meta.writable`.

`fingerprint_count: null` means the template table would not decode — **unknown**, not
zero. Render the two differently: a wrong "0" leads an operator to re-enrol somebody who
is already enrolled.

Claiming takes over the PIN the device **already** uses, and writes nothing to the
hardware — that is what preserves the person's existing fingerprints.

There is **no bulk employee import over HTTP**. `POST /api/employees` creates one at a
time; the estate-sized path is `php artisan staff:import staff.csv`.

Full background: `docs/device-identity-and-adoption.md`.

## Reconciliation

| Method | Path | Notes |
|---|---|---|
| GET | `/api/device-maps` | `?device_id= &status= &needs_attention=` |
| GET | `/api/device-maps/summary` | **stored state, no device contact** |
| POST | `/api/devices/{device}/reconcile` | queues a fresh read |
| POST | `/api/devices/{device}/reconcile/import-unknown` | creates one employee per **unclaimed roster entry**; matches nothing against existing records |
| POST | `/api/devices/{device}/reconcile/employees/{employee}/push` | resolves `missing_on_device` |

Reporting is read-only by design. Auto-resolving means deciding whose version
wins, and getting that wrong either deletes a real enrollment or restores a
departed employee's access.

Statuses: `in_sync`, `missing_on_device`, `missing_in_system`, `mismatched`.
`fingerprint_slots: null` means the template table could not be decoded — **not**
that the employee has no fingerprints.

## Employees

### `DELETE /api/employees/by-code/{code}` — delete by Personnel ID, system AND terminals (2026-10-21)

`{code}` is the **Personnel ID** (`employee_code`), not the database `id` that
`DELETE /api/employees/{employee}` takes. It removes the person from **every terminal
they are on** (card and fingers saved first, rule 19), deactivates them at once, and
deletes the record once every terminal confirms. Answers `200` (on no terminal, deleted),
`202` (removals queued, or a terminal could not be written and the record is kept),
`404` (no such code in your company), `403` (no `employees.delete`).

It is the integration API's `DELETE /api/v1/employees/by-code/{code}`, routed here too —
full description in `docs/integration-api.md`. First built the same morning as a
record-only delete; that left a deleted employee on three terminals (casebook DC-025).

### Identity — national ID (removed)

**National IDs were removed on 2026-09-27** at the owner's request: the column on the
Employees screen, the form field, the roster filter, the Attendance lookup, the
`PUT /api/employees/{employee}/national-id` and `POST /api/employees/lookup` endpoints,
and in the database `person_national_ids` plus `people.national_id` /
`people.national_id_hash` (migration `2026_09_27_110000_drop_national_ids`).

### `GET /api/employees/{employee}/report` — the Report screen (2026-09-27)

`EmployeeReportService`, built on `AttendanceSummaryService`: every day's verdict is
the payroll summary's, so the screen and the payroll report cannot disagree. Read-only;
`employees.view` via the employee policy.

- **One summary run** over `max(2 × period, 365)` days ending `to` (default today).
  `stats` is the period, `previous_stats` the period before it, `days` the whole span
  (work days only, oldest first; a missing date is a non-work day).
- **One `outcome` per day**, worst first: `absent`, `excused`, `missed_punch_out`,
  `late` (first punch after shift start + grace), `left_early` (last punch before
  the shift end — judged here, because the summary's `leave_on_time` means "before
  end + grace" and includes full shifts), `on_time`.
- **`untracked`** for days before `tracking_from` — the hire date, else the first
  punch ever, else null. The summary has no start date and would call a new starter's
  whole year absent; untracked days count in no statistic.
- **Null, never 0**, for a statistic with no days under it (`on_time_rate` with no day
  present, averages, rates with no expected day).
- `enrollment` per live terminal mapping: `state` is `never_read` (no sync),
  `unknown` (synced, template table did not decode) or `read` with a count (rule 3).
- `punches.by_verify_method` carries RAW method numbers; the screen decodes only the
  documented ones (`attendance/parts.jsx`).

The screen is `/app/employees/{id}/report` (row action **Report**). `/app/employees/sample/report`
renders generated sample data in the same layout, labelled as sample on screen, for
viewing the design before real attendance exists.

### Filters on `GET /api/employees`

| Parameter | |
|---|---|
| `search` | every word must match first name, last name, code, department or email — so the full name as shown finds the person (2026-10-11) |
| `department_id` | that department **and every department beneath it** (the Company › Department tree, 2026-10-11); `none` = no department at all |
| `device_id` | enrolled on that terminal, via the live mapping |
| `unenrolled` | `1` = on no terminal at all; they cannot clock in anywhere |
| `is_active` | |

| Method | Path | Notes |
|---|---|---|
| GET/POST | `/api/employees` | |
| GET | `/api/employees/next-code` | suggests the lowest free code; declared before `{employee}` or binding would 404 |
| GET/PUT/PATCH/DELETE | `/api/employees/{employee}` | |
| GET | `/api/employees/{employee}/attendance-summary` | |
| GET | `/api/employees/{employee}/report` | `?period=30\|90\|365` (default 30), optional `to`. The Report screen's data — see below |
| POST | `/api/employees/{employee}/propagate` | queues a write onto several terminals |
| POST | `/api/employees/{employee}/devices/{device}/push` | |
| POST | `/api/employees/{employee}/devices/{device}/remove` | |
| GET | `/api/employees/{employee}/devices/{device}/enrollment` | live read from the terminal |
| POST | `/api/employees/{employee}/waivers` | |

`GET /api/employees` and `GET /api/employees/{employee}` include `enrollments`:
one entry per terminal the employee is mapped to, each carrying `device_id`,
`device_name`, `fingerprint_slots`, `fingerprint_count`, `card_number` and
`synced_at`. Prefer it over the flat `fingerprint_count` / `card_number` fields,
which are only a snapshot of whichever device was read last and cannot be
attributed to a terminal. The key is **absent** when the relation was not loaded,
never `[]` — see docs/enrollment-flow.md.

`PUT /api/employees/{employee}/devices/{device}/card` — `{card_number}` as digits —
writes a typed card number and verifies it on read-back (`employees.push`). There is
no queued equivalent yet. See docs/enrollment-flow.md, "Writing a card by number".

`enroll-fingerprint`, `enroll-card`, `fingerprints/{slot}` and `card` under
`/devices/{device}/` are the **direct, blocking** predecessors of the queued
`enrollment/*` routes above. Prefer the queued ones.

### Personnel fields and access levels (2026-09-23) — DATABASE ONLY

`POST /api/employees` and `PUT /api/employees/{employee}` also accept the ZKAccess
Personnel-dialog fields. **All optional, and none is sent to a terminal or panel**:
the device push still writes PIN and name only (`DeviceUserSyncService::pushEmployee`).

| Field | Rule |
|---|---|
| `last_name`, `mobile_phone`, `job_title`, `office`, `education`, `country`, `city`, `postal_code`, `home_phone`, `political_status`, `race`, `home_address`, `work_address` | nullable strings |
| `gender` | `male` \| `female` \| null |
| `hired_date`, `birthday` | dates (`birthday` before today) |
| `device_password` | 1–8 digits. **Omitted = unchanged, `null` = clear.** Encrypted at rest; never returned — the response carries `has_device_password` instead |
| `privilege` | `user` \| `enroller` \| `admin` \| `superadmin` \| null. Null = "not set in this app", not "user" |
| `access_level_ids` | array of this tenant's access level ids. **Omitted = unchanged** |
| `multi_card_group_id` | this tenant's group id, or null |
| `valid_period_enabled` | boolean. When true, `access_valid_from` and `access_valid_to` are required |
| `access_valid_from`, `access_valid_to` | dates; `access_valid_to` must be after `access_valid_from` |

`employee_code` must be **digits only, 1–9** on create, and on update **only when it
changes** — 101 of 356 production codes were roster keys like `10-12` on 2026-09-23,
and an unconditional rule would make those people uneditable.

| Method | Path | Notes |
|---|---|---|
| POST | `/api/employees/{employee}/photo` | multipart `photo` (image, ≤ 4 MB). Separate from the JSON save because PHP does not parse multipart PUT |
| DELETE | `/api/employees/{employee}/photo` | removes the file and clears `photo_url` |

The column names are `access_valid_*`, not `valid_*`, because `employee_device_maps`
already has `valid_from`/`valid_to` and existing joins filter on them unqualified.

| Method | Path | Permission |
|---|---|---|
| GET | `/api/access-levels` | `employees.view` |
| POST / PUT / DELETE | `/api/access-levels[/{id}]` — `{name, time_zone?}` | `employees.update` |
| GET | `/api/multi-card-groups` | `employees.view` |
| POST / PUT / DELETE | `/api/multi-card-groups[/{id}]` — `{name}` | `employees.update` |

`time_zone` is a free label, not a C3 panel time-zone index: no access-level write to
a panel has been confirmed on this estate's hardware.

## Attendance, holidays, reports, notifications

| Method | Path |
|---|---|
| GET | `/api/attendance` — filters `employee_id`, `device_id`, `from`, `to`, and (2026-10-11) `department_id` (a tree node and everything beneath it; `none` = employees with no department, never unmatched punches) and `search` (every word must match the employee's first name, last name or code, or the terminal PIN). `employee_name` is first + last name. `/api/exports/attendance` takes the same filters. |
| GET | `/api/holidays` · POST · PUT/PATCH · DELETE `/{holiday}` |
| GET | `/api/dashboard/summary` |
| GET | `/api/reports/attendance` |
| GET | `/api/audit-logs` |
| GET | `/api/departments` (+ full resource). Each carries `parent_id` (null = a top-level company); `meta.unassigned_count` = employees with no department |
| GET | `/api/notifications` · `/unread-count` · POST `/{notification}/read` · `/read-all` |

### Date filters are OFFICE-LOCAL dates

`from` / `to` on `/api/attendance`, `/api/reports/attendance` and the exports are
dates in `config('attendance.timezone')`, not UTC and not the caller's timezone.
`punched_at` is stored UTC, so `App\Services\Attendance\PunchLogQuery` converts
each bound before it hits the column: `from` becomes that local day's 00:00 and
`to` that local day's 23:59:59.

Both conversions matter. Comparing the bare strings — which this endpoint used to
do — shifted every boundary by the office offset and made `to` mean midnight, so
`to=2026-09-14` silently excluded the whole of the 14th.

## PDF exports

| Method | Path | Permission |
|---|---|---|
| GET | `/api/exports/attendance` | `attendance.view`, or `attendance.view.self` for own rows |
| GET | `/api/exports/employees/{employee}/attendance` | `employees.view` |
| GET | `/api/exports/payroll` | `reports.view` |
| GET | `/api/exports/attendance-report` | `reports.view` |
| GET | `/api/exports/employees/{employee}/report` | `employees.view` |
| GET / POST | `/api/exports/employee-reports` — list recent runs / start one | `reports.view` |
| GET | `/api/exports/employee-reports/{id}` and `…/{id}/download` | `reports.view` |

Rendered synchronously with dompdf (`barryvdh/laravel-dompdf`) and returned as
`application/pdf` with a `Content-Disposition` filename. These are read-only
queries, so rule 11's "queue it" applies to device work, not to these.

Query parameters mirror the screen each one exports: `employee_id`, `device_id`,
`from`, `to` for the punch log; `from`, `to` for one employee; `from`, `to`,
`department_id` for payroll.

### The two visual reports (2026-10-18)

`/api/exports/attendance-report?from&to&department_id` — the company attendance report
for management (`AttendanceReport`, template `pdf.report.attendance`): headline figures
against the same-length period before, how the person-days went, day-by-day (weekly
above 62 work days) outcome columns, departments, an arrival-time histogram, weekday
pattern, the people who need attention, and everyone as an appendix. `department_id`
takes the node and everything beneath it. Range defaults to the last 30 days, at most
366 days, and **no date may be in the future** (422) — an unhappened work day has no
punches and would count as everybody's absence.

`/api/exports/employees/{employee}/report?from&to` (or `?period=30|90|365`, ending
today) — one person's report for any period up to 366 days (`PersonalReport`, template
`pdf.report.employee`): KPIs against the period before, a wall calendar of the period
(a heatmap above 62 days), arrivals/departures, weekday and monthly patterns, terminals
and methods, enrollment, and **every punch of every day** with its terminal and verify
method. Its figures equal the Employee Report screen's for the same days
(`PersonalReportTest`).

### Every employee's report as one PDF — built in the background (2026-10-18)

`POST /api/exports/employee-reports {from, to, department_id?}` → `202` with the run.
Active employees in the department and everything beneath it (or everyone), in
department then name order; anyone not tracked by `to` (no hire date, no punch) is left
out and named on the contents page. `409` while another run of the company's is queued
or running (younger than an hour); `422` for future dates, more than 366 days, or nobody
to report on; `503` if `pdfunite` is missing.

`BuildEmployeeReports` renders `CHUNK` (6) people per job and re-queues itself at the
back of the queue, so device commands on the single worker run in between. Each person is
rendered alone — the same page as the single export — and the last chunk adds a contents
page (two passes, so its own length is in the page numbers) and joins everything with
**`pdfunite` (poppler-utils, a runtime dependency since 2026-10-18)**. A person who fails
to draw is named on the contents page, never silently dropped; a disk failure fails the
run. Files live in `storage/app/private/report-exports/{id}/`, are downloadable only
through the API, and are cleared after 7 days when the next run starts. Progress is live
through the `reports` module (`LiveController` stamp + MQTT `module/reports/{id}`), and
the requester gets a notification when it finishes. Measured 2026-10-18: 132 people,
September, 68 s, 417 pages, 12.8 MB.

Two things a reader of the company report must know:

- **Rates are over the people who punched at least once in the period.** A person
  expected at work and seen on no terminal is a *no-show*: named on the "Needs
  attention" page, kept out of every rate, and the all-in attendance rate is printed
  beside the headline. On 2026-09 data this was 73 of 132 people — whole companies on
  terminals that were not syncing — and pooling them in turned 78.6% into 35.1%.
- **Every per-person figure is `EmployeeReportService::stats()`** over days judged by
  `EmployeeReportService::outcome()`, so a person's line equals their own Employee
  Report for the same days (`AttendanceReportTest` pins it). Company, department and
  weekday figures are the same function over the pooled person-days.

Charts are SVG drawn by `App\Support\PdfCharts`. dompdf 3.1.6 renders SVG rect, line,
path (M/L/Z), circle and text, but **not** the arc command or CSS gradients, and this
server has no GD, so there are no raster images.

"Page N of M" is drawn on the canvas by `ExportController::numberPages()`: dompdf
never resolves CSS `counter(pages)`, and every export printed "Page 1 of 0" until
2026-10-18.

Three properties every export holds to, all enforced in `ExportController`:

- **One source of truth.** Rows come from `PunchLogQuery`,
  `AttendanceSummaryService` and `PayrollReport` — the same classes the screens
  read. A PDF is the copy that gets filed, so it must not be computed twice.
- **The same permissions.** An export re-applies the rule of the list it mirrors.
  A self-only user exporting `?employee_id=<somebody else>` gets their own rows,
  not a 403 and not the other person's.
- **The page states what it is.** Tenant, range, active filters, timezone and
  generation time print in the fixed header/footer of every page. The punch log
  caps at 5,000 rows and prints a warning naming the true total when it truncates
  — a short attendance record otherwise reads as absence.

Known limit: dompdf's bundled DejaVu Sans does not shape right-to-left scripts,
so Arabic names render as disjoint glyphs. A shaping font has to be registered
with dompdf before that can work.

## MQTT topics

Mirror of `Services\Realtime\TopicBuilder`. Always pin the tenant segment — never
subscribe with a leading wildcard.

| Topic | Payload |
|---|---|
| `tenant/{t}/module/devices/status` | `device_id`, `name`, `status`, `previous_status`, `latency_ms`, `observed_at` |
| `tenant/{t}/module/enrollment/{sessionId}` | `session_id`, `employee_id`, `device_id`, `type`, `finger_slot`, `status`, `event`, `message`, `at` |
| `tenant/{t}/module/attendance/{employeeId}` | a new punch |
| `tenant/{t}/user/{userId}/notifications` | |

MQTT only enhances. Every screen works without a broker.

## Company settings (2026-09-24)

| Method | Path | Body / notes |
|---|---|---|
| GET | `/api/settings` | `settings.view`. Every allow-listed key with its value (`TenantSettings::DEFAULTS`). |
| PUT | `/api/settings` | `settings.manage`. Nested body, e.g. `{employees: {auto_push_on_save: true}}`; unknown keys are ignored; each change is audited as `setting.updated`. |

`employees.auto_push_on_save` (default **false**): when true, saving an employee pushes
to every terminal on their Access levels list without the "Push now / Not now" prompt.
Removals from a terminal still ask.

## Live stamps (2026-09-24, CLAUDE.md rule 18)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/live` | Any signed-in user. `{employees, devices, attendance, enrollment, holidays, notifications}` — opaque digests of (count, newest key, newest updated_at) per module, company-scoped. The SPA polls it when MQTT is unavailable and re-fetches a module when its stamp moves. |

