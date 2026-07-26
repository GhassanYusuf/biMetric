# Device capabilities

## Why four levels and not a checkbox

A boolean can only say "supported" or "not supported". That forces every
unverified feature into one of two lies: claim it works, or claim it does not.

So a capability has four levels:

| Level | Meaning | UI treatment |
|---|---|---|
| `confirmed` | Exercised successfully against real hardware in this project. | Offered normally. |
| `implemented` | The code exists and is believed correct, but **no device has ever confirmed it works**. | Offered, labelled *"unproven"*. |
| `not_implemented` | This library does not do it. | Not offered. |
| `unknown` | Never probed. We have no evidence either way. | Not offered; never described as missing. |

`usable` = `confirmed` or `implemented`. `Capability::describe()` returns the
sentence shown to an operator.

The distinction between `not_implemented` and `unknown` matters. The first is a
statement about our code. The second is an admission about our knowledge —
access-control scheduling may well work perfectly on this hardware; we have never
asked. Reporting it as "not supported" would be inventing a limitation.

## The current set for the ZKTeco standalone protocol

`CapabilitySet::defaults()` is the honest baseline. Every level below reflects
what was observed on the reference F18, not a datasheet.

### Confirmed (12)

| Key | Notes |
|---|---|
| `device.ping` | |
| `device.info` | Model, firmware, platform, serial, MAC, capacities |
| `device.time.read` | |
| `user.read` | Full user table via the bulk envelope |
| `user.write` | Requires `group ≥ 1` and `CMD_REFRESH_DATA` |
| `user.delete` | |
| `card.read` | One card per user |
| `card.write` | Writing 0 clears it |
| `fingerprint.enroll` | The *trigger* is confirmed; a completed capture is not — see below |
| `template.table.read` | The 22,066-byte table on the reference device |
| `template.slot.probe` | Per-slot occupancy check |
| `attendance.read` | |

### Implemented but unproven (7)

| Key | Why it is unproven |
|---|---|
| `device.time.write` | Never fired. The reference device's clock is ~6.4 minutes fast, but writing a wrong clock would misstamp every future punch, so this waits for a deliberate decision. |
| `template.delete` | `CMD_DELETE_USERTEMP` payload is inferred, not documented. |
| `card.enroll` | Live card capture never completed on hardware. |
| `attendance.realtime` | `REG_EVENT` subscription implemented; a live punch has not been observed arriving this way. |
| `attendance.clear` | Destructive. Deliberately never called to verify it. |
| `device.restart` | Deliberately never called. |
| `user.disable` | Approximated by clearing the card — the protocol has no suspend flag. |

### Not implemented (2)

`template.download`, `template.upload` — the library can read the template
*table* (which slots are filled) but does not transfer template blobs.

### Unknown (5)

`access.verify_mode`, `access.schedules`, `access.anti_passback`,
`access.duress`, `access.relay`.

Never probed. These may exist on the hardware. We claim nothing.

## How a capability reaches the operator

Three layers, all reading the same set:

1. **API refusal.** `DeviceSyncController::capabilityRefusal()` and
   `DeviceEnrollmentController::refuse()` return **422 before anything is
   queued**, with the level's description in the message. A command the firmware
   cannot run never enters the queue.

2. **UI gating.** `DeviceResource` exposes `capabilitySet()->forUi()` — every key
   with its `level`, `usable`, `confirmed` and `description`. The device detail
   screen hides unusable actions and appends *"(unproven)"* to usable but
   unconfirmed ones.

3. **The capabilities panel.** The device detail page lists all 26 keys with
   their level and a legend explaining what each level means, so nothing is
   hidden in a tooltip.

## Probing

`POST /api/devices/{device}/commands` with `probe_capabilities` queues a probe.
`CapabilityResolver` upgrades what it can verify non-destructively and leaves
everything else alone.

`CapabilityResolver::unprobeable()` exists because some capabilities cannot be
tested without doing the damage: you cannot verify `attendance.clear` without
clearing the attendance log, or `device.restart` without rebooting a terminal
somebody is standing at. Those stay `implemented` forever, and that is correct.

Probed results are persisted per device with `capabilities_probed_at`. Until a
probe runs, the device reports the protocol baseline — which the UI labels as
such rather than presenting it as a measurement of that unit.

## Adding a capability

1. Add the constant to `Capability` and to `Capability::all()`.
2. Give it an honest level in `CapabilitySet::defaults()` — `implemented` if you
   wrote it from documentation, never `confirmed` until a real device proved it.
3. If it can be probed safely, handle it in `CapabilityResolver`; if not, add it
   to `unprobeable()`.
4. Gate the endpoint on it.
5. Only after a successful run against hardware, promote it to `confirmed` — and
   say in the commit message which device and firmware proved it.

Promoting a level without hardware evidence is the one change that turns this
whole system back into a set of claims.
