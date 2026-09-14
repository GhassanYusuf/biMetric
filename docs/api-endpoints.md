# HTTP API reference

73 routes under `/api`. Generated from the live route table, not written from
memory.

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
| POST | `/api/devices/{device}/sync-attendance` | legacy direct sync |
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
| POST | `/api/employees/{employee}/devices/{device}/enrollment/fingerprint` | `{finger_slot: 0-9}` |
| POST | `/api/employees/{employee}/devices/{device}/enrollment/card` | |
| DELETE | `/api/employees/{employee}/devices/{device}/enrollment/fingerprints/{slot}` | |
| DELETE | `/api/employees/{employee}/devices/{device}/enrollment/card` | |

Statuses: `pending`, `sent`, `waiting`, `success`, `timeout`, `failed`,
`cancelled`. 409 if a session is already active for that employee and device.

Progress publishes on `tenant/{tenantId}/module/enrollment/{sessionId}` — the
payload carries `session_id`, `status`, `event` and `message`, **not** the
accumulated trail. Fetch the session for that.

## Reconciliation

| Method | Path | Notes |
|---|---|---|
| GET | `/api/device-maps` | `?device_id= &status= &needs_attention=` |
| GET | `/api/device-maps/summary` | **stored state, no device contact** |
| POST | `/api/devices/{device}/reconcile` | queues a fresh read |
| POST | `/api/devices/{device}/reconcile/import-unknown` | resolves `missing_in_system` |
| POST | `/api/devices/{device}/reconcile/employees/{employee}/push` | resolves `missing_on_device` |

Reporting is read-only by design. Auto-resolving means deciding whose version
wins, and getting that wrong either deletes a real enrollment or restores a
departed employee's access.

Statuses: `in_sync`, `missing_on_device`, `missing_in_system`, `mismatched`.
`fingerprint_slots: null` means the template table could not be decoded — **not**
that the employee has no fingerprints.

## Employees

| Method | Path | Notes |
|---|---|---|
| GET/POST | `/api/employees` | |
| GET | `/api/employees/next-code` | suggests the lowest free code; declared before `{employee}` or binding would 404 |
| GET/PUT/PATCH/DELETE | `/api/employees/{employee}` | |
| GET | `/api/employees/{employee}/attendance-summary` | |
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

`enroll-fingerprint`, `enroll-card`, `fingerprints/{slot}` and `card` under
`/devices/{device}/` are the **direct, blocking** predecessors of the queued
`enrollment/*` routes above. Prefer the queued ones.

## Attendance, holidays, reports, notifications

| Method | Path |
|---|---|
| GET | `/api/attendance` |
| GET | `/api/holidays` · POST · PUT/PATCH · DELETE `/{holiday}` |
| GET | `/api/dashboard/summary` |
| GET | `/api/reports/attendance` |
| GET | `/api/audit-logs` |
| GET | `/api/departments` (+ full resource) |
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

Rendered synchronously with dompdf (`barryvdh/laravel-dompdf`) and returned as
`application/pdf` with a `Content-Disposition` filename. These are read-only
queries, so rule 11's "queue it" applies to device work, not to these.

Query parameters mirror the screen each one exports: `employee_id`, `device_id`,
`from`, `to` for the punch log; `from`, `to` for one employee; `from`, `to`,
`department_id` for payroll.

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
