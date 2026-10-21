# Device identity and adoption

How a person on a terminal is connected to a person in this system, and why it is
not the obvious thing.

Written 2026-09-15, after the identity model that preceded it destroyed enrollments
in production. Read this before changing anything under `app/Services/Devices/`.

---

## The failure this exists to prevent

Every terminal in this estate was in service before this system existed. Each holds a
roster somebody else assigned, years ago, with no coordination between devices.

The old code connected the two sides by string-comparing the device's user id (the
"PIN") against `employees.employee_code`. Two things followed, both in production on
**2026-09-15**:

1. **Pushing employees overwrote strangers.** `UserService::save()` on this firmware
   family is an *upsert*. Writing to an occupied slot does not fail — it replaces the
   record, and the fingerprint templates with it. A template cannot be restored from a
   backup; the employee has to stand at the terminal and re-enrol.
2. **Adding a second device merged unrelated humans.** A PIN is a slot number local to
   one terminal. Measured across the six devices on the day:

   | PIN | IT Room | Entrance | Good Wheels | Camelot | Glass Door |
   |----|---|---|---|---|---|
   | 1 | akram | akram | HABIB UR RAHMAN | Ghassan | akram |
   | 5 | — | Suji | ANGELITO PEREZ | Timmith | Suji |
   | 10 | — | Josephin | ADNAN KHAN | Ebrahim | Josephin |

   16 PINs meant different people on different devices. Matching on the number alone
   collapsed each of those rows into one employee, and attached one person's punches
   to another.

Neither failure produces an error on any screen. That is why the fix is structural
rather than a validation rule.

---

## The three layers

| Layer | Identified by | Scope | Table |
|---|---|---|---|
| **Person** | national ID | the human, across every employer | `people` |
| **Employment** | `employee_code` | one person at one company | `employees` |
| **Device identity** | PIN | one person on one terminal, for a period | `employee_device_maps` |

The old model made one number do all three jobs. Only the third is something a
terminal understands.

### Person — keyed on national ID

> **National IDs were removed on 2026-09-27** at the owner's request: the column on the
> Employees screen, the form field, the roster filter, the Attendance lookup, the
> `PUT /api/employees/{employee}/national-id` and `POST /api/employees/lookup` endpoints,
> and in the database `person_national_ids` plus `people.national_id` /
> `people.national_id_hash` (migration `2026_09_27_110000_drop_national_ids`).
> Everything below about national ids describes the design as it was, and
> is kept for the reasoning. `people` survives with `hrms_person_id`.

The downstream HRMS links attendance to payroll by **national ID**, so that is the
join key. It is the one identifier we do not get to choose.

- Stored **encrypted** (`people.national_id`). Lookups go through
  `people.national_id_hash`, a deterministic HMAC over the normalised value — a blind
  index, searchable without being reversible. Set it only via
  `Person::setNationalId()`; assigning the column directly stores plaintext and leaves
  the hash stale.
- Normalisation strips case, spaces and punctuation before hashing. Three operators
  typing the same document three ways must not become three people.
- **Not tenant-scoped.** A person working across sister companies is the entire reason
  the table exists; scoping it would recreate the duplicate it collapses. Their
  *employments* are tenant-scoped.
- **Reissue is expected.** An iqama or permit renewal changes the number for the same
  human. The old one is superseded in `person_national_ids`, never overwritten, so
  payroll exports produced under it still reconcile.
- `status = unverified` until an ID is supplied. Attendance still collects; **HRMS
  export is blocked** rather than guessed at (`Person::isExportable()`).

### Employment

`employee_code` is unique **per tenant**, not globally. Two sister companies both
numbering from `001` is normal. It was global until 2026-09-15, which forced unrelated
humans to collide the moment a second tenant was onboarded.

### Device identity

A PIN is allocated once per `(device, employee)`, recorded, and **never re-derived**
from anything. `DeviceIdentityResolver` is the only thing that answers "what is this
employee's PIN on this device".

Mappings are **time-boxed** (`valid_from` / `valid_to`):

- A punch resolves through the mapping in force **at the punch timestamp**
  (`EmployeeDeviceMap::scopeInForceAt`), not the current one.
- A retired mapping is **closed, not deleted**. Deleting it orphans the attendance
  recorded under it.
- **A retired PIN is never reissued** to a different person. `DeviceIdentityResolver::nextFreePin()` skips
  every PIN the device has ever handed out, including those held by unclaimed people.
  **The same person may come back to it (2026-10-11):** `claim()` gives someone added
  back the PIN they last held there — or the one asked for — when our mappings only
  ever gave that PIN to them and its roster entry is the removal's reservation or
  their own claim (`pinWasOnlyEverTheirs()`); the entry becomes their claim again. A
  keypad-enrolled stranger at that PIN is still refused by the slot guard inside
  `saveUser()`. Before this, removing and re-adding Akram on IT Room put him on PIN 2.
  **A PIN ASKED FOR may take over a retired one (2026-10-21, casebook DC-026):** Syed's
  CPR was typed onto Asif, pushed to Good Wheels, corrected — and then refused to Syed
  for good. Since punches resolve by time, a new mapping starting now cannot reach the
  old holder's punches, so `assertPinFreeFor()` / `claim()` allow it when
  `pinReusableFor()` holds: every other holder's mapping on that PIN is **closed**; **no
  stored punch** under it is dated at or after the last of them ended (an unattributed
  one in the gap, or a terminal clock far ahead, would fall into the new window); and
  its roster entry is a removal's reservation or already this employee's. The entry
  becomes their claim. Automatic allocation (`nextFreePin()`) is unchanged — it still
  never hands out a used PIN; only an explicitly requested number (the Personnel ID)
  takes one over.
- `valid_from` is **null** for a mapping adopted off hardware. The person was already
  enrolled and we do not know since when; stamping the claim date would orphan every
  punch older than it. On the Glass Door panel that would have been 87,000 records.

---

## Adoption: the gate on writes

A device is read-only until somebody has looked at who is already on it.

```
unadopted  →  read_only  →  adopted
   │             │             │
 added        roster read   every entry decided
 no writes    no writes     writes permitted
 no reads     attendance
              syncs
```

`Device::assertWritable()` is called before **every** device write. Reads are
deliberately not gated — reading is how a device gets adopted, and it cannot destroy
anything.

### The write adopts the device itself (2026-10-11)

Owner's request, after repeatedly being sent to the Devices page to press "Allow
writes" on terminals whose every person was already matched — the case where the step
decides nothing. `assertWritable()` now calls `DeviceAdoptionService::adoptIfClear()`:

| Situation | What the write does |
|---|---|
| Already adopted | Proceeds. |
| Unmatched people already known on the terminal | Refuses at once, without touching the hardware, with a 409 naming up to five of them ("IT ROOM holds 2 people nobody has matched: ali-test (PIN 5), …"). Only a person can decide who they are. |
| Otherwise (never read, or read and fully decided) | Reads the roster afresh — a read — then adopts and proceeds if that read turns up nobody unmatched; refuses as above if it does. |
| The roster read itself fails | Refuses with the read's error. Nothing is written. |

The check is unchanged; it is run by the system rather than an operator. Adoption is
recorded against the user whose action triggered it (`adopted_by`). `isWritable()` —
and so the devices payload's `is_writable` and the propagator's skip list — is true when
the device is adopted or has no known unmatched entries, so the "Allow writes" button
only appears when there is something left to decide. Each push still reads its target
slot first (guard 3 below), so a write never lands on somebody either way.

### The flow

1. **`device:roster {device}`** — reads the terminal's user table into
   `device_roster_entries`. Read-only, repeatable. An entry already decided keeps its
   decision; a re-read never silently un-claims somebody.
2. **Decide every entry.** Each is a person already on the hardware:
   - **claim** — bind it to an employee, *at the PIN the device already uses*. Nothing
     is written to the device, which is what preserves their fingerprints. Issuing them
     a new number would mean re-enrolling every finger.
   - **ignore** — not a person we track (the installer's test account, a leaver). The
     row is **kept**, so the PIN stays reserved and the next read does not re-present it.
3. **`device:adopt {device}`** — refuses while anything is undecided. "Adopted with 40
   unknowns" is the state that allowed the original overwrite, so it is not a state the
   system can be in.

### Bulk helpers

- **`device:import-roster {device}`** — one new employee per unclaimed entry. Matches
  nothing against existing records. Use it for a terminal whose people are not in the
  system at all.
- **`device:suggest-claims {device} [--apply]`** — proposes employees by name for a
  *second* terminal. `--apply` claims only pairings unambiguous in both directions —
  exactly one roster entry and exactly one employee. Everything else is printed for a
  human.

  A name is a weak key: the field is 24 bytes, often abbreviated or transliterated, and
  the Entrance panel alone held 320 records under 259 distinct names. `--apply` is a
  convenience for bulk onboarding, **not** a claim that the match is proven. The
  national ID at the Person layer is what eventually proves two records are one human.

---

## The three write guards

Every device write passes all three, in order. Each caught a real failure.

| # | Guard | Where | Prevents |
|---|---|---|---|
| 1 | `Device::assertWritable()` | `app/Models/Device.php` | Writing to a terminal whose occupants are unknown |
| 2 | `DeviceIdentityResolver::pinFor()` | throws if unmapped | Addressing the device by payroll code |
| 3 | `assertSlotFree()` via `saveUser()`'s guard | driver, in-session | Overwriting the person in the slot |

Guard 3 is on the **driver contract** (`BiometricDeviceDriver::saveUser()`), not in a
service, for one reason: the read that proves the slot is free and the write that fills
it **must share one session**. This protocol is stateful and permits one session per
device (rule 11), so checking in one session and writing in another leaves a window —
and a window here means overwriting somebody's enrollment.

`DeviceIdentityResolver` holds the *decision* and no session, so it stays testable
without hardware and speaks no protocol (rule 8).

### The guard trusts the hardware over our records

`slotBelongsTo()` requires **both**:

1. our live mapping says this employee holds this PIN, **and**
2. the device's own roster has no record of that PIN, or records it as claimed by this
   same employee.

The mapping alone is not enough — it is our belief, and it goes stale when a PIN is
reassigned on the keypad or when it was written by the auto-matching this replaced. When
belief and hardware disagree, the hardware wins and the write is refused (rule 3).

---

## Attendance attribution

A punch is stored with the raw `device_user_id` **always**, attributed or not.

- Resolution goes through the mapping in force at the punch's own timestamp.
- Unresolved punches are stored, counted as `unmatched`, and **left unattributed**.
  A plausible employee id would be a lie; "unmatched" is a true statement about what
  we know (rule 10).
- **`attendance:reattribute [device] [--dry-run]`** recomputes attribution for stored
  punches. Claiming is a human decision that usually arrives *after* the sync, so
  without this a claim would only affect the future — and on a terminal that has been
  running for years, nearly all of its attendance is in the past.

### What unmatched actually means, measured 2026-09-15

| Device | Unmatched PINs | Still on device | Gone from device |
|---|---|---|---|
| IT Room | 59 | 3 (undecided) | 56 |
| On The Road | 12 | 2 (undecided) | 10 |
| Glass Door | 85 | 16 (undecided) | 69 |
| Entrance | 9 | 0 | 9 |

The large majority are PINs **deleted from the terminal** — leavers whose user record
was removed while their punches remained. Their attribution is unrecoverable and they
stay unmatched, correctly. The minority still on the device become attributable the
moment an operator claims them, via `attendance:reattribute`.

---

## Importing the staff list

```
php artisan staff:import staff.csv [--tenant=slug] [--dry-run]
```

The Employees screen creates one person at a time — right for a new hire, useless for
onboarding an estate. This is the bulk path.

Header row required; order irrelevant; unknown columns ignored.

| Column | | |
|---|---|---|
| `name` | **required** | |
| `employee_code` | **required** | payroll number, unique **within one company** |
| `national_id` | optional | the HRMS join key. Without it the person is `unverified` and **cannot be exported to payroll** |
| `email`, `department` | optional | |
| `tenant` | optional | tenant slug. Falls back to `--tenant`, then to the only tenant |

**The same `national_id` twice is not an error.** It is one human employed by two
sister companies: one Person, two Employments. That is the case the layer exists for.
Normalisation strips case, spaces and punctuation before hashing, so `860101123` and
`860-101-123` are the same document.

A re-import that omits `national_id` **never detaches** a person already identified —
that would block their payroll export with no visible symptom.

It writes nothing to any device and claims no roster entry. Importing staff and deciding
which device user is which human are separate steps: the second is a judgement about
hardware and belongs to the adoption flow.

### The order for a fresh start

```
php artisan identity:reset --employees-only   # clears staff, keeps rosters + punches
php artisan staff:import staff.csv            # your list, from HR
php artisan device:roster {device}            # if rosters are stale; read-only
php artisan device:suggest-claims {device}            # review
php artisan device:suggest-claims {device} --apply    # apply the unambiguous ones
php artisan device:adopt {device}             # once nothing is undecided
php artisan attendance:reattribute            # re-attach punches already collected
```

## Resetting

**`identity:reset [--force] [--keep-attendance]`** clears everything derived from the
terminals and returns every device to `unadopted`.

Keeps tenants, users, departments, devices and their probed capabilities — a device's
address and firmware facts are unaffected by an identity bug.

**Attendance cannot be re-read in full.** A ZKTeco terminal holds a bounded log buffer
and rolls old records off; whatever has rolled off exists only in the database. Copy the
database file first.

### `--employees-only` — start the staff list over, keep everything the hardware said

Clears people, employments, mappings, templates and cards. **Keeps** roster entries,
attendance and raw logs.

- `attendance_logs.employee_id` is `SET NULL`, so punches survive unattributed and carry
  their raw `device_user_id`. `attendance:reattribute` re-attaches them once the new
  staff are claimed — so the history is not lost by re-importing staff.
- Roster entries stay: they are what the terminals hold, and re-reading six devices
  yields the same bytes.
- `ignored` decisions stay. "This is the installer's test account" is a statement about
  the **device**, not the staff list, and it keeps that PIN reserved.
- Every `claimed` entry is released to `unclaimed`, and every adopted device drops back
  to `read_only`. An adopted device with undecided entries is the exact state that
  allowed the original overwrite, so it is not a state the system may sit in.

---

## What is still open

State as of **2026-09-15, end of session**: the staff list was deliberately cleared
(`identity:reset --employees-only`) so the owner can import the real one from HR.

| | |
|---|---|
| Employees / people | **0** — awaiting the HR import |
| Roster entries | 711, **all unclaimed** |
| Attendance | 151,264 punches kept, all unattributed |
| Devices | all six `read_only` |

- **The staff list has not been imported yet.** `staff:import` exists and is tested;
  the CSV from HR is what it needs. Everything downstream waits on that.
- **No UI for claiming.** The API exists (`GET/POST /api/devices/{device}/roster…`)
  but no screen consumes it. At the last full pass, 91 entries were ambiguous names that
  `device:suggest-claims` refuses to guess at — those need a person either way.
- **Merging duplicate humans across companies** happens through `national_id` at the
  Person layer. It cannot be done before the import supplies them.
- **C3 panels cannot write.** `saveUser()`/`deleteUser()` throw
  `UnsupportedCommandException` on `C3Driver` and `CslHttpDriver`. Declared, not
  silently plausible (rule 9).
