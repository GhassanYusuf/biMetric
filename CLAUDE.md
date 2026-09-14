# Project rules

Non-negotiable conventions for this codebase. Where a rule can be checked
mechanically it is, by `tests/Unit/FrontendConventionsTest.php`,
`tests/Unit/ProtocolLibraryBoundaryTest.php`, `tests/Unit/ProjectStateTest.php`,
`tests/Unit/DeviceModelIsolationTest.php` and `tests/Unit/ProbeDisciplineTest.php`,
all of which run with `php artisan test`.

Rules 1-7 govern the frontend. Rules 8-13 and 15-17 govern the biometric device
layer and apply to every future change that touches it. Rule 14 governs how the
state of the work is handed to the next session, on this machine or any other.

The numbering is referenced from code comments and from `docs/`, so rules are never
renumbered. A new one is appended.

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

`promoseven/biometric-protocol` (`Promoseven\Biometric`) is a standalone composer
package with its own repository, its own version tags and a proprietary licence.
It is NOT part of this application — this app is merely one of its consumers, and
it is sold to others. It must stay framework-agnostic.

It is resolved through composer and installed at
`vendor/promoseven/biometric-protocol` (a symlink to the working copy in
development). Editing it means editing a product other people pay for: bump its
version and update its own README, not just this app.

**Never** put Laravel in it: no `config()`, `now()`, `Log::`, Carbon, Eloquent,
facades, container. It takes a `DeviceConfig` value object, not a `Device` model.
The whole reason `DeviceConfig` exists is that the old code had
`ZktecoDevice::fromModel(\App\Models\Device)`, which made the protocol
un-reusable.

**Only `App\Services\Devices\ZktecoDriver` may SPEAK the protocol** — open a
session, build a config, touch a transport, issue a command. Everything else
depends on `App\Contracts\BiometricDeviceDriver`. Controllers never touch
protocol code: they call services, services call the contract, and
`test_controllers_never_construct_a_device_session()` enforces exactly that.

A larger set of classes may *name* library types — `Capability`,
`CapabilitySet`, `DeviceException` — in signatures, guards and catch blocks.
That is the intended coupling, and the authoritative list is the `$allowed`
array in `tests/Unit/ProtocolLibraryBoundaryTest.php`, each entry carrying the
reason it is there. Add to that list deliberately, with a comment, or not at all.

**Known debt, recorded in that test:** `Capability`, `CapabilitySet` and
`DeviceException` are the app's shared vendor-neutral vocabulary, yet they live
in the ZKTeco protocol package — which is why `CslHttpDriver`, which speaks HTTP
and no ZKTeco at all, still imports from it. That vocabulary belongs in its own
package and must move before a third vendor arrives.

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
  `vendor/promoseven/biometric-protocol/tests/Fixtures/` and `TemplateCodec`'s 22,066-byte real
  capture. The library's suite runs as the `Protocol` testsuite in `php artisan test`.
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

---

## 14. Session state is COMMITTED, not remembered

`docs/PROJECT-STATE.md` is the answer to **"where were we?"** Read it before
reading code, and before asking the person what they wanted.

**Why it is a file and not a memory:** assistant memory is per-machine. It does not
travel to a laptop, a second desk, a fresh clone, or a different assistant. The
repository does. Anything worth knowing next time therefore goes in the repo, or it
is lost the moment the machine changes.

### When it must be updated

Before finishing any session that:

- changed direction, scope, or what the product *is*
- took a commercial, licensing, naming or architectural decision
- left work in flight, uncommitted, or half-migrated
- ended with a question only the owner can answer
- discovered an operational fact the next person would otherwise rediscover the
  hard way — a locked directory, a missing worker, an exposed device

Routine work that changes no state needs no entry. A bug fixed and committed is
what the git log is for.

### What it must contain

| Section | Why |
|---|---|
| `Last updated: YYYY-MM-DD` | A state file with no date cannot be trusted. |
| Blockers to picking this up elsewhere | Below. |
| Repository layout | This app is no longer self-contained (rule 8). |
| What the product is | It stopped being obvious the day it became middleware. |
| Where we stopped | Done / next / open questions, separated. |
| Session log, newest first | Absolute dates. Never "yesterday" or "last week". |
| Operational facts | Ports, env quirks, device addresses, known traps. |
| Housekeeping debt | Cheaper to record than to rediscover. |

### The rules that make it worth reading

- **Say what is not committed or pushed.** A state file that documents work still
  sitting in one machine's working tree, without saying so, actively misleads. This
  is the first thing the file must establish, not a footnote.
- **Record the reasoning, not just the decision.** "Per-terminal licensing" is a
  fact; *why* it beat per-employer is what lets it be revisited.
- **Name who owes an answer** on every open question.
- **Absolute dates.** Relative ones rot the moment they are written.
- **Code wins.** If this file contradicts the code, the code is right and the file
  is stale — fix the file in the same session you noticed.
- **Correct it, don't append to it.** It is a description of the present, not an
  archive. Superseded statements get rewritten; only the session log accumulates.

`tests/Unit/ProjectStateTest.php` checks the structure — that the file exists, is
dated, and carries every required section. It cannot check that the contents are
true or current. That part is discipline.

---

## 15. One model per file. The F18 path is the reference and stays clean

**The ZKTeco F18 is the only model that works end to end with no open issues.**
Every timing, buffer size, codec offset and capability level in the library was
calibrated against one — IT Room, `192.168.7.62`. That is the working baseline and
the thing every other model is measured against.

**Every other model is a different device, not an F18 with quirks.** The F22 on
`192.168.7.63` refuses push reads and needs windowed ones, and refuses windowed
reads on the users table specifically. Camelot (`87.237.196.60`) demands the AUTH
exchange the LAN F18s do not. CSL is not ZKTeco at all. `docs/device-capabilities.md`
and the terminal table in `docs/PROJECT-STATE.md` record what each one actually did.

### The rule

**Never branch on model inside a shared file.** No `if ($model === 'F22')`, no
`// on the F22 also try…`, no second code path bolted into a working one. The
moment a model needs different behaviour, that behaviour gets **its own file,
named for the model**.

| The difference is in | It goes in |
|---|---|
| Protocol, transport or codec behaviour | `packages/zkteco-protocol/src/<Layer>/<Model>/…` — e.g. `src/Transport/F22/…`, beside the shared `UdpTransport` rather than inside it |
| A whole different protocol or vendor | Its own driver directory, `app/Services/Devices/<Vendor>/`, exactly as `Csl/` already is |
| What the device can do | A per-model `CapabilitySet` factory. `defaults()` describes the F18 and is not edited to make room for another model |
| Captured bytes | A fixture named for the model it came off. An F22 capture never overwrites an F18 one |

**The seam is selection, not branching.** Pick the implementation once, by model,
where the session or driver is constructed; everything downstream speaks the
interface and never learns which model it got. A conditional in the middle of a
read loop is the failure this rule exists to prevent.

**Do not touch the F18 path while chasing another model.** A model that misbehaves
is evidence about that model, not a bug report against the one that works. If a
change to genuinely shared code really is the right fix, it must be **re-confirmed
against the F18 before it lands**, and the commit must say so. "It still looked
fine" is not a confirmation.

**Name the model, not the attempt.** `F22WindowedReader`, not `BulkReaderV2`,
`BulkReaderNew`, `BulkReaderFixed` or `BulkReaderAlt`. In six months the model name
still means something and `V2` does not.

**A finding on one model says nothing about another.** This is rule 10 applied to
hardware: an offset, a reply code or a capability level confirmed on the F18 is
`unknown` on an F22 until an F22 has been read. Promoting it without that read is
inventing a fact. Mark every `CONFIRMED` in the code with the model it was
confirmed on.

### Order of work when a new model appears

1. **Probe and record first.** Capture what it actually does — replies, sizes,
   failures — into `docs/device-capabilities.md` and the terminal table in
   `docs/PROJECT-STATE.md`, with the address and the date.
2. **Write its files.** New model-named classes, new fixtures, new tests. Nothing
   shared is edited yet.
3. **Wire the selection.** One place decides which implementation a device gets.
4. **Only then consider sharing.** Two models proven to behave identically may be
   collapsed onto one implementation. One model plus a guess may not.

`tests/Unit/DeviceModelIsolationTest.php` enforces the mechanical half: a model name
may appear in comments anywhere — that is how findings are recorded — but may not
appear in executable code outside a file that is itself named for that model. It
cannot check that an F18 was re-read before shared code changed. That part is
discipline.

---

## 16. When there is no SDK, learn the device on the wire — read-only, controlled, written down

Sometimes there is no library, no documentation, and no vendor answer — only a
terminal on an IP that answers. That is not a dead end. This project has already
recovered a protocol that way: Camelot's handshake was solved on 2026-09-13 by
watching what it refused, and the F22's windowed reads were found by reading the
reply it gave instead of the one expected.

This rule is how that is done, so it is a method rather than a lucky afternoon.

**It applies only to devices we own or have written authorization to test.** A
terminal belonging to a customer is not a lab.

### Start with what is known. Escalate in order.

Most of these devices are dialects of the same few protocols; they differ at the
edges, not at the core. So never start from a blank sheet:

1. **Speak the known protocol first.** Handshake, `CMD_*` we already implement,
   exactly as the F18 accepts them. Most of it will work, and what works is the
   scaffolding for everything after.
2. **Vary one thing at a time.** Window size, pacing, session reset, auth key,
   transport. One variable per run, or the result explains nothing.
3. **Then the unknown.** Adjacent command numbers, undocumented replies,
   vendor-app traffic. By this point the session, the framing and the checksum are
   already proven, so a failure here is about the *command* and nothing else.

**Failing deliberately is the technique, not an accident.** Send the thing that
might not work, and read what comes back. A refusal carries the reply code, the
size the device thinks it has, and how far it got — the F22's users table refusing
with reply `4991` is how "windowed reads are per table, not per device" was
learned. Log every failure in full; the failures are the dataset.

### Always run a control in the same session

An F18 read, against the same code, minutes apart. A symptom is only evidence when
the device known to work does **not** show it — that is what the `control-itroom-*`
traces are for. Without a control, a network problem reads as a firmware finding.

### Capture bytes, not conclusions

Byte totals cannot tell "the kernel dropped it" from "the device stopped sending"
from "the network lost it". Timestamp every datagram, record kernel counters either
side, keep raw payloads before any parse. `tools/zkprobe/Probe.php` already does all
of this — extend it, do not write a second one (rule 13).

### Where the work lives

| | |
|---|---|
| Shared instrumentation | `tools/zkprobe/Probe.php` |
| One script per investigation | `tools/zkprobe/<subject>.php`, named for what it asks |
| Every run's output | `tools/zkprobe/traces-YYYY-MM-DD/<label>-NN.json` |
| Every trace | carries `label`, `host` and `started_at`, so a file that escapes its folder still says what it is |

**Traces are committed.** They are the evidence for every claim in `docs/`, and a
conclusion whose trace lives on one machine is an assertion, not a finding.

**Every probe script declares its blast radius in its header** — `READ-ONLY`, or
`WRITES TO THE DEVICE` with what it writes and how to undo it. The existing three
say `READ-ONLY` and say why each command is safe. Copy that.

### Read-only until the owner says otherwise

Reads cost nothing and can be repeated. A write can destroy an enrollment nobody
can re-collect without the employee standing at the terminal again.

- Discovery probes are **read-only**. Always.
- A write probe needs the **owner's explicit go-ahead**, a terminal out of service
  or a test user created for the purpose, and the current state read out and saved
  first.
- Never sweep command numbers blindly at a live terminal. Bound the range, log
  every send, and **stop the sweep** the moment a reply suggests state changed.
- A probe that throws yields `unknown`, never `not_implemented` (rule 9). A
  timeout is not evidence about firmware.

### Vendor traffic is the highest-value source — and the most dangerous to keep

If the vendor's own app or web portal talks to the device, capturing that exchange
answers in one afternoon what a sweep answers in a week. `tcpdump` on the host, or
the portal client we already have in `app/Services/Devices/Csl/`.

**But do not commit raw captures.** A real exchange carries employee names, card
numbers and fingerprint templates. Commit the *decoded structure*, the offsets, the
reply codes, and a **redacted or synthetic fixture** — never the `.pcap` and never a
payload with somebody's biometric data in it.

### Nothing graduates on one lucky run

A finding becomes code only after it is **reproducible** — repeated runs, on
separate sessions, with the control alongside. Then, in rule 8's order:

1. The finding is written into `docs/device-capabilities.md` and
   `docs/protocol-overview.md` — what was sent, what came back, **on what hardware,
   on what date** (rule 12).
2. A fixture from the real capture, and a test against it that needs no device.
3. The code, in a file named for that model (rule 15).
4. The capability moves to `implemented` — and to `confirmed` only after it has
   succeeded against the real terminal.

**Write down what did not work.** A negative result costs one line and saves the
next session a day: "pacing the reads at 100/300/1000 ms changed nothing on
Camelot" is worth as much as the thing that worked. Untracked failures get retried
forever.

`tests/Unit/ProbeDisciplineTest.php` checks what it can: that probe scripts declare
their blast radius, that traces are self-describing, and that no raw packet capture
is committed. It cannot check that a control was run. That part is discipline.

---

## 17. Research before you reverse-engineer

Rule 16 is how a device is learned on the wire. This rule is what happens *before*
the first packet: **the web almost always knows something, and an afternoon reading
beats a week guessing.** ZKTeco has sold these panels for fifteen years; someone has
already written down most of what a given model does.

The inBIO460 at `192.168.7.61` proved the point on 2026-09-14. It was recorded here
for weeks as "pings but does not answer" — assumed dead. Fifteen minutes of research
found that an inBIO is not a standalone terminal at all: it is a **C3 / PullSDK
access panel** speaking a completely different protocol from the F18 — TCP, not UDP,
a different frame, a different checksum. Two open-source implementations documented
the wire format precisely. Speaking it, the "silent" panel answered on the first try
with its serial number, firmware and full data-table schema. **The device was never
silent. We were speaking the wrong language.**

### The order, every time a new or unresponsive device appears

1. **Identify the model precisely first.** A model number is a research key. "inBIO"
   vs "standalone terminal" was the entire difference above. Read the datasheet:
   *panel* or *terminal*, which SDK (PullSDK / Standalone / Push), which transport.
2. **Search for the protocol before writing any code.** Vendor SDK docs, and —
   usually more honest — open-source reimplementations. For ZKTeco the useful ones
   found so far: `adrobinoga/zk-protocol` (standalone, the F18 family),
   `vwout/zkaccess-c3-py` and `-lua` (C3 / inBIO panels), `fananimi/pyzk`.
   Record the exact URL and commit in the probe header and the docs.
3. **Transcribe, do not invent.** A CRC, a frame layout or a command code from a
   reference is transcribed literally so that a mismatch is a finding about the
   *device*, not a bug in our arithmetic. `tools/zkprobe/inbio460.php` carries the
   C3 CRC-16/ARC exactly as the reference wrote it, for that reason.
4. **Try the known thing, and READ THE REFUSAL.** This is where research meets rule
   16. The inBIO refused both connect forms — `0x76` → error −13 "command not
   available", `0x01` → −98 — and the temptation is to read that as "no". It was
   not "no": every reply CRC was valid, so the panel was answering *semantically*.
   The working path was to send **no connect at all** — `GETPARAM`, `DATATABLE_CFG`
   and `GETDATA` all answer session-less. The reference's own assumption (always
   connect first) was the wrong thing to carry over, and the refusal replies are
   what said so. **A well-formed error is the device talking to you.**
5. **Only reverse-engineer from zero what research genuinely did not cover.** After
   steps 1–4 that is usually a small, well-bounded gap — for the inBIO, that
   `GETDATA` answers `0x0D` (a buffered-size + pull-token handshake) instead of
   `0xC8` with inline rows, so bulk reads need a second buffered pull the reference
   libraries do not show. That gap is documented and left for the next session
   rather than guessed at. A narrow known gap is a good place to stop; a blind
   command sweep is the last resort, not the first (rule 16).

### What research obliges you to record

- The **reference sources** — URL and, where it matters, commit — in the probe
  header and in `docs/`. A finding transcribed from a repo that later vanishes must
  still be traceable.
- **Where the device disagreed with the reference.** This is the highest-value
  output. "The C3 libraries always connect first; this firmware refuses connect and
  needs none" and "they expect `0xC8` inline rows; this one returns a `0x0D`
  buffered handshake" are the two facts that make the next integration possible, and
  neither is in any datasheet.
- **What research did NOT settle**, so the next person starts at the wire gap and
  not at the search box.

### Then it is a device feature like any other

A protocol learned this way is still subject to every other rule: its own
model-named files (rule 15), read-only until the owner authorizes a write (rule 16),
capability-gated in the UI (rule 9), documented (rule 12), and — because an inBIO is
a second vendor-shaped protocol, not a ZKTeco-standalone dialect — a new driver
implementing the contract, never a branch inside `ZktecoDriver` (rule 8).

`tests/Unit/ProbeDisciplineTest.php` already covers the mechanical part (blast-radius
headers, self-describing traces, no committed captures). It cannot check that you
searched before you guessed. That part is discipline — and it is usually the
difference between an afternoon and a fortnight.
