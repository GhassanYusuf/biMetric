# Project rules

Non-negotiable conventions for this codebase. Where a rule can be checked
mechanically it is, by `tests/Unit/FrontendConventionsTest.php` and
`tests/Unit/ProtocolLibraryBoundaryTest.php`, both of which run with
`php artisan test`.

Rules 1-7 govern the frontend. Rules 8-13 govern the biometric device layer and
apply to every future change that touches it.

---

## 1. Never use native browser dialogs

**Banned everywhere in `resources/js`:** `window.confirm`, `window.alert`,
`window.prompt`, and their bare globals.

They are unstyled and unthemeable, block the entire JS thread, cannot show rich
content or a destructive-action colour, and — worst — the browser is allowed to
**suppress** them. A suppressed `confirm()` returns `false`, which silently
cancels the action with no explanation to the user.

### Use instead

**Confirmations** — promise-based, so the call site reads like the thing it
replaced:

```jsx
import { useConfirm } from '@/…/shared/confirm';   // SPA
// import { useConfirm } from '@/Components/ConfirmDialog';  // legacy Inertia

const confirm = useConfirm();

const ok = await confirm({
    title: `Delete ${employee.name}?`,
    body: ['First paragraph.', 'Second paragraph.'],  // string or string[]
    confirmLabel: 'Delete employee',
    danger: true,      // red primary action
});
if (!ok) return;
```

Providers are mounted once at the root — `resources/js/spa/main.jsx` for the SPA,
`resources/js/app.jsx` for the Inertia app. Do not mount them per page.

---

## 2. Report outcomes with animated toasts, never inline banners

**Every result of an action** — success, failure, "21 records read" — goes through
a toast. Inline result banners are banned: they push the page down when they
appear, sit there long after they stopped being true, and end up duplicated in
different places on different screens.

```jsx
import { useToast } from '@/…/shared/toast';

const toast = useToast();

toast.success(res.message);
toast.error(err instanceof ApiError ? err.message : 'Could not reach the device.');
toast.info('Counts are stale — the template table would not decode.');
```

Toasts stack (capped at 4), auto-dismiss (success 4.5s, info 5s, error 8s), pause
on hover, and are dismissible. Bottom-centre on mobile, top-right on desktop.

### `InlineAlert` is still correct for PERSISTENT page state

The distinction is **event vs. state**:

| Use a toast | Use `InlineAlert` |
|---|---|
| "Card removed from Gassan." | "This employee is not on the terminal." |
| "Could not reach IT Room." | "Showing the first 100 of 300 employees." |
| "21 employee records read." | "No active terminal, so device actions are unavailable." |

If it describes something that just *happened*, it is a toast. If it describes
how things *currently are*, and stays true while the page is open, it is content.

### Form validation is neither

Field-level errors belong on the field, via the `error` prop on `TextField` /
`SelectField`. Do not toast a 422.

---

## 3. Never claim more than the hardware told us

ZKTeco reads have three distinct outcomes and collapsing any two is a lie:

- **never read** — we have not asked the device (`enrollment_synced_at` is null)
- **unknown** — the read succeeded but the firmware's table would not decode
- **a real value** — what the device's own tables say

A wrong `0 fingerprints` tells an operator somebody is unenrolled when they are
not. Decoders fail safe to "unknown" (`ZktecoDevice::decodeTemplateRecords`
returns `[]`), and the UI must keep the three states visually distinct.

Related: never report a device write as successful on the acknowledgement alone.
This firmware ACKs writes it then drops. Read back and confirm — see
`pushEmployeeViaBinary`, `deleteFingerprint`, `clearCard`.

---

## 4. Page content spans the full width

No centred `max-width` wrapper on page content; `<main>` in `DesktopLayout.jsx`
holds `<Outlet />` with padding only. Modals, the auth card, and search inputs
are not page content and may be constrained.

---

## 5. Mobile and desktop are separate presentation files

`Page.jsx` owns data and mutations; `PageDesktop.jsx` and `PageMobile.jsx` only
render. Keep behaviour identical between them — if one gets an empty state, a
keyboard affordance, or a loading skeleton, so does the other.

---

## 6. Loading states are skeletons, not centred spinners

Content-shaped placeholders so nothing shifts when data lands. `SkeletonTable`,
`SkeletonCards`, and `Skeleton` are in `shared/ui.jsx`. A spinner is fine inside
a button or beside a heading to show background work.

---

## 7. Accessibility floor

- Body and placeholder text ≥ 4.5:1. `text-slate-400` is **2.56:1 on white** and
  is reserved for icons and hairlines; use `text-slate-500` or darker for text.
- Every interactive element gets the shared `FOCUS` ring from `shared/theme.js`.
- Anything clickable is keyboard reachable, with `Enter`/`Space` handling.
- Dialogs close on `Escape` via `useEscapeKey(onClose, enabled)` — and refuse to
  while a write is in flight.
- Elevation is declared **once** per surface: a shadow *or* a border, never both.

---

## 8. The biometric protocol layer is a REUSABLE LIBRARY, not app code

`packages/zkteco-protocol` (`Zkteco\Protocol`) is a standalone composer package
that other platforms are meant to install and use. It must stay framework-agnostic.

**Never** put Laravel in it: no `config()`, `now()`, `Log::`, Carbon, Eloquent,
facades, container. It takes a `DeviceConfig` value object, not a `Device` model.
The whole reason `DeviceConfig` exists is that the old code had
`ZktecoDevice::fromModel(\App\Models\Device)`, which made the protocol
un-reusable.

**Exactly one class in `app/` may import from `Zkteco\Protocol`:**
`App\Services\Devices\ZktecoDriver`. Everything else depends on
`App\Contracts\BiometricDeviceDriver`. Controllers never touch protocol code —
they call services, services call the contract.

Adding a second vendor (Hikvision, Suprema, Anviz) means writing another
implementation of the contract and binding it, not editing a controller.

### Order of work for any device feature

1. The **library** first — protocol, codec, service, capability.
2. The **adapter** second — `ZktecoDriver`, if a new mapping is needed.
3. The **app** third — service, job, controller, route.
4. The **UI** last, gated on a capability flag.

Doing it in any other order is how protocol logic ends up in a controller.

---

## 9. Device support is CAPABILITY-DRIVEN, and capabilities have four levels

Two levels cannot express what this protocol is actually in.

| Level | Meaning | UI |
|---|---|---|
| `confirmed` | Exercised against real hardware in this project | Offer it |
| `implemented` | Written from protocol docs, never seen succeed | Offer with a warning |
| `not_implemented` | This library does not do it | **Hide it** |
| `unknown` | Never probed on this device | **Hide it** |

- The UI **must** hide unsupported actions. A button that returns 502 teaches
  operators to distrust the whole screen.
- Services guard with `$capabilities->require(Capability::X)`, which throws
  `UnsupportedCommandException` rather than returning a plausible empty result.
- `CapabilityResolver` probes **read-only only**. You cannot test "can I delete a
  fingerprint" without destroying somebody's fingerprint;
  `CapabilityResolver::unprobeable()` lists what must stay declared and why.
- A probe that throws yields `unknown`, never `not_implemented` — a network blip
  is not evidence about firmware.

---

## 10. Never claim more than the device told you

Three outcomes, always distinct:

- **never read** — we have not asked the device
- **unknown** — the read succeeded but the data would not decode
- **a real value** — what the device's own tables say

A wrong `0 fingerprints` tells an operator somebody is unenrolled when they are
not. Decoders fail safe to "unknown" (`TemplateCodec::decode()` returns `[]`), and
callers must never read that as "none".

**Never report a device write as successful on the acknowledgement alone.** This
firmware family ACKs writes it then silently drops. Read back and confirm — see
`UserService::save()`, `TemplateService::deleteSlot()`.

**Never store a device timestamp without converting to UTC.** The device stamps
LOCAL time. Laravel does not convert on save, and `insertOrIgnore`/`insert` bypass
casts entirely. Two separate bugs in this project came from exactly that: 6,432
duplicate rows at a 3-hour offset, and a watermark 3 hours in the future that
would have skipped real punches.

---

## 11. Every device action is queued, audited and fault-tolerant

- Device work goes through `DeviceCommandBus`, producing a `DeviceCommand` row.
  Nothing that writes to a device, or blocks on a human, runs in a web request.
- The **row** owns domain retries with backoff; the queue only owns releases.
  `WithoutOverlapping` releases count as queue attempts, so `$tries` must be high
  and `$maxExceptions = 1`.
- **One command at a time per device.** The protocol is stateful per session; two
  concurrent commands interleave replies and both answers become wrong.
- Queued jobs must restore tenancy from the row — there is no request, so no
  resolved tenant, and the global scope would query the wrong data.
- Operator-facing messages and technical detail are stored separately
  (`message` vs `error`/`raw_response`). One goes on screen, the other to whoever
  debugs it.
- Sync preserves raw payloads **before** parsing, and counts undecodable records
  rather than dropping them. A third of this project's attendance was silently
  discarded for months by a date-parsing bug; the counters exist so that cannot
  recur unnoticed.

---

## 12. Documentation is part of the change, not a follow-up

Any change touching biometric or device communication **must** update the matching
file in `docs/`:

| Change | Update |
|---|---|
| New/changed protocol command | `docs/protocol-overview.md`, `docs/library-api-reference.md` |
| New capability or firmware finding | `docs/device-capabilities.md` |
| Sync behaviour | `docs/attendance-sync-flow.md` |
| Enrollment behaviour | `docs/enrollment-flow.md` |
| New exception or retry rule | `docs/error-handling.md` |
| App-side integration | `docs/integration-laravel.md` |
| Anything a reuser needs | `docs/library-installation.md`, `docs/integration-other-platforms.md` |

Also required:

- **Every protocol assumption, firmware quirk and unsupported feature must be
  documented explicitly**, with what was observed and on what hardware.
- **Every new protocol command needs a test and a doc entry.** Codec tests run
  against captured fixtures with no device present — see
  `tests/Fixtures/zkteco/` and `TemplateCodec`'s 22,066-byte real capture.
- Mark everything `CONFIRMED` / `DOCUMENTED` / `EXPERIMENTAL` in the code itself,
  not only in prose.
- Write for the developer who maintains this later. No marketing tone.

---

## 13. Audit before you add

This is an existing production system. Before creating any file, class, table,
endpoint or screen, check whether an equivalent exists and extend it.

Already present and to be reused, never duplicated: multi-tenancy
(`BelongsToTenant`, `TenantScope`, `ResolveTenant`), auth and permissions
(Sanctum + spatie), the API envelope (`ApiResponse`), auditing (`AuditLog` +
`AuditObserver`), notifications (`NotificationService`), real-time
(`MqttPublisher` + `TopicBuilder` + `useMqtt`), the queue (`database` driver), and
the whole frontend design system in `resources/js/spa/shared/`.

Concretely: `devices` was extended rather than a parallel `attendance_devices`
created, and `audit_logs` was reused rather than a second audit table added.
