# Architecture overview

## The one rule that shapes everything

The protocol code is a **library**, not part of this application. It lives in
`promoseven/biometric-protocol`, depends on nothing but PHP, and could be installed
into a CLI tool or another platform tomorrow without carrying Laravel with it.

Exactly one class in `app/` is allowed to know it exists. That boundary is
enforced mechanically by `tests/Unit/ProtocolLibraryBoundaryTest.php`, which
fails the build on an `Illuminate\` import inside the package, on a second class
importing `Promoseven\Biometric`, or on a `require` entry beyond `php` in the
package's `composer.json`.

## The layers

```
┌──────────────────────────────────────────────────────────────┐
│ React SPA (/app)                                             │
│   pages/devices/*  employees/*  attendance/*                  │
│   never calls the protocol; only the JSON API + MQTT           │
└───────────────┬──────────────────────────────┬───────────────┘
                │ JSON over /api               │ MQTT/WebSockets
┌───────────────▼──────────────────────────────▼───────────────┐
│ Laravel application (app/)                                    │
│                                                               │
│  Http/Controllers/Api/*    queue work, never touch hardware    │
│  Services/Devices/*        orchestration, persistence, retries │
│  Jobs/*                    ProcessDeviceCommand (the worker)   │
│  Models/*                  DeviceCommand, AttendanceSyncRun…   │
│                                                               │
│  Contracts/BiometricDeviceDriver ◄── the seam                  │
│  Services/Devices/ZktecoDriver  ◄── the ONLY importer          │
└───────────────────────────┬──────────────────────────────────┘
                            │ framework-agnostic PHP
┌───────────────────────────▼──────────────────────────────────┐
│ promoseven/biometric-protocol  (31 classes, zero Laravel)          │
│                                                               │
│  Client/Device          front door: Device::run($cfg, fn…)     │
│  Client/DeviceClient    command dispatch, session hygiene      │
│  Service/*              UserService AttendanceService           │
│                         EnrollmentService TemplateService       │
│  Codec/*                Timestamp User Attendance Template      │
│  Protocol/*             Command constants, Packet build/parse   │
│  Transport/*            UdpTransport, BulkReader                │
│  Session/*              session lifecycle + dirty tracking      │
│  Capability/*           what this firmware can actually do      │
│  Exception/*            six typed failures, each retryable-aware│
│  Value/*                DeviceConfig Reply DeviceUser …         │
└──────────────────────────────┬───────────────────────────────┘
                               │ UDP :4370
                        ZKTeco F18 terminal
```

## Why the seam exists

`BiometricDeviceDriver` declares `run()`, `ping()`, `info()`, `capabilities()`,
`probeCapabilities()` and `supports()`. Services depend on the contract, not on
`ZktecoDriver`.

That is not architecture for its own sake. A second vendor's terminal, or the
CSL HTTP protocol already present on some reseller-branded units, becomes a new
driver rather than a rewrite of every service. And in tests the driver can be
swapped for a fake, which is the only way to test the sync logic without a
physical device on the network.

## Nothing blocks on hardware

Every device operation is queued. A controller writes a `DeviceCommand` row,
returns its id, and ends the request. `ProcessDeviceCommand` picks it up.

This is not an optimisation. A fingerprint capture waits up to 30 seconds for a
human to walk to a terminal and present a finger. Holding a web worker for that
is unacceptable, and worse, closing the browser used to destroy all record that
the enrollment had been attempted at all.

Two settings on that job are deliberate and easy to get wrong:

```php
public int $tries = 25;        // WithoutOverlapping RELEASES count as attempts
public int $maxExceptions = 1; // a real throw is terminal
```

`WithoutOverlapping` releases the job back to the queue when another command is
already running against the same device. Each release consumes an attempt, so
`$tries = 1` produced `MaxAttemptsExceededException` on the very first release.
Domain-level retries live on the `device_commands` row (`attempts`,
`max_attempts`), not in the queue — so the operator sees them.

## Real-time is an enhancement, never a mechanism

MQTT over WebSockets carries device health transitions, enrollment progress and
new punches. Every screen also works without it: the UI fetches on mount and on
demand, and `useMqtt` swallows broker failures silently.

Publishers wrap `publish()` in `try { } catch (\Throwable) { }` for the same
reason — a broker outage must never fail an enrollment or stop a health
transition being recorded.

Topics come from one place, `Services\Realtime\TopicBuilder`, and are always
tenant-pinned:

```
tenant/{tenantId}/module/devices/status
tenant/{tenantId}/module/enrollment/{sessionId}
tenant/{tenantId}/module/attendance/{employeeId}
tenant/{tenantId}/user/{userId}/notifications
```

A subscription must never begin with a wildcard; the tenant segment is what
stops one workspace receiving another's events.

## Multi-tenancy

`Tenant`, the `BelongsToTenant` trait and `TenantScope` (a global scope) mean
queries are tenant-scoped by default. `ResolveTenant` middleware establishes the
acting tenant and **must run before `SubstituteBindings`**, or route model
binding resolves records before the scope applies.

One trap worth naming: `exists:devices,id` in a validation rule does **not**
respect the global scope. `DeviceGroupController::tenantScopedDeviceIds()` filters
submitted ids back through the scoped model for exactly this reason — otherwise a
caller could attach another tenant's device by guessing an id.

## Authorisation

Sanctum tokens, spatie permissions: 34 permissions across 4 tenant roles, where
`tenant-admin` holds `'*'` (the 32 non-platform permissions). Route middleware is
a fast first gate; controllers and policies check again.

A `DeviceGroup` is not a `Device`, so `DevicePolicy` cannot judge it — the group
controller checks the `devices.*` permissions directly via
`authorizeDevicePermission()`. Handing a policy the wrong model type is how that
endpoint first failed with "Too few arguments".

## Where to read next

| If you want to | Read |
|---|---|
| Understand the wire format | [protocol-overview.md](protocol-overview.md) |
| Know what the firmware can do | [device-capabilities.md](device-capabilities.md) |
| Use the library elsewhere | [library-installation.md](library-installation.md) |
| Call the library | [library-api-reference.md](library-api-reference.md) |
| Trace a sync | [attendance-sync-flow.md](attendance-sync-flow.md) |
| Trace an enrollment | [enrollment-flow.md](enrollment-flow.md) |
| Handle failures | [error-handling.md](error-handling.md) |
| Wire it into Laravel | [integration-laravel.md](integration-laravel.md) |
| Wire it into Node/CLI/other | [integration-other-platforms.md](integration-other-platforms.md) |
| Call the HTTP API | [api-endpoints.md](api-endpoints.md) |
| Integrate another system (tokens, webhooks, punch feed) | [integration-api.md](integration-api.md) |
| Test any of it | [testing-strategy.md](testing-strategy.md) |
| Fix something broken | [troubleshooting.md](troubleshooting.md) |
| Know what changed and what is next | [change-log-or-roadmap.md](change-log-or-roadmap.md) |
