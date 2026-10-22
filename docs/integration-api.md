# Integration API (`/api/v1`)

For **other software calling this one** — an HRMS, ERP, payroll or access-control system.
Added 2026-10-08. The SPA's own endpoints (`docs/api-endpoints.md`) are unchanged and are
**not** a supported integration surface; this one is.

Read this first if you are the integrator. Read "How it fits the codebase" if you are
the maintainer.

---

## 1. Quick start

```bash
# 1. On the server: a dedicated service user, then a scoped token for it.
php artisan integration:token payroll-bot@acme.test payroll-sync \
    --ability=employees:read --ability=employees:write --ability=attendance:read --days=365
#   → prints the token ONCE. Only its hash is stored.

# 2. Check it.
curl -H "Authorization: Bearer $TOKEN" https://bridge.example/api/v1/ping

# 3. Create-or-update a person by YOUR id. Safe to run twice.
curl -X PUT -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     https://bridge.example/api/v1/employees/by-external-id/HR-1042 \
     -d '{"name":"Sara Ali","email":"sara@acme.test","push_to_device_ids":[3,4]}'

# 4. Read punches since you last looked.
curl -H "Authorization: Bearer $TOKEN" 'https://bridge.example/api/v1/attendance?after_id=0&limit=500'
```

Tokens can also be minted over HTTP by a **signed-in** user (`POST /api/v1/tokens`), but
never by another integration token — a scoped token cannot mint itself a wider one.

## 2. Conventions

**Envelope** — identical to the rest of the app:
`{ "success", "message", "data", "meta", "errors" }`. Validation failures are `422` with
field errors in `errors`.

**Auth** — `Authorization: Bearer <token>`. A request needs **both**:

1. the token's **ability** for the route (below), and
2. the token's **user** to hold the matching permission (`employees.update`, …).

A token can only ever *narrow* its user. `403` means one of the two is missing.

| Ability | Grants |
|---|---|
| `employees:read` | employees, departments, access levels |
| `employees:write` | create / change / delete employees, departments, photos |
| `devices:read` | terminals, health, capabilities, commands, sync runs, enrollment sessions |
| `devices:write` | push/remove people, enroll fingerprints & cards, trigger syncs, retry/cancel commands |
| `attendance:read` | punches, summaries, attendance report |
| `webhooks:manage` | webhook endpoints and deliveries (also needs `settings.manage`) |

A sign-in token from the web app carries `*` and passes every ability check.

**Time** — every timestamp is **UTC ISO-8601** (rule 10). The one exception is a bare
date (`2026-10-01`) passed to `from` / `to` on `/attendance`, which means an
**office-local** day (`ATTENDANCE_TIMEZONE`), exactly as the web app's filter does. Send a
full timestamp to be unambiguous.

**Rate limit** — 120 requests/minute **per token** (`INTEGRATION_RATE_LIMIT`). `429` with
`Retry-After`.

**Idempotency** — send `Idempotency-Key: <unique string>` on any write. If the call times
out, repeat it with the same key and the **original response** comes back with
`Idempotent-Replayed: true`; nothing runs twice. Matters most for enrollment (a second
capture would put a terminal into capture mode with a person standing at it). Scoped per
token + method + path; kept 24 h; the same key with a *different* body is `422`; a `5xx` is
never stored.

**Queued, not instant** — anything that touches a terminal returns a **command id** (or
enrollment **session id**), not a result. See §5.

## 3. Employees

| Method | Path | Notes |
|---|---|---|
| GET | `/employees` | Filters: `search`, `external_id`, `employee_code`, `department_id`, `is_active`, `resigned` (`1` only resigned, `0` none), `employment_type`, `device_id`, `updated_since`. `per_page` ≤ 200. Ordered by `id` — stable while rows are added. |
| GET | `/employees/{id}` | |
| GET | `/employees/by-external-id/{externalId}` | |
| GET | `/employees/by-code/{code}` | By **Personnel ID** (`employee_code`), the same answer as `/employees/{id}`. Exact match as text (`0099902` ≠ `99902`); `404` if no employee in your company has it. A path of its own because ids and codes are both numbers — `/employees/1000` is always the id. |
| POST | `/employees` | `employee_code` optional — the next free one is allocated. |
| PUT | `/employees/by-external-id/{externalId}` | **Upsert.** `201` + `meta.created=true` or `200` + `meta.created=false`. The URL's id wins over any `external_id` in the body. |
| PATCH / PUT | `/employees/{id}` | **Partial** — send only what changed. |
| DELETE | `/employees/{id}` | Record only, unless `?remove_from_devices=1` — see below. |
| DELETE | `/employees/by-code/{code}` | By **Personnel ID** (`employee_code`), from the system **and every terminal** — always, no flag. See below. |
| POST / DELETE | `/employees/{id}/photo` | multipart `photo`. |
| GET | `/employees/next-code` | |
| GET | `/employees/{id}/attendance-summary` | `from`, `to`. |

Fields are the web form's (`name`, `employee_code`, `email`, `department_id`, `job_title`,
`hired_date`, `access_level_ids`, …) plus:

- **`external_id`** — your id for this person, ≤ 128 chars, unique per company. Stored,
  never sent to a terminal.
- **`card_number`** — up to 32-bit. Stored; it reaches a terminal only through a push.
- **`push_to_device_ids`** — after saving, queue the person onto these terminals.
- **`push_to_mapped_devices`** (update only) — re-push to every terminal they are on now.
- **`employment_type`** — `full_time` (default) or `part_time`. A label: no attendance
  rule reads it (2026-10-12).
- **`resigned_at`** — `YYYY-MM-DD`, today or earlier; `null` = still employed. Setting it
  makes the employee inactive **and queues their removal from every terminal they are on**
  (`meta.device_commands`, or `meta.skipped_devices` for a terminal that cannot be
  written). The record and their card and fingerprint copies are kept (rule 19). Any
  `push_to_*` in the same request is ignored, and a resigned employee is never pushed —
  clear the date first.
- **`mobile_phone`, `home_phone`** — digits, spaces, `()` and `-`, optional leading `+`.
  The web form stores `"+973 33445566"`: the calling code, a space, the number.
- **`country`** — free text; the web form stores the English country name (`"Bahrain"`).

Read-only additions to every employee: **`company`** `{id, name}` — the top of the
department's branch (the department itself when `department_id` is a company) — beside
`department_id` / `department_name`. The free-text `department` follows `department_id`
when that changes, unless the same request sets `department` itself.

Device writes the call asked for are reported in `meta`:

```json
"meta": {
  "device_commands": [ { "id": 812, "command": "push_user", "status": "queued", … } ],
  "skipped_devices": [ { "device_id": 5, "device_name": "Glass Door", "reason": "…read-only…" } ]
}
```

Saving always succeeds or fails on its own. A terminal that cannot be written to is
**skipped and named**, never silently ignored (rule 3) and never a reason to refuse the
save.

### Deleting an employee, and taking them off the terminals

`DELETE /employees/{id}?remove_from_devices=1` answers **202**, not 200:

1. A `remove_user` command is queued per terminal they are on.
2. The employee is made **inactive immediately**.
3. The row is **deleted only after every one of those commands has succeeded**.

Why that order: the removal commands look the employee up by id, so deleting first would
make every one fail — and a person who is still enrolled on a terminal would then be an
orphaned, working door credential that nothing here could find.

If a removal **fails** (after its retries) or a terminal cannot be written to
(`meta.skipped_devices`), the row is **kept, inactive**, and you are told via the
`device_command.finished` webhook / the command row. Fix the terminal and call DELETE
again. Without the flag the call just deletes the record and is `200`.

**By Personnel ID — `DELETE /employees/by-code/{code}`** (2026-10-21). The same delete,
addressed by `employee_code` instead of `id`, and it **always** takes the person off the
terminals: there is no record-only form, because a record-only delete by code is how
casebook DC-025 left a deleted employee able to punch on three terminals.

- `{code}` is matched exactly as text: `0099902` does not find `99902`.
- `200` + `meta.deleted=true` — on no terminal, deleted at once.
- `202` + `meta.deleted=false` — removals queued (`meta.device_commands`), the employee
  is inactive now and deleted once every terminal confirms; or a terminal could not be
  written to (`meta.skipped_devices`) and the record is kept, inactive.
- `404` — no employee in your company has that code. `403` — no `employees:write`.
- `data` is `{id, employee_code, name}` of who it was, so the caller can confirm the
  number addressed the person it meant.

Each terminal removal saves the person's card and fingerprints into the system before
deleting (`DeviceUserSyncService::removeEmployee()`, rule 19); a terminal it cannot
save from is not cleared, and the record stays.

The same method answers `DELETE /api/employees/by-code/{code}` for a signed-in user
(`employees.delete`), so the screen-side and integration routes cannot behave
differently.

## 4. Terminals, enrollment and commands

| Method | Path | Ability |
|---|---|---|
| GET | `/devices`, `/devices/{id}` | `devices:read` — includes `capabilities`, `is_online`, `is_writable`, `write_refusal` |
| POST | `/devices/{id}/commands` | `devices:write` — body `{command, full?, from?, to?}`; `command` ∈ `sync_attendance`, `sync_enrollment`, `import_employees`, `reconcile_users`, `health_check`, `read_info`, `probe_capabilities`, `sync_time` |
| POST | `/employees/{e}/devices/{d}/push` | `devices:write` — queue one person onto one terminal → `202` + command |
| POST | `/employees/{e}/devices/{d}/remove` | `devices:write` — queue removal from one terminal (**record kept**) |
| POST | `/employees/{e}/devices/{d}/enrollment/fingerprint` | **Trigger fingerprint enrollment.** Body `{finger_slot: 0–9}`. The terminal beeps and waits ~30 s for a finger. |
| POST | `/employees/{e}/devices/{d}/enrollment/card` | Trigger card enrollment (terminal waits ~20 s for a tap). |
| DELETE | `/employees/{e}/devices/{d}/enrollment/fingerprints/{slot}` | Delete one finger. **Destructive** — the person must re-enroll it. |
| DELETE | `/employees/{e}/devices/{d}/enrollment/card` | Clear the card, keep the record and fingers. |
| PUT | `/employees/{e}/devices/{d}/card` | **Synchronous.** Write a typed `card_number`, read it back. May take seconds; `502` if unreachable. Only endpoint here that holds the request on hardware (no queued form exists yet). |
| GET | `/enrollment-sessions`, `/enrollment-sessions/{id}` | `devices:read` |
| POST | `/enrollment-sessions/{id}/cancel` | Releases the terminal. Do this if the person walked away. |
| GET | `/device-commands`, `/device-commands/{id}` | Poll an outcome. |
| POST | `/device-commands/{id}/retry`, `/cancel` | |

Enrollment is refused (`422`) when the terminal's capability for it is `not_implemented`
or `unknown`, and `409` if a capture is already waiting for that person at that terminal.
**Check `capabilities` on `GET /devices/{id}` before offering an action** — the UI rule
(rule 9) applies to integrations too: do not show a button for something the firmware
cannot do.

**Enrollment is a human at a terminal.** The call returns at once; the outcome is
`enrollment.finished` (webhook) or `GET /enrollment-sessions/{id}`. Session `status` ∈
`pending → sent → waiting → success | timeout | failed | cancelled`. A `timeout` means
nobody came, not that the terminal is broken.

**Never read "success" off the `202`.** It means *queued*. A command is only done when its
`status` is `succeeded` (rule 3/10: this firmware ACKs writes it then drops; the service
reads back before it says succeeded).

### Command lifecycle

`queued → running → succeeded | failed | cancelled`. A `failed` command with
`can_retry=true` is re-queued automatically with backoff; `is_finished` is the flag to
trust. `message` is for people, `error`/`raw_response` for whoever debugs.

One command runs per terminal at a time (the protocol is stateful per session), so a
second command to a busy terminal waits rather than failing.

## 5. Attendance

`GET /attendance` — a **feed**, not a screen's page.

| Param | |
|---|---|
| `after_id` | Return rows with `id` greater than this. **Persist `meta.next_after_id` and send it next time.** |
| `limit` | ≤ 1000, default 200 |
| `from`, `to` | punch time bounds (see Time) |
| `employee_id`, `external_id`, `device_id` | filters. An unknown `external_id` returns nothing, never everyone. |
| `matched` | `true` = linked to an employee, `false` = unclaimed PINs only |

Oldest first, keyed on the row id: ids only grow, so the cursor never skips or repeats a
row — **including a punch synced late** (old timestamp, new id), which a timestamp cursor
would miss. `meta`: `count`, `has_more`, `next_after_id` (null only when nothing matched —
keep your old cursor).

```json
{ "id": 90211, "punched_at": "2026-10-08T05:02:11+00:00", "employee_id": 41,
  "external_id": "HR-1042", "device_user_id": "17", "employee_code": "17",
  "employee_name": "Sara Ali", "device_id": 3, "device_name": "IT Room",
  "verify_method": 1, "status": 0 }
```

A punch from a PIN nobody has claimed has `employee_id: null` but **still appears**, with
`device_user_id` — it is never dropped. Terminals can be written to **after** a punch
exists, so link by `employee_id`/`external_id` when present and keep the PIN otherwise.

Also: `GET /reports/attendance`, `GET /employees/{id}/attendance-summary` (same as the web
app; per-day breakdown and payroll hours).

## 6. Webhooks

Push instead of poll. Manage with `webhooks:manage` **and** the `settings.manage`
permission.

| Method | Path | |
|---|---|---|
| GET | `/webhook-events` | the event names |
| POST | `/webhooks` | `{url, events:[…|"*"], description?}` → returns **`secret` once** |
| GET/PATCH/DELETE | `/webhooks/{id}` | re-enabling resets the failure count |
| POST | `/webhooks/{id}/rotate-secret` | old secret dies immediately |
| POST | `/webhooks/{id}/test` | sends a signed `webhook.test` |
| GET | `/webhooks/{id}/deliveries` | `?status=failed` — what was sent, what came back |
| POST | `/webhook-deliveries/{id}/redeliver` | same event id, same body |

**Events**

| Event | When |
|---|---|
| `attendance.punch` | new punches. **Batched**: `data.punches` is a list (≤ 100), same shape as `/attendance` rows. |
| `employee.created` / `.updated` / `.deleted` | `data.employee`, plus `data.changed` (field names) on update |
| `employee.enrollment_changed` | only fingerprint count / slots / card changed — split out so profile mirrors are not woken by every sync |
| `enrollment.finished` | a capture reached success/timeout/failed/cancelled |
| `device_command.finished` | a command reached a **final** state (a failure that will be retried is not announced) |
| `device.status_changed` | a terminal went online/offline |
| `webhook.test` | on request |

**Request**

```
POST <your url>
Content-Type: application/json
X-Webhook-Id:        3f1c…            # stable across retries — your idempotency key
X-Webhook-Event:     employee.created
X-Webhook-Timestamp: 1791450131
X-Webhook-Signature: sha256=9b2e…

{ "id": "3f1c…", "event": "employee.created", "created_at": "…", "tenant_id": 1, "data": { … } }
```

**Verify it.** `HMAC_SHA256(secret, "{X-Webhook-Timestamp}.{raw body}")`, hex, compared in
constant time against the header. Use the **raw** bytes, not re-serialised JSON. Reject a
timestamp more than ~5 minutes old (replay).

```php
$ok = hash_equals(
    'sha256='.hash_hmac('sha256', $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'].'.'.$rawBody, $secret),
    $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'],
) && abs(time() - (int) $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP']) < 300;
```

**Delivery semantics**

- **At-least-once.** Dedupe on `X-Webhook-Id` (or `data.punches[].id`).
- **Not ordered across events.** Do not assume `employee.updated` arrives before a later one.
- Answer any `2xx` fast (10 s timeout, redirects are **not** followed). Anything else, or a
  timeout, retries at +1 m, 5 m, 30 m, 2 h, 6 h, 12 h, 24 h (8 attempts, ~2 days).
- After **25 consecutive deliveries** that gave up, the endpoint is **disabled**
  (`disabled_at`). PATCH `is_active=true` once the receiver is fixed.
- A webhook is a **nudge, `GET` is the truth.** If you may have missed one (downtime > 2
  days), reconcile with `/employees?updated_since=` and `/attendance?after_id=`.
- Subscribing does **not** replay punch history; it starts from "now".

**URL rules.** `http(s)` only, no credentials in the URL. Loopback, link-local
(`169.254.x.x`, cloud metadata) and other reserved addresses are refused, for literals and
for names that resolve to them, at save **and** at every delivery. **Private LAN addresses
(10/8, 172.16/12, 192.168/16) are allowed** — this product runs inside the employer's
network and the customer's HR system is usually on it.

## 7. Things an integrator should know

- **PIN vs code vs your id.** Three different numbers: `external_id` is yours;
  `employee_code` is what a terminal keypad types; `device_user_id` is the PIN a particular
  terminal recorded. Do not assume the last two match across terminals.
- **Not every terminal can do everything.** Two of this estate's six are C3 panels that
  cannot write users or capture fingers. `skipped_devices` and `capabilities` tell you.
- **Read-only (unadopted) terminals refuse writes** until their existing roster has been
  claimed — this is what stopped real enrollments being overwritten. It surfaces as
  `is_writable=false` / `write_refusal`.
- **A deployment needs a queue worker and the scheduler running** (`php artisan
  queue:work`, `schedule:run` every minute). Without a worker every device command stays
  `queued` forever and no webhook is ever sent; without the scheduler backfilled punches
  never reach webhooks. `GET /device-commands` showing commands that never leave `queued` is the
  symptom.
- **Departments** are `/departments` (read `employees:read`, write `employees:write`).
  Create them before referencing `department_id`.
  A company is a department with no `parent_id`; its departments name it as their
  parent. `DELETE` answers 409 while the node still has employees or departments.
  Renaming one updates the free-text `department` of its employees that still held
  the old name.

## 8. How it fits the codebase (for maintainers)

- `routes/api_v1.php`, required from `routes/api.php`. Route names are `api.v1.*` —
  `RouteNameCollisionTest` requires the `api.` prefix.
- **Reuse (rule 13).** Devices, commands, enrollment, departments, attendance report and
  employee photo/summary routes point at the **same controllers as the SPA**; the v1 file
  only adds ability checks, the rate limit and idempotency. New v1 controllers exist only
  where an integration needs something the SPA does not: `V1\EmployeeController`,
  `V1\AttendanceController`, `V1\WebhookController`, `V1\TokenController`.
  **Consequence:** those reused responses share the SPA's resource shape, so a change to
  `DeviceResource`, `DeviceCommandResource` or `EnrollmentSessionResource` is an API change
  for integrators. Additive changes only; removing or renaming a field needs a `/v2`.
- **Tokens are Sanctum personal access tokens** with abilities (`IntegrationAbilities`),
  not a new table. Names are prefixed `integration:`.
- **`employees.external_id`** — migration `2026_10_08_100000`. Unique per tenant **when
  set**, as a *filtered* unique index on SQL Server (it allows only one NULL otherwise —
  `docs/database-sql-server.md`).
- **Webhooks** — tables `webhook_endpoints`, `webhook_deliveries` (migration
  `2026_10_08_100100`). `IntegrationEventObserver` (registered on `Employee`, `Device`,
  `DeviceCommand`, `EnrollmentSession`) → `WebhookDispatcher::emit()` → `DeliverWebhook`
  job. Retries are owned by the delivery **row**, as with device commands (rule 11).
- **Punches are NOT emitted from the sync code.** Bulk inserts raise no model events and
  `AttendanceSyncService` is the calibrated path (rule 15). `PunchWebhookRelay` reads
  `attendance_logs` past each endpoint's `punch_cursor`; `RelayPunchWebhooks` (on
  `PunchRecorded`) and a per-minute schedule both call it, under a lock.
- **Employee deletion** — `EmployeeOffboarding`; the delete runs from the observer when
  the last removal in its batch succeeds.
- **No protocol code here.** Everything device-side goes through `DeviceCommandBus` and
  the existing handler; nothing in this feature names a model or touches the library
  (rules 8, 15). `Capability` is not named in any new class.
- Tests: `tests/Feature/Api/IntegrationApiTest.php` (no device contacted; the queue is
  faked).

## 9. Not done, deliberately

- **No OpenAPI file.** The tables above are the contract for now.
- **No UI** for tokens or webhooks. They are API/CLI only.
- **No per-token IP allow-list**, no token-scoped device allow-list (a token reaches every
  terminal its user may).
- **No queued form of "assign card number"** — it is the one synchronous device call.
- **No fingerprint-template import/export over the API.** The bytes are biometric data and
  stay inside the system (`FingerprintVault`); only slot numbers are exposed.
