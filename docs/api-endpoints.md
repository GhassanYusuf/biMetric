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
| 502 | the device was reached and reported a real error |

A 422 on a device action usually means the firmware cannot do it — the message
names the capability and its level. See
[device-capabilities.md](device-capabilities.md).

---

## Auth

| Method | Path |
|---|---|
| POST | `/api/auth/login` *(throttled 10/min)* |
| GET | `/api/auth/me` |
| POST | `/api/auth/logout` |

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

Statuses: `running`, `succeeded`, `partial`, `failed`. Each run carries five
counts — `read`, `imported`, `duplicate`, `unmatched`, `undecodable` — because
`imported: 0` alone cannot distinguish a healthy incremental sync from a decoder
that is dropping records. See [attendance-sync-flow.md](attendance-sync-flow.md).

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
