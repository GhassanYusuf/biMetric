# biMetric — Multi-Tenant Payroll & Attendance SaaS

A multi-tenant, API-first attendance & payroll platform for companies running
ZKTeco biometric terminals — talking to the hardware directly over the network,
with no vendor DLL, OCX or SDK anywhere in the stack.

- **Backend:** Laravel 12 · SQLite · Sanctum (token auth) · spatie/laravel-permission (teams = tenants)
- **Device layer:** `promoseven/biometric-protocol` — a separate, separately-licensed composer package (36 classes, **zero runtime dependencies**, PHP 8.2+), installed at `vendor/promoseven/biometric-protocol`. This app is one consumer of it, not its owner.
- **Real-time:** MQTT (php-mqtt) publish → browser MQTT-over-WebSocket subscribe
- **Frontend:** React SPA (Vite) consuming the JSON API — mobile-first, separate mobile/desktop presentation files
- **Legacy:** the original Inertia screens remain during cut-over; the SPA is the target

## Documentation

| | |
|---|---|
| [architecture-overview.md](docs/architecture-overview.md) | How the layers fit together and why the seam exists |
| [protocol-overview.md](docs/protocol-overview.md) | The wire format, as **observed** on real hardware |
| [device-capabilities.md](docs/device-capabilities.md) | The four honesty levels and how they gate the UI |
| [library-installation.md](docs/library-installation.md) | Installing the protocol package |
| [library-api-reference.md](docs/library-api-reference.md) | Its public surface |
| [attendance-sync-flow.md](docs/attendance-sync-flow.md) | The sync path, and the 34% data loss it recovered |
| [enrollment-flow.md](docs/enrollment-flow.md) | Fingerprint and card capture as durable sessions |
| [error-handling.md](docs/error-handling.md) | Typed failures, retry classification, silent-discard rules |
| [integration-laravel.md](docs/integration-laravel.md) | Wiring the library into this app |
| [integration-other-platforms.md](docs/integration-other-platforms.md) | CLI, Node, and porting notes |
| [api-endpoints.md](docs/api-endpoints.md) | All 73 HTTP routes + MQTT topics |
| [testing-strategy.md](docs/testing-strategy.md) | What is tested, what is not, and the hardware checklist |
| [troubleshooting.md](docs/troubleshooting.md) | Every failure that actually happened, and its fix |
| [change-log-or-roadmap.md](docs/change-log-or-roadmap.md) | What changed, known unknowns, what is next |

Conventions that are enforced rather than suggested live in
[`CLAUDE.md`](CLAUDE.md). Older references:
[`docs/ENGINEERING_STANDARDS.md`](docs/ENGINEERING_STANDARDS.md),
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed        # schema + tenants/roles + the owner account
npm install
npm run build                     # or: npm run dev
php artisan serve
```

- **Legacy Inertia app:** `http://localhost:8000/`
- **New API-first SPA:** `http://localhost:8000/app`
- **API base:** `http://localhost:8000/api`

To enable live MQTT updates in the browser, run an MQTT broker with a WebSocket
listener and set `MQTT_WS_URL` (e.g. `ws://localhost:9001`) in `.env`. Without
it the UI still works and simply fetches on demand (MQTT only enhances).

## The one login

Seeding is **destructive for accounts by design**: every `db:seed` run wipes the
`users` table and recreates exactly one account, so these credentials are always
valid afterwards.

| Email | Password | Scope | Role |
|---|---|---|---|
| `g.yusuf@promoseven.com` | `Gha$$an1983` | Default Company | tenant-admin (all 32 permissions) |

Override the password per environment with `OWNER_PASSWORD` in `.env`. It is
otherwise committed to this repository in `database/seeders/DatabaseSeeder.php`.

No demo or placeholder logins are created. The **Acme Industries** tenant still
gets departments and employees so multi-tenant scoping stays exercisable, but it
has no accounts. Tests mint their own role-scoped users (see `ApiFlowTest`)
rather than relying on seeded logins.

The owner is a tenant-admin rather than a platform super-admin on purpose: a
super-admin resolves to *no* tenant (`ResolveTenant` spans all tenants), which
would leave the SPA without a workspace or a live MQTT topic.

## Architecture at a glance

- **API envelope** — every JSON response is `{ success, message, data, meta, errors }` (`app/Support/ApiResponse.php`).
- **Tenancy** — `App\Support\Tenancy` + `BelongsToTenant` trait + `TenantScope` global scope. The acting tenant is resolved server-side by `ResolveTenant` middleware (runs *before* route-model binding, so cross-tenant ids 404). Never trusted from client input.
- **Auth** — Sanctum tokens; `Gate::before` grants super-admins everything.
- **Permissions** — spatie, team = tenant; authorize on permissions (not role names) via policies + controller checks.
- **Real-time** — domain events → listeners → `MqttPublisher` (fail-safe) on tenant-scoped topics; notifications are DB-persisted first (`NotificationService`).
- **Audit** — `AuditObserver` records create/update/delete of core models (secrets redacted).
- **Devices** — the protocol lives in the external `promoseven/biometric-protocol` package. Only `Services\Devices\ZktecoDriver` may *speak* it (open a session, touch a transport, issue a command); a documented allowlist of classes may *name* its types (`Capability`, `DeviceException`) through the `Contracts\BiometricDeviceDriver` seam. `tests/Unit/ProtocolLibraryBoundaryTest.php` fails the build if either changes.
- **Queued device work** — every device operation writes a `device_commands` row and returns its id; nothing blocks on hardware. A fingerprint capture waits up to 30 seconds for a person, which is why it must never hold a web worker.
- **Capabilities** — four levels (`confirmed` / `implemented` / `not_implemented` / `unknown`) gate every device action, refusing at the API with 422 before anything is queued. An unproven feature is offered and labelled *"unproven"* rather than being claimed or hidden.

### MQTT topics
```
tenant/{tenantId}/user/{userId}/notifications
tenant/{tenantId}/role/{roleName}/alerts
tenant/{tenantId}/module/{module}/{recordId}
tenant/{tenantId}/dashboard/{userId}
```

## API routes

Auth: `POST /api/auth/login` · `GET /api/auth/me` · `POST /api/auth/logout`

Tenant-scoped (require `auth:sanctum` + `tenant`):

| Module | Routes |
|---|---|
| Employees | `GET/POST /api/employees`, `GET/PUT/DELETE /api/employees/{id}`, `GET .../attendance-summary`, `POST .../devices/{device}/push`, `POST .../devices/{device}/remove`, `POST .../waivers` |
| Departments | `GET/POST /api/departments`, `GET/PUT/DELETE /api/departments/{id}` |
| Devices | `GET/POST /api/devices`, `GET/PUT/DELETE /api/devices/{id}`, `POST .../test-connection`, `POST .../commands`, `GET .../status-history` |
| Device groups | `GET/POST /api/device-groups`, `PUT .../devices`, `POST .../commands` |
| Command queue | `GET /api/device-commands`, `POST .../{id}/retry`, `POST .../{id}/cancel` |
| Sync history | `GET /api/device-sync-runs`, `GET .../{run}` |
| Enrollment | `GET /api/enrollment-sessions`, `POST .../{session}/cancel`, `POST /api/employees/{e}/devices/{d}/enrollment/fingerprint` |
| Reconciliation | `GET /api/device-maps`, `GET .../summary`, `POST /api/devices/{d}/reconcile` |
| Attendance | `GET /api/attendance` |
| Holidays | `GET/POST /api/holidays`, `PUT/DELETE /api/holidays/{id}` |
| Dashboard/Reports | `GET /api/dashboard/summary`, `GET /api/reports/attendance` |
| Notifications | `GET /api/notifications`, `GET .../unread-count`, `POST .../{id}/read`, `POST .../read-all` |
| Audit | `GET /api/audit-logs` |

## Tests

```bash
php artisan test
```

**139 passing, 766 assertions, ~10 seconds, no hardware required.** Everything runs
on in-memory SQLite, isolated from real data; no test opens a socket.

The suite concentrates on paths where being wrong looks exactly like being right —
timestamp decoding, undecodable-record accounting, capability claims, the library
boundary. Several tests exist purely so a specific past bug cannot return. See
[testing-strategy.md](docs/testing-strategy.md), which also carries the manual
against-hardware checklist.

## Known unknowns

Carried deliberately. Full detail in
[change-log-or-roadmap.md](docs/change-log-or-roadmap.md).

- **A completed fingerprint capture has never been observed.** The trigger is
  confirmed — the device beeps and the event trail proves the command lands — but
  finishing a capture needs a person at the terminal when the job runs.
- **Template decoding is verified against one device only** (a real 22,066-byte
  table). It fails safe to *"fingerprints unknown"*, never to *"none"*.
- **`verify_method` 3 and 4** are emitted by this hardware with no known meaning;
  stored verbatim rather than mapped to a guess.
- **The reference device's clock runs ~6.4 minutes fast** and drifts. `SET_TIME` is
  `implemented`, never confirmed, so the correction has **not** been fired.
- **2,823 attendance records recovered** by the timestamp fix go back to 2023 and
  **change historical payroll totals**.

## Notes

- `Tenancy` is process-static; correct for FPM. Under Octane/persistent workers,
  `Tenancy::set()` already forgets cached permissions on switch, and tests reset
  it per case — see `tests/TestCase.php`.
- Device work needs a queue worker: `php artisan queue:work`. A killed worker holds
  its `WithoutOverlapping` lock until it expires (600s).
