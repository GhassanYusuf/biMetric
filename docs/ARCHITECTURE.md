# Payroll SaaS — Architecture Plan (Phase 1)

A multi-tenant, API-first attendance & payroll platform for companies running
ZKTeco biometric devices. Governed by `docs/ENGINEERING_STANDARDS.md`.

## 1. Domain entities

| Entity | Purpose | Tenant-scoped |
|---|---|---|
| `tenants` | Company/organization account (the SaaS tenant) | — (root) |
| `users` | People who log in (admins, operators, employees) | yes |
| `roles` / `permissions` | Access control (spatie), `team_id = tenant_id` | per tenant |
| `departments` | Org groups within a tenant | yes |
| `employees` | Workforce records (may link to a `user`) | yes |
| `devices` | ZKTeco terminals (binary / csl_http) | yes |
| `attendance_logs` | Punches pulled/pushed from devices | yes |
| `holidays` | Company holidays (excuse absences) | yes |
| `attendance_waivers` | Per-employee excused days | yes |
| `notifications` | Persisted in-app notifications (read/unread) | yes |
| `audit_logs` | Who did what, when (compliance/history) | yes |
| `settings` | Per-tenant config (shift hours, tz, branding) | yes |

### Key relationships
- `tenant` hasMany `users`, `employees`, `departments`, `devices`, `holidays`,
  `notifications`, `audit_logs`, `settings`.
- `department` hasMany `employees`; `employee` belongsTo `department`, optional
  belongsTo `user`.
- `device` hasMany `attendance_logs`; `employee` hasMany `attendance_logs`,
  hasMany `attendance_waivers`.
- `user` hasMany `notifications`; morphs into `audit_logs` (actor).

## 2. Roles & permission matrix

Roles (seeded, extendable per tenant):

| Role | Scope | Typical permissions |
|---|---|---|
| `super-admin` | platform | `tenants.*`, impersonate, global settings (bypasses tenant scope) |
| `tenant-admin` | tenant | `*` within tenant: users, roles, employees, devices, settings, reports |
| `hr-manager` | tenant | employees.*, attendance.*, waivers.*, reports.view, notifications.* |
| `operator` | tenant | devices.*, attendance.sync, employees.view/push, enrollment.* |
| `employee` | self | me.view, attendance.view.self, notifications.view.self |

Permissions are grouped: `employees.{view,create,update,delete,push}`,
`devices.{view,create,update,delete,sync,enroll}`,
`attendance.{view,view.self,sync,waive}`, `holidays.*`, `users.*`, `roles.*`,
`reports.view`, `settings.*`, `notifications.{view,view.self,manage}`,
`audit.view`, `tenants.*`.

Enforcement: policies per model + `permission:` middleware on routes +
`can()` gates in services. Authorization is always on **permissions**.

## 3. MQTT event map

| Domain event | Publishes to | Payload |
|---|---|---|
| Punch recorded (`PunchRecorded`) | `tenant/{t}/module/attendance/{employeeId}` + `tenant/{t}/dashboard/{u}` | punch summary |
| Notification created | `tenant/{t}/user/{u}/notifications` | notification |
| Device status/sync change | `tenant/{t}/role/operator/alerts` | device + status |
| Waiver / assignment change | `tenant/{t}/module/employee/{id}` | change diff |
| Overdue / reminder | `tenant/{t}/user/{u}/notifications` | alert |

All publishes go through `App\Services\Realtime\MqttPublisher` (fail-safe), fired
from listeners on domain events. Notifications persist to `notifications` first,
then publish.

## 4. Folder structure (target)

```
app/
  Actions/            # single-purpose domain actions
  Http/
    Controllers/Api/  # JSON controllers (thin)
    Requests/         # Form Requests
    Resources/        # API Resources
    Middleware/       # ResolveTenant, EnsureTenantMatches, ...
  Models/             # + Concerns/BelongsToTenant, Scopes/TenantScope
  Policies/
  Services/
    Realtime/         # MqttPublisher, TopicBuilder
    Zkteco/           # EXISTING hardware-verified layer (reused as-is)
    Attendance/       # summary/report services (moved out of controllers)
  Support/            # ApiResponse, Tenancy context
resources/js/
  shared/             # api client, hooks, ui components, validation
  mobile/             # mobile-first pages
  desktop/            # desktop pages
  app.jsx             # SPA bootstrap + router + device-class switch
routes/
  api.php             # all business endpoints
  web.php             # app shell only
database/
  migrations/  models/factories/  seeders/
docs/
```

## 5. SQLite schema plan (Phase 2)

- New `tenants` table; add nullable→backfilled `tenant_id` to every existing
  domain table (`employees`, `devices`, `attendance_logs`, `holidays`,
  `attendance_waivers`, `users`) with an index and FK.
- spatie tables published with `teams=true` (team = tenant).
- New: `notifications` (persisted app notifications), `audit_logs`, `settings`.
- Preserve existing data: existing rows assigned to a bootstrap "default" tenant
  during migration so the running app keeps working through cut-over.

## 6. Cut-over strategy
Build API + new SPA alongside the current Inertia app against the same DB. Ship
per module. Retire Inertia screens as their API-backed replacements land. The
ZKTeco service layer (`app/Services/Zkteco`) is reused unchanged — it is
hardware-verified.

## 7. Build order
Phases 1→9 per the standards. This document is Phase 1.
