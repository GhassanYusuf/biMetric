# Integrating the library into Laravel

This is how *this* application does it, and it is the reference for anyone wiring
the package into another Laravel app.

> **The database underneath is SQL Server**, not SQLite, since 2026-09-21. Nothing
> in this file depends on that, but anything that persists what a device said does:
> see [`database-sql-server.md`](database-sql-server.md) for the cascade rules,
> the UTF-8 constraint on raw device values, and why a device id must never be
> `CAST` to an integer.

## The seam

```php
// app/Contracts/BiometricDeviceDriver.php
interface BiometricDeviceDriver
{
    public function run(Device $device, \Closure $callback): mixed;
    public function ping(Device $device): array;
    public function info(Device $device): array;
    public function capabilities(Device $device): CapabilitySet;
    public function probeCapabilities(Device $device): CapabilitySet;
    public function supports(Device $device, string $capability): bool;
}
```

`ZktecoDriver` implements it and is **the only class in `app/` that imports
`Promoseven\Biometric`**. Everything else depends on the interface.

```php
// app/Providers/AppServiceProvider.php
$this->app->bind(BiometricDeviceDriver::class, ZktecoDriver::class);
```

`tests/Unit/ProtocolLibraryBoundaryTest.php` fails the build if a second class
starts importing the library, or if a controller constructs a session directly.
The boundary is a test, not a convention.

## The driver

Its whole job is translating between Laravel's world and the library's:

```php
public function run(Device $device, \Closure $callback): mixed
{
    return ProtocolDevice::run(
        new DeviceConfig(
            host: $device->ip_address,
            port: $device->port,
            commKey: (int) ($device->comm_key ?? 0),
            timeout: 8.0,
            timezone: $device->timezone ?? config('services.zkteco.timezone'),
        ),
        $callback,
        $device->capabilitySet(),
    );
}
```

Config lookup, Eloquent, logging and the capability set all live here. None of it
crosses into the package.

### Three drivers, one contract

`DeviceDriverManager` picks by `Device::$protocol` and forwards. Adding the C3
panel driver touched **the manager, the model constant, the request rules and the
form** — no service, no job, no controller, no route.

| Protocol | Driver | Speaks |
|---|---|---|
| `binary` | `ZktecoDriver` | ZKTeco standalone, UDP/TCP 4370 |
| `csl_http` | `Csl\CslHttpDriver` | a reseller web UI over HTTP |
| `c3` | `C3\C3Driver` | ZKTeco C3 / PullSDK panels, TCP 4370 |

`run()` and `open()` hand out a standalone protocol session, so both non-ZKTeco
drivers throw `UnsupportedCommandException` for them rather than returning
something session-shaped that cannot serve a command. Everything else —
`ping`, `info`, `readAttendance`, `capabilities` — works uniformly.

**`readUsers()` closed a leak in the contract.** Until 2026-09-14 reading a roster
was expressed only as "call `run()` and use the ZKTeco `UserService` inside the
closure", so every consumer of a roster was hard-wired to one protocol. Two drivers
could not serve it — `CslHttpDriver` and `C3Driver` both throw on `run()` — which
meant **"Import staff" answered a 502 on a CSL terminal while its capability said
`confirmed`**. That was a latent bug, not a C3 one; C3 was simply the second victim.
`DeviceHealthService::identify()` had the same leak, so "Read from device" on the
add-device form could only ever identify a standalone terminal.

Both now go through the contract. Only two `run()` calls remain in the app, both
**writes** to a ZKTeco user record, both capability-gated.

**The C3 driver is read-only.** Every write is `not_implemented`, which the UI
hides (rule 9). That is deliberate and not an oversight: we know from the vendor
SDK exactly which commands would write, and rule 16 requires the owner's go-ahead
and a terminal out of service before any of them is probed.

**There is no cursor on a C3 panel.** `readAttendance()` returns the whole table
every time, so `AttendanceSyncService` owns the watermark and the dedupe — the same
way it already does for the CSL driver's overlapping date windows.

## Never call the driver from a controller

Controllers queue work:

```php
public function dispatchCommand(Request $request, Device $device): JsonResponse
{
    $this->authorize('sync', $device);

    if ($error = $this->capabilityRefusal($device, $data['command'])) {
        return ApiResponse::error($error, ['command' => [$error]], 422);
    }

    $command = $this->bus->dispatch($device, $data['command'], [...]);

    return ApiResponse::success(new DeviceCommandResource($command), "Queued …");
}
```

`DeviceCommandBus::dispatch()` writes a `device_commands` row and dispatches
`ProcessDeviceCommand`. The request ends. The outcome arrives over MQTT or by
polling the command.

## The job

```php
class ProcessDeviceCommand implements ShouldQueue
{
    public int $tries = 25;        // WithoutOverlapping RELEASES count as attempts
    public int $maxExceptions = 1; // a real throw is terminal

    public function middleware(): array
    {
        return [(new WithoutOverlapping("device:{$this->command->device_id}"))
            ->releaseAfter(10)->expireAfter(600)];
    }
}
```

One command at a time per device. Two concurrent sessions against one terminal
produce interleaved datagrams and neither result is trustworthy.

See [error-handling.md](error-handling.md) for why `$tries` must be far above 1.

## Persistence around each operation

| Table | Purpose |
|---|---|
| `device_commands` | the queue an operator can see, retry and cancel |
| `attendance_sync_runs` | one row per sync with five record counts |
| `attendance_raw_logs` | undecoded bytes, so a decoder fix can recover data |
| `device_status_logs` | health **transitions** only |
| `employee_device_maps` | who exists where, and whether it agrees |
| `enrollment_sessions` | durable capture state + device event trail |
| `access_cards` | card history, with sentinel detection |
| `biometric_templates` | which slots are filled per employee/device |
| `device_groups` | terminals addressable as a set |

Storing enrollment state means a browser that closes mid-capture no longer loses
the record of it.

## Persist what the device says, not what you assume

```php
'punched_at' => Carbon::instance($c['punched_at'])->utc()->format('Y-m-d H:i:s'),
```

`insertOrIgnore` **bypasses Eloquent casts**. Any fast-path write must convert
explicitly, or device-local time lands in a UTC column. That mistake duplicated
6,432 rows at a 3-hour offset. See
[attendance-sync-flow.md](attendance-sync-flow.md).

## Multi-tenancy

`BelongsToTenant` + `TenantScope` scope every device model. `ResolveTenant`
middleware **must run before `SubstituteBindings`**, or route model binding
resolves records before the scope applies.

`exists:devices,id` does not respect the global scope. Filter submitted ids back
through the scoped model:

```php
private function tenantScopedDeviceIds(array $ids): array
{
    return Device::query()->whereIn('id', $ids)->pluck('id')->all();
}
```

## Authorisation

Policies where the subject is a model (`DevicePolicy::sync($user, $device)`), and
direct permission checks where it is not.

A `DeviceGroup` is not a `Device`, so `DevicePolicy::update()` cannot judge it —
handing it the class name threw "Too few arguments". Groups check `devices.*`
permissions directly via `authorizeDevicePermission()`.

## Real-time

```php
try {
    $this->mqtt->publish(
        TopicBuilder::module($device->tenant_id, 'devices', 'status'),
        ['device_id' => $device->id, 'status' => $status, ...],
    );
} catch (\Throwable) {
    // MQTT only ENHANCES — a broker outage must never fail a health check.
}
```

Always tenant-pinned, always non-fatal, always through `TopicBuilder`.

## API responses

One envelope everywhere, via `Support\ApiResponse`:

```json
{ "success": true, "message": "…", "data": …, "meta": { "pagination": {…} }, "errors": [] }
```

Paginators are unwrapped so pagination lands in `meta.pagination` rather than
inside `data`.

## Recording a device failure

The library is framework-agnostic and cannot log for itself (rule 8), so the app
side owns that. `App\Support\DeviceErrorReporter` is the only place that does it:

```php
} catch (\Throwable $e) {
    $message = $this->errors->report($e, 'attendance sync', $device);

    return ApiResponse::error($message, [], 502);
}
```

`report()` writes one structured entry to the `device` channel — including the
exception's `context()` and `isRetryable()` — and returns the operator-facing
sentence. Never build that sentence at the call site: the wording lives in
`operatorMessage()` so it stays consistent across the queue and the API.

**Catch `\Throwable`, not just `DeviceException`.** A bug in a codec is not a
`DeviceException`, and it used to escape as a bare 500 `'Server error.'`. The
reporter tells the two apart — protocol `context()` for one, a stack for the other
— and refuses to put an internal message on an operator's screen.

Anything that escapes uncaught is handled by the `$exceptions->report()` hook in
`bootstrap/app.php`, which reports it and returns `false` so it is not logged
twice. Full detail: [error-handling.md](error-handling.md).

## Scheduling

```php
// bootstrap/app.php
$schedule->job(new CheckAllDeviceHealth)->everyFiveMinutes();
```

Health checks write a `device_status_logs` row **only on a transition**, so a
device polled every five minutes does not produce a row saying "still online".

`is_online` is **derived** from `last_seen_at`, never stored: a device that simply
stops answering never gets the chance to mark itself offline, so a stored flag
would keep claiming it is up. This is why the UI can show `health_status: online`
with `is_online: false` — rendered as **"Stale"**.

## Never address a device by `employee_code`

Added 2026-09-15, after doing so destroyed enrollments in production.

A device user id (the "PIN") is a **slot number local to one terminal**. It is not a
payroll number and it is not portable: on the six devices in this estate, PIN `1` is
three different humans. Deriving it from `employees.employee_code` merged unrelated
people and, because `save()` is an upsert, wrote over the person already in the slot.

```php
// WRONG — and this is exactly what the old code did
$driver->run($device, fn ($d) => $d->users()->save($employee->employee_code, $employee->name));

// RIGHT — mapped PIN, adoption gate, slot guard, one session
$device->assertWritable();
$pin = $identity->pinFor($device, $employee);
$driver->saveUser($device, $pin, $employee->name, $identity->slotGuard($device, $employee, $pin));
```

`DeviceIdentityResolver::pinFor()` throws when the employee has no identity on that
terminal, rather than inventing one. Creating an identity is `claim()` or the adoption
flow, both of which check the slot is free first.

See `docs/device-identity-and-adoption.md`.

## Checklist for a new device operation

1. Add a `DeviceCommand::*` constant.
2. Handle it in `DeviceCommandHandler`.
3. Gate it on a `Capability` in the controller — 422 before queueing.
4. Expose it through `BiometricDeviceDriver`, not by importing the library.
5. **If it writes to the device**: call `Device::assertWritable()`, take the PIN from
   `DeviceIdentityResolver::pinFor()`, and pass `slotGuard()` so the read that proves
   the slot is free shares a session with the write. There is no unguarded write path
   above the driver, and there must not become one.
6. Record its outcome on the command row (message *and* error), and report
   failures through `DeviceErrorReporter` — never a bare `Log::error`.
7. Publish progress over MQTT if it is long-running, non-fatally.
8. Add the UI action behind both the permission and the capability.
9. Write the doc — see rule 12 in `CLAUDE.md`.
