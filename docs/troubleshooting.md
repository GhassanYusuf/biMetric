# Troubleshooting

Every entry here is a failure that actually happened on this system.

---

## The device times out on everything

**Check in this order:**

1. `ping` the IP. Wrong address or a different VLAN is the most common cause.
2. Outbound **UDP** 4370 — not TCP. A firewall rule written for TCP will let a
   port scan through and still drop every packet the library sends.
3. A wrong comm key presents as a **timeout**, not as a clear rejection.
4. Reseller-branded firmware may not speak this protocol at all. If `Test` fails
   on the Binary protocol, try **CSL HTTP** with the device's web credentials.

---

## "Fingerprints unknown" for every employee

**Cause:** two bulk reads on one connection.

After `CMD_PREPARE_DATA`, the device sends all announced bytes whether or not
anyone is reading. Issue a second command on the same socket and the leftovers
arrive first — as well-formed `CMD_DATA` packets indistinguishable from the new
command's reply.

**Fixed** by opening a fresh session for every bulk read and marking the session
dirty afterwards (`BulkReader`, `Session::markDirty()`).

This bug also made a diagnostic probe report **"0 users"** on a device holding 19,
and made template slot probes come back shifted by exactly one request. If you see
any of those three symptoms, session hygiene is the place to look.

---

## A write appears to succeed but nothing changed

Two distinct causes:

1. **Missing `CMD_REFRESH_DATA`.** The device acks the write and then discards it.
   Every write path must send `CMD_REFRESH_DATA` (1013) afterwards.
2. **`group = 0`** in the user record. The record appears to write and the user
   cannot authenticate. It must be ≥ 1.

If neither applies, this firmware does intermittently drop writes — `UserService::save()`
reads back and throws `VerificationException`. Retry; if it persists, the device
may need a power cycle.

---

## An employee lost their card or admin rights after a rename

Privilege and card live in the **same 72-byte record** as the name. Writing it with
default values wipes both.

Call `save($userId, $name)` with `$privilege` and `$cardNumber` left `null` — null
means "preserve". Passing `0` means "clear".

---

## Punches are duplicated exactly 3 hours apart

`insertOrIgnore` **bypasses Eloquent casts**, so device-local `+03:00` time was
written straight into a UTC column.

This produced **6,432 duplicated rows**. The fix converts explicitly at the write:

```php
'punched_at' => Carbon::instance($c['punched_at'])->utc()->format('Y-m-d H:i:s'),
```

To detect it, count rows for one employee exactly 3 hours apart. After the fix
that count went 6,432 → 6, and those 6 are genuine in/out pairs.

---

## Syncs report success but recent punches are missing

**Check the watermark is not in the future.**

The watermark was once stored as `17:59+00:00` when the true UTC moment was
`14:59` — the same device-local-as-UTC bug. A watermark three hours ahead silently
skips three hours of real punches on every subsequent sync, with no error
anywhere.

```sql
SELECT name, attendance_watermark FROM devices WHERE attendance_watermark > NOW();
```

Any row returned is a live data-loss bug. Fix the conversion, then run a **full
re-read** to recover.

---

## A sync reports `read=9582 imported=0`

**This is correct.** The device has no server-side date filter, so every sync
transfers the entire log and discards what is already stored. On a settled device,
`imported: 0` with a high `duplicate` count is exactly what a healthy incremental
sync looks like.

Worry instead about **`undecodable > 0`**.

---

## `undecodable` is above zero

The device offered punches the decoder could not read. They are archived in
`attendance_raw_logs` rather than discarded, so they are recoverable.

The historical instance of this: a timestamp built with
`createFromFormat('Y-n-j-G-i-s', …)` where `i` and `s` require two digits. Any
punch with a minute or second below 10 returned `false` and was silently dropped —
**3,236 of 9,582 records, 34%**, for the entire life of the code.

After fixing and re-syncing, **2,823 records were recovered** and the table matched
the device exactly at 9,582.

If you see this count rise, capture the raw log rows and compare a failing record
against `Codec\Timestamp` before changing anything else.

---

## Commands sit in `queued` and never run

1. **Is a worker running?** `php artisan queue:work`
2. **A killed worker holds its `WithoutOverlapping` lock.** Every later command
   for that device is released until the lock expires (600s). Clear `cache_locks`
   or wait it out.
3. `MaxAttemptsExceededException` on the first attempt means `$tries` is too low:
   each `WithoutOverlapping` release consumes a queue attempt. `ProcessDeviceCommand`
   uses `$tries = 25`, `$maxExceptions = 1`.

---

## A command is refused with 422 before it runs

That is the capability gate, and it is information rather than a fault:

> IT Room does not support device.time.write: Implemented from protocol
> documentation but never confirmed on hardware — may fail.

Either the capability is `not_implemented`/`unknown`, or you are looking at a
deliberate refusal. Run `probe_capabilities` if the device's set has never been
measured. See [device-capabilities.md](device-capabilities.md).

---

## An enrollment never leaves `sent`

`waiting` is entered by the **first device event** (`beeped`), not by the job
starting. A session stuck at `sent` means the command never reached the terminal —
a network or session problem, not a person failing to turn up.

A session at `timeout` with a trail containing `beeped` means the opposite: the
hardware worked, and nobody presented a finger.

---

## Enrollment events appear twice

`["beeped","waiting_for_finger","timeout","beeped","waiting_for_finger","timeout"]`

Two possible readings:

- **Normal:** one session that retried. Events are appended in order, not
  deduplicated, precisely so a retry is visible.
- **The old bug:** the progress reporter persisted each event *and* the result's
  `events` array was appended again at the end. That append was removed. If every
  event is exactly doubled with no interleaving, something has reintroduced it.

---

## A "Too few arguments" error from a policy

A `DeviceGroup` is not a `Device`, so `DevicePolicy::update()` cannot judge it.
Group endpoints check `devices.*` permissions directly via
`authorizeDevicePermission()`.

---

## A user can see another tenant's device

`exists:devices,id` in a validation rule does **not** respect the global tenant
scope. Filter submitted ids back through the scoped model — see
`DeviceGroupController::tenantScopedDeviceIds()`.

Also confirm `ResolveTenant` runs **before** `SubstituteBindings`, or route model
binding resolves records before the scope applies.

---

## Diagnostic snippets

```bash
php artisan tinker --execute="
  \$d = App\Models\Device::first();
  echo json_encode(app(App\Contracts\BiometricDeviceDriver::class)->info(\$d), JSON_PRETTY_PRINT);
"
```

```bash
# Watermark sanity — anything returned is a live bug
php artisan tinker --execute="
  echo App\Models\Device::whereNotNull('attendance_watermark')
      ->get(['name','attendance_watermark'])->toJson(JSON_PRETTY_PRINT);
"
```

```bash
# Do our stored punches match the device?
php artisan tinker --execute="echo App\Models\AttendanceLog::count();"
```
