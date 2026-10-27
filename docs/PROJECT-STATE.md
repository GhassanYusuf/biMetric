# Project state — read this first

**This file is the answer to "where were we?"**

It is maintained under CLAUDE.md rule 14. If you have just arrived on a different
machine, or after a long gap, read this before reading code.

Assistant memory does **not** travel between machines. This file does, because it
is committed. That is the whole reason it exists.

**Last updated: 2026-10-26**

---

## ⚠ Before you trust this file on a new machine

Several things currently break the "arrive on another machine and see everything"
promise. Fix them or the rest of this document is describing work that machine
cannot see:

| Blocker | State on 2026-09-13 |
|---|---|
| ~~The live library has no remote~~ **RESOLVED 2026-10-25** | It lives at **`/var/biometric-protocol/`**, and `vendor/promoseven/biometric-protocol` is a symlink to it. On 2026-10-25 it was pushed to **`GhassanYusuf/biometric-protocol`** (private): branch `main` at `2e9f4c1` (= `v2.16.2`) and all seven tags, `v2.3.0`–`v2.16.2`. The remote is `git@github-biometric:GhassanYusuf/biometric-protocol.git`. `github-biometric` is a host alias in `/root/.ssh/config` that uses this repo's own write-enabled deploy key, `/root/.ssh/id_ed25519_biometric`, because GitHub will not let one deploy key serve two repos. **Composer now installs it from GitHub** (owner, 2026-10-25): a `vcs` repository with `no-api`, constraint `^2.16`, locked at `v2.16.2`, installed from **source** so `vendor/` is a git clone that includes `tests/`. On this box, a global git `insteadOf` maps the canonical `git@github.com:` URL to the `github-biometric` alias. **Consequence: edits in `/var/biometric-protocol` are no longer live in the app.** A library change ships as commit → tag → push → `composer update promoseven/biometric-protocol` → `queue:restart` (`docs/library-installation.md`). A fresh machine needs only an SSH key that can read the repo. Composer runs as root here because the key is root's; chown `vendor/promoseven` to www-data after. |
| The tracked copy is the **wrong** one | `packages/zkteco-protocol/` (`Zkteco\Protocol`) is tracked but superseded and loaded by nothing. Reading it will mislead you. |
| ~~App depends on a path that does not exist~~ **FIXED 2026-09-14** | The path repository `../../biometric-protocol` resolves to `/var/biometric-protocol`, which was absent; **any** `composer require` failed outright, not just `composer install`. The library was copied there and composer now resolves and symlinks it normally. The copy is still nobody's git repository, so this only unblocks composer — it does not make the library safe. |
| ~~App repo not pushed~~ **RESOLVED 2026-10-25** | `origin` is `git@github.com:GhassanYusuf/biMetric.git`. On 2026-10-25 an SSH key was made on this box (`/root/.ssh/id_ed25519`, comment `zkt-server`, no passphrase) and the owner added it to the biMetric repo as a **deploy key with write access**, which covers that one repo only. GitHub's host keys are in `/root/.ssh/known_hosts`; their fingerprints were checked against GitHub's published ones. **All seven branches are pushed** with upstreams set: `main` (fast-forwarded `13775e2`→`69c50c0`), `camelot`, `employees`, `password-feature`, `export-employees`, `delete-by-code`, `wipe-terminal`. **Since 2026-10-25 every branch is merged into `main`, and this server runs `main`.** Nothing has been merged into `main`; that is the owner's call. `docs/database-diagram.md` stays untracked until the owner decides on it. The library was pushed the same day to its own repo with its own deploy key (see the row above). |
| ~~The C3 driver is uncommitted and half outside git~~ **RESOLVED 2026-09-14** | App committed as `69748ae` on branch `camelot`. `/var/biometric-protocol` is now a git repository with a **`v2.3.0` tag** and 70 tracked files. **It still has no REMOTE** — the history exists but lives only on this box, so pushing it somewhere is the remaining half of this blocker. On 2026-09-24 the working tree (2.7.0–2.9.0) was committed as `2180866` on `main`; **not tagged**. |
| **The vendor SDK dump is deliberately NOT in git** | `draft/` (the owner's ZKAccess 3.5.5 install and `draft/analysis/`) is gitignored because it holds real employee names, card numbers and fingerprint templates. Every conclusion drawn from it is written into `docs/`; the source is not portable and must be re-supplied by the owner if needed again. |

Done 2026-10-25 except one step: the library has its own repo with tags, composer
installs it from there, and the app is pushed. **Still left:** retire the stale
in-tree copy `packages/zkteco-protocol/`, which nothing loads.

---

## Repository layout

```
Projects/
├── biometric-protocol/     ← THE PRODUCT. Own repo, v1.0.0, proprietary licence.
│                             origin = GhassanYusuf/biometric-protocol (2026-10-25). 43 files, ~4,700 lines of src/.
└── payroll/app/            ← Reference consumer + demo. origin = biMetric.git
                              Consumes the library from GitHub by tag (vcs, source).
```

On this machine the library's working copy is `/var/biometric-protocol/`, pushed to
`GhassanYusuf/biometric-protocol`. Since 2026-10-25 `vendor/promoseven/biometric-protocol`
is **not** a symlink to it any more. It is a separate git clone at the locked tag, so
working-copy edits reach the app only through tag → push → `composer update`. See the blockers
above before touching composer.

---

## What the product is

**Middleware, not an HRMS.** This was clarified on 2026-09-02 and it reframes
everything earlier in `docs/`.

```
Biometric terminals  →  OUR PRODUCT (the bridge)  →  customer's HR platform
```

- **Our customer is the HRMS vendor**, not the employer. Their customer is the employer.
- `payroll/app` is a **reference consumer and demo. It is not for sale.**
- We supply device connection, firmware handling, attendance collection and
  enrollment plumbing. The HRMS owns employees, shifts, payroll rules and the UI.

### Commercial decisions taken

| Decision | Detail |
|---|---|
| Pricing unit | Per connected terminal, per year, billed to the HRMS vendor, who marks it up. OEM/redistribution licence. |
| Delivery | On-prem container ("the bridge") that runs inside the employer's network. **Relay mode recommended** — container dials out, so no inbound firewall rules. |
| Protection | Don't ship source. The container is the protection. Composer/source access is a premium, contract-only tier with per-customer build fingerprints. |
| Naming | `promoseven/biometric-protocol`, namespace `Promoseven\Biometric`. The vendor trademark is out of the package name and namespace; it remains in the description and keywords as descriptive/nominative use. |
| Licence enforcement | An expired licence blocks new terminals and updates. **It must never stop attendance collection.** Bricking payroll costs more than the piracy. |

---

## Where we stopped

### Catering is a UA300 — writes on since library 2.18.0 — 2026-10-26

Catering (192.168.7.120) is a **ZKTeco UA300/ID** (identified 2026-10-25, DC-032). The owner
had set its row to "F18" (2026-10-25 13:19 UTC); corrected to `UA300/ID` at 2026-10-26 07:45
UTC. While it said F18, the owner's employee merges moved ~17 workers on it to their CPR IDs
through the app and removed four resigned workers, and the owner reports it all worked.
**Why not leave it as F18:** a different board, and the one write compared so far (the
clock) behaved differently, so its levels must be its own.

Library **2.17.0** added `Model\UA300` (reads). The owner-approved write test passed
2026-10-26 08:19 UTC; **2.18.0** (owner committed and tagged it; installed the same day) sets
`user.write` C and `user.delete`, `card.write`, `template.upload` I. Catering is back in
Access levels on its own profile, with a warning on delete, card and fingerprint.

**Riza Teodosio** (resigned) is NOT on Catering: deleted at 07:31 UTC, link left open by a
timeout. Removing her gave a 502 ("not on the device") until the removal fix of 08:5x UTC;
the owner's next save closes her link. **Library 2.18.1** (owner tagged it, installed
2026-10-26) corrects 2.18.0's wrong Riza finding and sets the UA300's `user.delete` C. **Clock write** on the UA300 is
untested: run `tools/zkprobe/ua300-write.php 17 --clock-only` once it has drifted 30 s.

Catering's punches (DC-031, fixed 2026-10-25): 673 re-linked; **1,438 under 22 user IDs no
longer on the terminal stay unattributed. Owner to say who they were**, starting with 702
(481 punches, until 2025-10-08).


### The scheduler was NEVER running until 2026-10-18 — cron now installed

Found 2026-10-18 when the owner asked whether punches are read daily: they were not.
No crontab existed for root or www-data, so `schedule:run` never fired — not the 05:00
morning routine (clock set + range read of the previous and current day, DC-019), not the 02:30 fingerprint
copies, not the 5-minute health check. Every sync since 2026-10-11 had been pressed by
hand; the last one was 2026-10-15, Camelot's last attempt (2026-10-13) failed.

Installed for www-data on 2026-10-18:
`* * * * * cd /var/www/zkt && php artisan schedule:run >> /dev/null 2>&1`
and queued a catch-up `sync_attendance` on all six terminals (commands 158–163).
All six succeeded the same day: imported Good Wheels 112, On The Road 20, Camelot 122,
Glass Door 51, Entrance 53, IT ROOM 32. Camelot again returned 17,203 of 42,352 records
undecodable (archived raw — the known over-the-internet desync, see the 2026-09-14
Camelot section); Entrance had 84 punches not matched to an employee.
Since 2026-10-20 the daily read is a cron job (next section); the full-table read
stays off — the clock-then-read job is the daily read the owner asked for.

**The 5-minute health check never worked until 2026-10-20** (DC-024): `->tap()` on a
model threw before queueing, and the stuck rows made every later run skip all four
ZKTeco terminals. Fixed and committed 2026-10-20 (`413374c`); first working run 07:45 UTC.

### Keypad passwords and terminal roles written from the system — DONE 2026-10-20, committed 2026-10-21 (not pushed)

Branch **`password-feature`** (from `employees` at `41181b2`), committed 2026-10-21, plus library
**2.14.1 / 2.15.0** in `/var/biometric-protocol` (committed `ada8f15`, tag `v2.15.0`, no remote). Owner:
"apply the changes of writing password from the system to all … also the admin and
normal user thing". Every terminal was proven with a temporary "zkt-test" user, twice
(probes `tools/zkprobe/user-password-write.php`, new `user-privilege-write.php`; traces
`tools/zkprobe/traces-2026-10-20/`): `password.write` CONFIRMED on F18, F22, inBIO460,
TF1700; `user.privilege` CONFIRMED on F18 and F22 (C3 panels have no admin to grant).
A push now writes the employee's keypad password everywhere, and the form's **Device
role** is applied on a save when CHANGED (Admin/Super admin → administrator, User →
normal user, Enroller/Not set untouched) — never when unchanged, because real roles
differ per terminal. Per-room roles stay on the Enrollment dialog's Make administrator.
DC-022 closed. The owner's test employee "Test" (1802, code 1000) was deleted and taken
off every terminal on 2026-10-21 (DC-025).

### Delete by Personnel ID, system AND terminals — BUILT 2026-10-21 on `delete-by-code`

Owner, for an integration: "an endpoint to delete an employee from the system and from
the biometric terminals". `DELETE /api/v1/employees/by-code/{code}` (integration token,
`employees:write`), same method at `DELETE /api/employees/by-code/{code}` (signed-in
user). Queues removal from every terminal (card and fingers saved first), deactivates
at once, deletes the record once every terminal confirms. Docs: `integration-api.md`.

Why it always clears terminals: the first version (same morning) deleted the record
only. The owner deleted "Test User" (employee 1802, Personnel ID 1000) with it at
08:12 UTC and could still punch — casebook **DC-025**. With the owner's go-ahead, user
1000 was removed from IT ROOM, Entrance and On The Road at 09:03 UTC (record and finger
saved first, verified by re-read). IT ROOM's reservation of 1000 (roster entry 1433)
was then deleted at the owner's request, so code 1000 is free there again. The endpoint
was then proven live on IT ROOM (test employee 1804, 09:17 UTC: removed in 8 s, record
deleted on confirmation, read back). The "Test" employee note under the password feature no longer applies.

### 2026-10-22 — Camelot's lost attendance recovered; punches can be WRITTEN to an F18

Camelot was wiped from outside the system on 2026-10-21 (no backup) after ~4½ months
of misaligned reads: its June–October attendance existed only as undecodable raw bytes
(DC-020/DC-028). Rebuilt offline from the raw archive (4,770 punches the system lacked);
Camelot's pre-wipe log reassembled (42,415 of 42,423), written onto the test F18
IT-Office, pulled by USB by the owner, and named for HR
(`draft/Camelot_Attendance_20Sep-21Oct_2026_named.pdf`). **Owner owes:** whether to
import the 4,770 into the system; confirm Camelot ids 9 ("TUHIN") and 14 ("Suzeth").

**Punch write PROVEN on F18 firmware Ver 6.60 (ZMM210_TFT):** `CMD_SET_DATA` (3001) with
an `ATT_LOG` text record (from `taha-rafi/php-zkteco` `bf7805c`). Not yet a library
feature or capability — owner wants a "copy onto a replacement terminal" feature built
from it (library → driver → command → UI). Probes `tools/zkprobe/attlog-write.php`,
`attlog-copy.php` (both refuse any terminal but IT-Office).

Also 2026-10-22: `GET /api/employees/by-code/{code}` and `/api/v1/…` (by Personnel ID,
its own path because ids and codes are both numbers); DC-027 closed on hardware (first
finger written to an empty terminal; wipe's linked-user path proven); **DC-029 OPEN** —
mistaken Postman deletes of Charmae and Monir half-ran on Camelot (records kept, fingers
gone, records now drop new fingers): both reactivated with punches and stored fingers;
owner to choose re-add from the system or re-enrol at the terminal. Camelot still holds
Winnie under 900177098 (Monir's CPR) — move her to 841270180 there.

IT-Office (device #18, 192.168.7.64) disconnected by the owner, not needed: the device row
and 42,418 copied punches imported by a sync at 08:45 UTC are still in the system —
owner to say remove.

### Wipe terminal — BUILT 2026-10-21 on `wipe-terminal`, run on hardware 2026-10-21

Owner: a Devices action that removes every fingerprint and card, asks whether to save
the punches, and saves a linked user's fingers or card only when the system lacks them.
Owner's answers, **for this feature only** (CLAUDE.md **rule 22**, the one exception to
rule 19): the terminal is emptied of users; "don't save" erases the punches unsaved;
users linked to no employee are deleted with nothing kept; F18 only. Devices → Actions
→ **Wipe terminal…** (`DeviceWipeService`, `DeviceUserSyncService::wipeUsers()`,
`WIPE_DEVICE`, `POST /api/devices/{id}/wipe` with `save_punches` and `confirm_name`),
library **2.16.0** (`device.wipe`, `UserService::deleteAll()`). First real run: on a
test F18, with 2–3 test employees on it (one finger the system lacks, one card).

### A Personnel ID may take over a closed-off terminal number — 2026-10-21 on `delete-by-code`

Syed Wajid Abbas's CPR (770280340) had been typed onto Muhammad Asif on 2026-10-11 and
pushed to Good Wheels; corrected 2026-10-21, after which the never-reissue rule refused
770280340 to Syed for good. Changed (`DeviceIdentityResolver::pinReusableFor()`): a
requested number may take over a retired one when nobody holds it, nothing was punched
under it after the last holder left, and its roster entry is not someone else's claim.
Automatic numbering is unchanged. Syed is now 770280340 on Good Wheels, read back
(DC-026). 315 punches under user 8 on Good Wheels, 2024-07-28 → 2025-04-21, are
attributed to nobody: owner says user 8 was **someone else** back then, so they are not
Syed's and stay unattributed. Who that was is unknown (owner/HR, if it matters for
those months' records). The employee form now
offers the F18 user-ID move too (`idMoveMethod()` in `devices/parts.jsx`, the server's
rule: in place, or re-add), shows the hint whenever a terminal's ID differs from the
Personnel ID, and **always asks before a re-add even with auto-push on** (auto-push is
ON; 14 people on the F18s had a mismatched ID: Camelot 11, Good Wheels 3). Not yet
checked in a browser — no browser automation on the owner's side.

### Employee list export, PDF or Excel — BUILT 2026-10-21, committed on its own branch (not pushed)

Owner: "if I select all employees, export all of them in PDF or Excel; if I select one
of the companies, export that company's employees". Employees screen → **Export** →
PDF / Excel. Exports exactly what the screen lists, every page of it: the tree node
(all / company / department and below / unassigned), search, terminal and status. The
filters moved out of `EmployeeController::index` into `EmployeeRosterQuery`, shared by
both, so the file cannot list different people from the screen. `EmployeeRoster` builds
the rows; `pdf.employee-roster` (landscape) and `EmployeeRosterWorkbook` (a column per
terminal) write them. A terminal whose fingers were never read prints "?", never 0
(rule 10). `GET /api/exports/employees[/excel]`, `employees.view`. Real roster: 236
current staff → 16 pages in ~3 s; PROMOSEVEN alone 141.

It is on branch **`export-employees`** (`5bfafe7`, from `41181b2`), **not** on
`password-feature`: the two are separate branches that both touch `Employees.jsx` and
`EmployeeController.php` (different hunks), so merging both into one line should need no
hand-resolution, but that has not been tried.

### Cron jobs screen — BUILT and committed 2026-10-20

Owner: first "another read of punches … make sure the clock is set before reading …
10am daily Bahrain", then "add another tab, call it cron job, that shows the cron jobs
that exist and lets users add another and tinker with the time". Owner's choices: jobs
are **actions from a fixed list, never typed commands**, and **every schedule moves to
the new screen** (out of Settings). Sidebar → Company → **Cron jobs** (`settings.view` /
`settings.manage`): the company's jobs (add, edit times and days or a cron expression,
pick terminals, pause, run now, delete) and the system's own, read-only.
`scheduled_jobs` table, `ScheduledJob`, `ScheduledJobRunner` (was `DeviceMorningRoutine`),
`ScheduledJobController`, `schedules` live stamp. Migration ran on this server on
2026-10-20: the only job is *Set clocks, then read the past day* at `0 5,10 * * *`;
the five `devices.clock_sync_*` / `attendance.sync_*` settings rows were removed (the
one stored value, `0 5,10 * * *`, is in the job). Details:
`docs/attendance-sync-flow.md`, "Scheduled reads".

### Users screen with permission levels — BUILT 2026-10-19, committed 2026-10-20 (`1eb76d4`)

Owner: "add users tab that has permissions levels and add users". Chose **editable
levels** (Administrator locked to everything) and **the admin sets the starting
password** (no invite e-mail — outgoing mail was never set up). Sidebar → Company →
**Users** (`users.view`). Two tabs: Users (add, edit, set new password, deactivate,
delete) and Permission levels (the four built-in levels plus custom ones, a tick-grid of
every permission by group). `UserController`, `RoleController`, `PermissionCatalogue`
(the labels), `ManagedUserResource`, `UserPolicy`; `User` is now audited; `users` live
stamp. Guards, all server-side and tested (`UserManagementTest`, 13): nobody changes their
own level or deactivates/deletes themselves; the last active Administrator cannot be
removed; nobody grants a level or adds a permission they lack; deactivating or a password
reset signs the person out; a user with any history is deactivated, not deleted. No
migration. As of today the owner is the only user.

### Monthly Statement PDF (ZKTeco layout) — BUILT 2026-10-19, committed 2026-10-19

Owner supplied `draft/OTR-SEP.xlsx` (gitignored, real staff) — the ZKTeco "Monthly
Statement Report" the company files — and asked that our PDF carry the same data.
Reports screen → **Monthly statement**; `GET /api/exports/monthly-statement`
(`from`, `to`, `department_id`), `MonthlyStatement`, `pdf/monthly-statement.blade.php`.
Same 17 columns, every calendar day, totals, Checked/Approved By. Built on
`AttendanceSummaryService` (which now also returns `off_days`: punches on non-work days).

Checked against the xlsx for OTR, 2026-08-20 → 09-21: **161 of 165 day rows identical**
(In, Out, Work to the minute). The four differences: three 09/21 rows (the xlsx was made
at 09:41 that day, before the Out punch) and Mohammad Hussain 08/31, where ZKTeco ignored
a 06:42 punch we hold. Karthik 09/17 also differs: ZKTeco caps Work at 12:00 and moves the
rest to OT2; we do not cap.

**Totals deliberately differ** where ZKTeco's rules were wrong or absent: it counted
15:00 Absent on every day without punches (Fridays included) and never computed
Late/Early. Ours follow the company's **attendance rules, set on the Holidays screen**
(2026-10-19): weekend, shift start/end, and the Late / Left early / Left late allowances,
stored as `attendance.*` settings and read only through `App\Support\AttendanceRules`.
Until somebody saves them they are the old defaults — 09:00–18:00, Fri+Sat off, 15 min —
so OTR's ≈17:00 departures still print as ~1h Early. Open question 6.

Max 60 people per statement PDF (dompdf ≈ 0.46 s/page; 40 people took 18.6 s). Above that
it asks for a department. For everyone, the Reports screen's **All employees ▾ → Excel**
(2026-10-19) writes the same statement as a workbook — `GET
/api/exports/monthly-statement/excel`, `MonthlyStatementWorkbook`, block per person plus a
Summary sheet, real dates/times/`[h]:mm` durations. 235 people: 3.4 s, 63 MB peak. Uses
**`openspout/openspout` ^4.28 — a new composer dependency** (streams rows; PhpSpreadsheet
would not fit 235 people in the 256 MB limit). `composer.json`/`.lock` changed, committed 2026-10-19.

**Later on 2026-10-19, committed 2026-10-20 (`094b61a`):** the Excel (and the statement PDF endpoint) can be
run for chosen people — `employee_ids[]`, one or many, from a search-and-tick picker in
the dialog ("Who: Everyone / Departments / Chosen people"); under Departments, several companies or departments can be ticked at once (`department_ids[]`, each with its sub-departments). The menu item is now
**Monthly Statement · Excel**, and the Reports button is **Employees ▾**. Also fixed: the
department filter said "and below" but matched only that department's own staff; it now
takes sub-departments too (`Department::subtreeIds`), so a company includes its
departments. Note: our P7H department holds 2 people; ZKTeco's P7H sheet
(`draft/P7H-SEP.xlsx`) lists 30 who are filed under other departments here.


Owner asked for "an amazing PDF report for attendance", for management / HR, and chose
both reports. Committed 2026-10-19; frontend rebuilt, so it is live.

- **Company attendance report** — Reports screen → **Attendance report** (beside the
  renamed **Payroll PDF**). `GET /api/exports/attendance-report`, `AttendanceReport`,
  `pdf/report/attendance.blade.php`. Cover with KPIs vs the previous period, day-by-day
  outcome columns, departments, arrival histogram, weekdays, "Needs attention" lists,
  perfect attendance, no-punch list, everyone as appendix, definitions.
- **Employee report, any period** — Employee Report screen → **Export PDF** opens a
  range picker (presets for the previous and current calendar month, 30 days, or any dates).
  `GET /api/exports/employees/{id}/report?from&to`, `PersonalReport`,
  `pdf/report/employee.blade.php`: charts plus every punch of every day (terminal, method).
- **Every employee's report as ONE PDF** — Reports screen → **Employee reports** (owner
  chose one combined file over a ZIP). Built in the background by `BuildEmployeeReports`
  in chunks of 6 so device commands interleave on the single worker; contents page, then
  each person; joined with `pdfunite`. Live progress list on the Reports page,
  notification when done, files kept 7 days. First real run: 132 people, September 2026,
  68 s, 417 pages, 12.8 MB (run 3, left in place for the owner to download).
  **Operational:** migration `2026_10_18_100000_create_report_exports_table` is APPLIED
  to production; `poppler-utils` (pdfunite) is now a RUNTIME dependency — a new server
  needs it; the queue worker was restarted (`queue:restart`) to load the job. Files are
  written by www-data under `storage/app/private/report-exports/` — create it as root
  and the worker cannot write (that failed the first run, 2026-10-18).
- Shared: `App\Support\PdfCharts` (SVG charts; palette validated), `ReportFormat`,
  `pdf/report/layout.blade.php`. Font subsetting on for every export (~800 KB → ~130 KB).
- **Fixed for all five exports:** the footer said "Page 1 of 0" (dompdf ignores
  `counter(pages)`); now drawn on the canvas.
- Tests: `AttendanceReportTest`, `PersonalReportTest`, more in `ExportTest`; full suite 663 green.

**Why rates exclude people with no punch at all:** on 2026-09 data 73 of 132 people had
none (AVANA 18 of 20, Camelot 9 of 9, PROMOSEVEN parent 13 of 13…). Pooled, attendance
read 35.1% instead of 78.6%, i.e. a terminal not syncing reported as mass absence. They
are listed by name and the all-in rate is printed beside the headline, so nothing is
hidden.

**Open — owner (Ghassan), found while building it:**
- **103 active employees have no hire date and have never punched**, so no report
  counts them at all (the report says so). Add hire dates, or deactivate them?
- **"Left early" is 51.5% of September's person-days.** The configured shift ends 18:00
  (`ATTENDANCE_SHIFT_END`). If the real day ends earlier, that config is wrong, not
  half the staff. Owner to confirm the shift.
- **"Average minutes late" is inflated** (184 min company-wide in September): the shared
  `stats()` counts a lone afternoon punch on a no-punch-out day as a late arrival. The
  company PDF leaves that figure out; the Employee Report screen still shows it. Change
  the definition for both, or keep it?
- **Today counts while it is still happening:** a range ending today marks people not
  yet in as absent and people not yet out as "no punch-out". Long-standing in the
  summary service; the reports inherit it. Should the default range end on the previous day?

### Clear punches, admin/normal user, action outcomes — BUILT 2026-10-18, app committed 2026-10-19

Owner's requests the same day, all in the working tree (app) and `/var/biometric-protocol`
(library **2.14.0**). The app was committed 2026-10-19; **the library is still not committed**. The frontend was rebuilt, so it is live.

- **Clear punches from a terminal** (`AttendanceClearService`, `clear_attendance`,
  `POST /api/devices/{id}/clear-attendance`, devices.update). Saves every punch first and
  refuses unless the read matched the terminal's own count (`GET_FREE_SIZES`, decoded for
  the first time and CONFIRMED on F18 + F22) and every record decoded; verifies the count
  is 0 afterwards. Staff, cards and fingers untouched. ZKTeco only: `attendance.clear` I on
  the F18, U on the F22. **Never run on hardware.** Why built: Camelot's 42k-record log is
  slow and reads misaligned (DC-020).
- **Admin / normal user per terminal** (`UserService::setPrivilege`, contract
  `setUserPrivilege`, `PUT /api/employees/{e}/devices/{d}/privilege`, the employee dialog).
  Owner chose this meaning of "change the access on the device". `user.privilege` I on the
  F18, U on the F22, N on C3. **Never run on hardware.**
- **Animated outcome on the control that was pressed** (owner chose "button + toast"):
  `Button`/`ActionMenu` `result`, `useActionResult`, `waitForCommand`. Device pages now
  follow a queued command to its END and report its own message; "Queued…" is an info
  toast, not a success.
- **Junk punches stopped at the door:** empty user id → undecodable (library); a punch
  dated >1 day ahead → archived, not imported, never the watermark (app). DC-020.

**Open — owner (Ghassan):**
- **Camelot must not be cleared yet.** Its newest correctly read punch is 2025-11-13;
  17,203 of its 42,352 records arrive misaligned, so ~a year of its punches exist here
  only as raw bytes. The clear refuses on exactly that. Next: a read-only windowed read of
  Camelot (the F22 method) to try for a clean read. Assistant can run it; it is read-only.
- **First clear and first privilege change** are write probes (rule 16): which terminal,
  when. IT ROOM would pass the clear's guard today (11,332 read = its count, 0 bad).
- **The 2,876 junk Camelot rows** already in `attendance_logs` (blank user, dated
  1999–2030, incl. the "2030 punch"): remove them? They are not real punches; deleting is
  a production data change, so it waits for a yes. Back them up first.

**Next session:** confirm the 2026-10-19 02:00 UTC morning run produced `sync_time` +
range-read commands on every terminal (DC-023).

### HR's five employee changes are LIVE, migration APPLIED (2026-10-12)

From an email HR sent the owner: Company vs Department, job title as a short field,
resigned, part-time, job title in the list. Also new: the Companies & departments page.
Details in the session log below.

Migration `2026_10_12_100000_add_employment_fields_to_employees` was **applied to the
SQL Server database on 2026-10-12 at 07:58 UTC, with the owner's go-ahead**. All 235
employees are `full_time`, and none is resigned. `Employee::hasEmploymentColumns()` stays
in the code as a guard for any database that has not run it. Committed 2026-10-19.

### Integration API `/api/v1` is BUILT, tested, and NOT DEPLOYED (2026-10-08)

Owner asked for an API so other software can integrate: employee CRUD, remove from
devices, trigger fingerprint enrollment, read attendance, "and anything I missed".
Contract and rationale: **`docs/integration-api.md`**. 24 new feature tests; full suite
515 passed.

**Two migrations exist and have been run NOWHERE except in-memory test databases** —
production (SQL Server) does not have them, and the new endpoints will 500 on it until it
does: `2026_10_08_100000_add_external_id_to_employees` (filtered unique index on sqlsrv)
and `2026_10_08_100100_create_webhook_tables`. `php artisan migrate` on this box asks for
confirmation (production env) and was cancelled on purpose. **Owner's go-ahead needed.**
Nothing is committed (standing arrangement for the `employees` branch).

Decisions and the reasoning:

- **Reuse over rebuild (rule 13).** Devices, commands, enrollment, departments, reports
  point at the SPA's own controllers with ability checks added. Cost: those response
  shapes are now an integrator-facing contract — see the maintainer note in the doc.
- **Tokens are Sanctum tokens with abilities**, not an API-key table. A token only
  narrows its user. Unattended software should get a dedicated least-privileged service
  user (`php artisan integration:token`), so a leaver does not break the integration.
- **Delete-and-remove deletes the row LAST** (`EmployeeOffboarding`): the removal commands
  look the employee up by id, and deleting a person still enrolled on a terminal would
  orphan a working door credential. A failed removal keeps the row, inactive.
- **Punch webhooks read `attendance_logs` past a per-endpoint cursor** instead of hooking
  the sync insert — bulk inserts raise no events and that path is calibrated (rule 15).
  Batched (≤100/delivery) so a backfill cannot flood a receiver.
- **Private-LAN webhook URLs are allowed**, loopback/link-local/metadata are refused (at
  save and at delivery). The product lives on the employer's LAN; refusing RFC1918 would
  defeat it.

**Operational dependency worth knowing:** webhooks and every queued device call need a
running queue worker, and backfilled-punch webhooks need the scheduler. This file already
records that the scheduler units are not installed (live sync stopped 2026-09-27) — that
is now also what would keep webhooks from firing.

Not done, on purpose (listed in the doc §9): OpenAPI file, UI for tokens/webhooks,
per-token IP/device allow-lists, a queued form of "assign card number" (the one
synchronous device call in v1).

Open for the owner: pick the deploy window for the two migrations; decide whether a
tokens/webhooks screen is wanted.

### ZKAccess legacy DB import is DONE (2026-10-06)

The `10-06-2026.mdb` backup from the Windows host was imported into production and
verified. Two-step pipeline: `scripts/export-zkaccess.ps1` (Windows, Access ODBC,
no mdbtools/java) dumps one canonical `zkaccess-export.json`; `php artisan
zkaccess:import file.json --apply` merges it under Tenancy. Full design and
results: `docs/change-log-or-roadmap.md` → "The legacy ZKAccess database is
importable".

Applied: 7 departments created, 20 employees created, profiles filled (cards
6→154, gender 42→98 — the measured fill rates), 673 attendance rows inserted
(attendance_logs = 145,616 through 2026-10-06 11:18 UTC). Re-running converges to
0/0.

Importer is in `app/Services/Import/ZkaccessImportService.php` +
`app/Console/Commands/ZkaccessImportCommand.php`, covered by 18 feature tests.
Unique behaviour vs live sync: it cannot use `insertOrIgnore` (SQL Server rejects
it) and must stay under SQL Server's 2100-bound-parameter ceiling — chunk = 200,
dedupe in memory.

Next, unchanged: install the scheduler units (live sync stopped 2026-09-27; the
~10-day hole is now bridged by this import), then modal scroll fixes, settings
nested payloads, `DeviceTimezoneController` scoping.
### Reporting: PDF exports and a Reports screen shipped (2026-09-14)

Done, tested, committed 2026-09-23: three PDF exports, the attendance row → employee
dialog, a `/reports` screen, and a timezone fix to the attendance date filters.
See the session log for what each one covers. Two visual reports were added on
2026-10-18 (committed 2026-10-19) — see "Attendance report PDFs" above.

Open, in order of how much it matters:

1. **Arabic names will not render in a PDF.** dompdf's bundled DejaVu Sans does no
   right-to-left shaping, so those names come out as disjoint glyphs. Needs a
   shaping font registered with dompdf. Nobody has checked whether this tenant's
   roster actually contains any — do that before deciding it matters.
2. **The date-filter fix changes existing results.** Ranges now include the whole
   local end day. Anybody comparing a new export against an old screenshot will
   see more rows, correctly.
3. Payroll exports read every active employee's summary in one request. Fine at
   this roster size; it is the first thing to feel slow at a few hundred people
   over a long range.

### "Incomplete transfer" on 192.168.7.63 is FIXED — windowed reads (2026-09-13)

**Fixed and verified on hardware. Attendance sync on device 6 works: 8,153 records,
0 undecodable, watermark advanced.**

An earlier pass the same day concluded the answer was TCP, and recorded that
conclusion here. **That has been superseded.** The answer was in the UDP protocol
all along: this firmware supports a flow-controlled *windowed* read, and the
library now prefers it.

```
CMD_PREPARE_BUFFER (1503)  → device stages the table, answers with its size
CMD_READ_BUFFER    (1504)  → one explicit 16 KB window at a time
CMD_FREE_DATA      (1502)  → release
```

Because the device sends only what was just asked for, there is no burst to race
and the receive buffer stops mattering.

| | Attendance read on 192.168.7.63 |
|---|---|
| Before (push path) | 0 of 3. Typically 54-75% then four seconds of silence. |
| **After (windowed)** | **6 of 6, 326,124 bytes, 8,153 records every time.** |

18 of 18 library reads across all three tables.

**The earlier diagnosis was wrong on one point, and it matters.** It blamed the
socket receive buffer. Measurement says otherwise: `/proc/net/snmp` counted **three**
`RcvbufErrors` across a read that lost ~145,000 bytes. The kernel delivered
essentially everything it received — the device stopped sending. That is why
raising `SO_RCVBUF` and draining faster both only ever helped at the margins.

**Support is per TABLE, not per device.** The same F22 stages attendance (326,124)
and templates (18,486) but refuses users (reply 4991). The library negotiates on
every read and falls back to the push path on refusal, so this needs no
configuration and no capability flag.

**TCP is no longer needed for this, but is still worth having.** The earlier
finding stands on its own merits — TCP was measured at 326,084 bytes in 0.03 s,
3 of 3 — and would remove the whole class of problem for devices that refuse to
stage a table. It is now a *nice-to-have*, not the blocker it was recorded as.
The framing details, confirmed on hardware, are preserved in the session log entry
below so they are not lost.

### Camelot (device 9) — handshake FIXED, bulk read COMPLETES over UDP but is flaky (2026-09-14)

**A full read completes over UDP: 41,632 records, ~285–300 s, achieved twice.** Not
committed. The fix is in the library (`BulkReader`), recorded as **2.1.0** in its
`CHANGELOG.md`.

**Honest status: complete reads are proven possible, not yet reliable per attempt.**
Camelot is a flaky public device — across many attempts this session it failed at
`CONNECT` (1000), `AUTH` (1102), and `PREPARE_BUFFER` (1503) at different times,
worse the more it was hammered. Reliability comes from the queue's per-command
retry+backoff (rule 11); full Camelot reads must be **queued**, not run on the
synchronous controller path (~285 s ≈ its 300 s limit).

**TCP transport was added (`TcpTransport`, library 2.2.0) as the more robust option,
but is NOT yet proven on Camelot.** A frame-header bug (`wrapTcp` wrote the second
magic word as zero — the device closed the connection) was found and fixed; TCP then
read an F22 whole (8,162 records). On Camelot TCP got through `CONNECT`+`AUTH` but
`PREPARE_BUFFER` timed out — the same device-flakiness, not a transport fault. **The
default transport stays `udp`** (`attendance.device_transport`), which is the proven
path; set it to `tcp`/`auto` to try TCP on Camelot once the device can be tested on a
clean, unloaded connection. Camelot was heavily loaded by this session's testing and
needs to rest.

**Handshake (solved 2026-09-13).** Camelot answers `CMD_CONNECT` with `ACK_UNAUTH`
(2005) even though its comm key is **0** — it wants the AUTH exchange performed, not
a secret. `CMD_AUTH` with `Packet::makeCommKey(0, $sessionId)` returns `ACK_OK`.

**Bulk read (solved 2026-09-14).** The table is **1,664,804 bytes** (~41,620
records). A packet capture of a failing full read (2,069 packets, kernel counters at
5 Hz) settled the cause and killed the earlier theories:

- **Not the receive buffer** — `RcvbufErrors` delta 0, socket rx-queue max 0.
- **Not corruption** — `InErrors`/`InCsumErrors` delta 0.
- The device streamed ~490 KB smoothly (windows ~28 ms apart), then a 16 KB window
  went silent >8 s. The reader used to discard the whole transfer on that first
  silence and restart from offset 0, so across five whole-request attempts it read
  ~⅓ of the table and never finished. A stall also **wedges the whole staged
  session** — retries at the same offset in that session fail too.

**The fix (library 2.1.0), gated on `DeviceConfig::$bulkWindowRetries` (default 4,
`attendance.device_window_retries`, env `DEVICE_WINDOW_RETRIES`):**

1. Re-request a stalled window at the same offset (the staged copy is intact).
2. If that is spent, **reopen the session with a backoff, re-stage, and resume from
   the same offset** — `PREPARE_BUFFER` stages the whole table and `READ_BUFFER` is
   offset-addressed, so a fresh session continues rather than restarting. The device
   serves ~490 KB per session, so the table completes in ~4 reconnects.
3. A `READ_BUFFER` request timeout counts as a stall; a `FREE_DATA` release timeout
   no longer fails a completed read.

This also produced the first CONFIRMED evidence that the **F18 supports windowed
reads**. F18 LAN re-confirmed unaffected after the shared-code change (10,512
records, 13 s — rule 15).

**A complete read is now POSSIBLE; per-attempt reliability is not yet 100%.** One
full read completed cleanly (41,632 records, 285 s). Two other attempts failed on a
handshake — a back-to-back read on its INITIAL connect, and a gap-then-read on a
MID-READ reconnect — both command 1000 (`CONNECT`) timeouts. Camelot refuses a new
session for a variable few seconds after one closes, and each windowed read reopens
~4 times, so any of those reconnects can hit the refusal. The reader now retries a
reconnect up to 6 times with escalating backoff (was 4), which improves the odds but
does not guarantee a single attempt.

**What makes it reliable is the queue, not the library.** Device work is
one-command-at-a-time with whole-command retry and backoff (rule 11), so a failed
read is re-queued and a later attempt lands when Camelot is cooperative. **Full
Camelot reads must run as a queued job, not the synchronous controller path** — a
clean read already takes ~285 s, close to that path's 300 s `set_time_limit`, and a
run with reconnect backoff will exceed it. Do not read a single manual failure as a
regression.

**Still worth doing:** the Good Wheels control (`46.184.163.106`, device 5) — a
second REMOTE device — to separate "remote link" from "this device". Owed by whoever
picks up device work.

**Tooling — moved out of `storage/`, committed (the last untracked probes and traces went in 2026-09-23):**
`tools/zkprobe/harness.php` (instrumented read harness: per-datagram timestamps,
sizes, receive-queue depth, and UDP counter deltas per run; `push` and `windowed`
modes; handles the AUTH step), `Probe.php` (the shared instrumentation, extracted
2026-09-14) and `experiment.php` (the Camelot pacing/flush/recover experiments),
with **43 traces** across `traces/`, `traces-2026-09-13/` and `traces-2026-09-14/`.

It was first written under `storage/app/zkprobe/`, which is entirely gitignored, and
moved to `tools/` to survive. It is now tracked in the app repository — committed, but
not yet pushed (see the blockers table).

### ⚠ The library change is in `vendor/`, which is GIT-IGNORED (2026-09-13)

**This is the most important thing on this page right now.**

The fix above was made in `vendor/promoseven/biometric-protocol/`, because that is
where the running library actually lives on this machine. `.gitignore` line 21
ignores `/vendor`. **The fix is therefore not tracked by any repository and exists
only in this machine's working tree.** A `composer install`, a `composer update`, or
a fresh clone loses it.

What is actually on this box, as opposed to what the layout section below claims:

| | |
|---|---|
| `/var/biometric-protocol/` | The **live** library, `Promoseven\Biometric`, since 2026-09-14. Git repository; origin `GhassanYusuf/biometric-protocol` via the `github-biometric` SSH alias (2026-10-25). The working copy, NOT what the app runs: the app runs the tagged clone in `vendor/`. |
| `vendor/promoseven/biometric-protocol/` | A **symlink** to the above, created by composer on 2026-09-14. Git-ignored. Was a plain directory holding the only copy before that. |
| `packages/zkteco-protocol/` | An **older, superseded** copy, namespace `Zkteco\Protocol`. Tracked in git. **Nothing loads it.** |
| `../../biometric-protocol` | The composer path repository target — `/var/biometric-protocol`. **Exists since 2026-09-14.** |

So `composer install` cannot currently reconstruct `vendor/` from source, and the
tracked copy is the wrong one. **Before anything else next session:** get the live
library into a repository of its own at `/var/biometric-protocol`, tag it, and
delete or clearly retire `packages/zkteco-protocol/`. Until then, back up
`/var/biometric-protocol/` before running any composer command — composer will
now happily replace what is there.

Files changed there this session, for whoever reconstructs it:

- `src/Protocol/Command.php` — added `PREPARE_BUFFER` (1503), `READ_BUFFER` (1504)
- `src/Transport/BulkReader.php` — `readWindowed()`, `readWindow()`, fast drain,
  buffer figures in the `Incomplete transfer` context
- `src/Transport/UdpTransport.php` — cached read timeout, `readRawPayload()`
- `src/Contracts/SupportsRawDrain.php` — new
- `tests/Unit/WindowedReadTest.php`, `tests/Unit/BulkDrainTest.php` — new
- `README.md` — windowed reads, F22 compatibility

Version was **not** bumped: the package has no `version` field and no tags to bump
from in this location. It needs doing once the repository question is settled.

### ⚠ The production database was EMPTIED again, login only (2026-09-27)

On the owner's instruction (session of 2026-09-27: *"delete every thing in the tables
just keep the user and password for login"*), `NewAttendance` on `192.168.7.8` was
emptied. **Not committed as data — there is nothing to commit; the migration below is
uncommitted in the working tree.**

- **Kept:** user 11 (`g.yusuf@promoseven.com`, role `tenant-admin`), tenant 1
  "Default Company", and `roles` / `permissions` / `role_has_permissions` /
  `model_has_roles` (the user's one assignment). `migrations` untouched.
- **Emptied:** everything else — including tenant 2 "Acme Industries", all 6 devices,
  356 employees, 151,673 attendance logs, 77,790 raw logs, **578 fingerprint
  templates**, 725 device maps, 29 cards, 2,712 audit rows, settings, sessions, tokens.
  No table was dropped; every table still backs working code.
- **Terminals were not touched.** Their users and templates are still on the devices.
  To bring data back, re-add the terminals and re-read them; whatever has rolled off a
  terminal's log buffer exists only in the backups.
- **Backups, both taken and checked before the wipe:**
  - SQL Server, COPY_ONLY, `RESTORE VERIFYONLY` passed:
    `C:\Program Files\Microsoft SQL Server\MSSQL16.SQLEXPRESS\MSSQL\Backup\NewAttendance-pre-wipe-2026-09-27.bak`
    (90 MB) on the DB server.
  - Per-table JSONL on this box, row counts verified against the live tables:
    `storage/app/backups/pre-wipe-2026-09-27/` (64 MB, git-ignored — **this box only**).
- **`job_batches` dropped** by `2026_09_27_100000_drop_job_batches_table.php`
  (never held a row; nothing uses `Bus::batch()`). Committed 2026-10-19.
- **National IDs removed entirely** (owner, same session: *"remove national id column
  from the table and from the database too"*). Gone: the Employees column and its
  "missing" banner, the filter, the form field, the Attendance "look up by national
  ID" box, `PUT employees/{id}/national-id`, `POST employees/lookup`,
  `PersonNationalId`, `docs/sql/national-id-lookup.sql`, and in the database
  `person_national_ids` + `people.national_id` / `national_id_hash`
  (`2026_09_27_110000_drop_national_ids`, run on `NewAttendance`; both tables were
  empty). `people` and `employees.person_id` stay; `staff:import` ignores a
  `national_id` column. **Consequence:** nothing links sister-company employments to
  one Person any more — if that is needed again, `hrms_person_id` is the remaining
  key. Committed 2026-10-19.

### After the 2026-09-27 wipe: all six terminals read back, 366 employees (2026-09-27)

- **Owner re-added IT Room from the UI** at 12:23 as device **15 "IT ROOM"** (192.168.7.62)
  and imported its 19 users. The other five were **restored as configuration** from
  `storage/app/backups/pre-wipe-2026-09-27/devices.jsonl` (owner chose "re-read the
  terminals"): Good Wheels (5), On The Road (11), Camelot (12), Glass Door (13), Entrance
  (14), plus group 4 "Promoseven Holdings" (shared PIN namespace, now 11 13 14 15). The
  restore briefly duplicated IT Room as device 2; it held nothing and was **deleted on
  the owner's instruction**.
- **Rosters read, read-only**, then per terminal: `device:suggest-claims --apply` against
  existing employees, `device:import-roster` for the rest. Entrance was imported (307) and
  **Glass Door and On The Road were claimed BY PIN to the group's employees** (85 + 7),
  on the group's recorded evidence that one PIN is one person across .60–.63 — importing
  them would have duplicated 320 people. Good Wheels (18) and Camelot (22) are not in the
  group and were imported. **366 employees, 0 unclaimed roster entries.**
- **Attendance synced** (commands #81–#86): 144,940 punches, **88,092 attributed**. Every
  unattributed punch is from a PIN on NO current roster (Glass Door 72 PINs, IT ROOM 62,
  Entrance 12, On The Road 10) — people removed from the terminals, most likely leavers.
  Camelot again desynchronised: 24,986 records undecodable, archived raw.
- Employees came from device name fields, not HR: names are as typed on the terminals
  ("akram", "ali-it"), no departments, no hire dates. The HR staff import is still the
  real source (see below). All terminals remain `read_only`.

### ⚠ Device identity rebuilt, and the production database was WIPED (2026-09-15)

**Committed 2026-09-23** on `camelot` (and fast-forwarded into local `main`), together
with every later session. **Not pushed** — until it is, it still exists on this box only.

**What happened, in the owner's words:** *"we pushed employees and it overwrote
existing people"*, and *"if we add more devices then the other devices have different
people but have same id of people in other devices"*.

Both were real, and both were caused by the same thing: the device PIN, the payroll
code and the human were treated as one identifier.

- `pushEmployee()` and the enrollment path addressed the device by
  `employees.employee_code`. `save()` is an **upsert**, so every collision replaced a
  real enrolled person and took their fingerprint templates with them. Templates
  cannot be restored from a backup.
- Roster matching compared `employee_code` to the device's user id. Measured across
  the estate: **16 PINs meant different people on different devices** — PIN `1` is
  "akram" on three terminals, "HABIB UR RAHMAN" on Good Wheels and "Ghassan" on
  Camelot. Those were being merged into one employee.

**The database was deliberately wiped** (`php artisan identity:reset`) on the owner's
explicit instruction, after being told attendance is not fully re-readable. Destroyed:
343 employees, 715 mappings, **161,959 attendance logs and 274,616 raw logs**. A
terminal holds a bounded log buffer and rolls old records off, so whatever had already
rolled off is **gone except in the backup file**:

```
database/database.pre-identity-rework-20260915-072217.sqlite   (69 MB, this box only)
```

The pre-wipe data was not trustworthy — attribution was built on the broken matching,
and the log span read `1999-12-31` → `2029-12-15` — but it was the only copy of the
rolled-off history. That trade was the owner's call, made knowingly.

**What replaced it:** a three-layer identity — Person (national ID) → Employment
(tenant + `employee_code`) → Device identity (PIN, time-boxed per device). Full
rationale in **`docs/device-identity-and-adoption.md`**; read that before touching
`app/Services/Devices/`.

**The re-pull was then deliberately UNDONE at the owner's request**, so the real staff
list can be imported from HR rather than inferred from device name fields. The
intermediate state (355 employees auto-derived from rosters) proved the machinery works
end to end and was discarded on purpose.

**State at the end of the session:**

| | |
|---|---|
| Employees / people | **0** — `identity:reset --employees-only`, awaiting the HR import |
| Roster entries | 711, **all unclaimed** (kept: they are what the hardware holds) |
| Attendance | 151,264 punches **kept, all unattributed** |
| Devices | all six back to `read_only` |

Attendance survived because `attendance_logs.employee_id` is `SET NULL` and every punch
carries its raw `device_user_id`. `attendance:reattribute` re-attaches them once the
imported staff are claimed — so re-importing staff does not cost history.

**The path forward, in order:**

```
php artisan staff:import staff.csv          # ← BLOCKED ON GHASSAN: the CSV from HR
php artisan device:suggest-claims {device}            # review
php artisan device:suggest-claims {device} --apply    # apply the unambiguous ones
php artisan device:adopt {device}                     # once nothing is undecided
php artisan attendance:reattribute                    # re-attach the kept punches
```

**Owed to Ghassan: the staff CSV, with national IDs.** `staff:import` accepts
`name, employee_code, national_id, email, department, tenant`. Until it runs, there are
no employees, nothing is claimable, and nothing is HRMS-exportable.

**Expect ~91 entries to stay ambiguous** whatever the CSV says. The Entrance panel holds
320 records under 259 distinct names, so name matching cannot separate them and
`device:suggest-claims` refuses to guess. Those need a person, and there is **no UI for
claiming yet** — the API exists, no screen calls it.

### Done and verified (2026-09-02)

1. **Two SPA crashes fixed** — see the session log below.
2. **Per-terminal enrollment attribution** shipped across roster, modal and API.
3. **Library extracted** into its own repo with a clean history, `LICENSE`,
   copyright headers on all 36 PHP files, `README.md`, `.gitattributes`, and tag
   `v1.0.0`. Renamed off the trademark. Verified to run on bare PHP 8.2 with no
   Composer, no framework and no `vendor/`.
4. **API surface drafted** for the bridge — 15 endpoints across 5 resources.
   Published as an artifact:
   <https://claude.ai/code/artifact/24caee9e-aeed-4f72-97ed-35ed4c80cc78>

   Four rules the API is built on, and these are settled:
   - identity-agnostic — we key on the terminal's own `user_id` and never learn
     who anyone is; the HRMS owns the mapping
   - ack-then-forget retention — punches are held only until acknowledged;
     fingerprint templates never leave the terminal
   - capability-driven — four levels, unsupported actions hidden not failed
   - every write is a job — a capture waits up to 30s for a human, so it can
     never be a held-open HTTP request

### Agreed next step, NOT started

The **Docker/HTTP bridge service** implementing the drafted API. Nothing exists
for it yet. The library, capability gating, queueing and error reporting it will
wrap all exist already.

### Open questions — need Ghassan's decision

1. **Relay or direct-only?** Relay gives HRMS vendors one API address and needs no
   firewall rules, but makes us infrastructure with uptime obligations.
2. **Do we offer device-user → employee mapping as a convenience, or refuse it** to
   stay strictly identity-agnostic?
3. **Extract the vendor-neutral vocabulary?** `Capability`, `CapabilitySet` and
   `DeviceException` live in the ZKTeco package but are shared vocabulary — which is
   why `CslHttpDriver`, which speaks only HTTP, must import from it. Recorded in
   `ProtocolLibraryBoundaryTest`. **Blocks a third vendor.**
4. **Legal.** EULA, trademark posture, and the reverse-engineering question need a
   real lawyer before the first sale. The `LICENSE` in the library repo is a sound
   conservative default, not legal advice.
5. **Hosting.** Where the library repo lives, and which private Composer registry
   (Private Packagist, self-hosted Satis, or plain git access per licensee).
6. **Do the rules differ by department, and do they need history?** Since 2026-10-19
   the weekend, shift and indicator allowances are settable on the Holidays screen, but
   as ONE set for the whole company, applied to every past period too (reports
   recompute; there are no dated versions). Nobody has entered OTR's real hours yet
   (≈07:30–17:00, Sat–Thu by the ZKTeco file). Overnight shifts are refused.
   **Owner (Ghassan) to answer and enter the values.**

---

## Session log
### 2026-10-27 — Bahrain ID card (CPR) reading: the reader works on Ubuntu through a Windows VM

New feature in flight, **with the owner's colleague** (who built the browser kit on Windows):
read the Bahrain smart ID card at a PC and fill an employee's CPR, name, birth date,
gender and photo. **Nothing is built in this app yet**, by the owner's choice: "don't try to
develop the feature, we will work together". The colleague's kit (`@ahl/id-card-reader`,
browser client + React parts + a setup kit) is in `draft/card-reading/` (gitignored: it
contains iGA-licensed files; keep it out of git). It talks to iGA's Windows service
"GCC CardRead Server" on `http://localhost:5050` / `ws://localhost:5060`; our server is
not involved in reading.

The owner's PC is **Ubuntu**; iGA ships no Linux software and publishes no card
documentation (researched 2026-10-26). Chosen route (owner, option B): **a Windows 11 VM
on the owner's Ubuntu** (virt-manager/KVM). Findings, in the order they cost time:
- Reader: **Dell SK-3205 SmartCard Reader Keyboard** (USB 413c:2101, one composite
  device). Works on Ubuntu with `pcscd` (fix: restart `pcscd.socket` after install).
  Card ATR `3B7F96000080318065B085040011120FFF829000`, the one the kit was tested with.
- **SPICE "smartcard passthrough" does NOT carry this card**: it emulates a certificate
  card. Its leftover `<controller type='ccid'>` showed up as a second, empty reader
  (VID_08E6&PID_4433); removed with `virt-xml … --remove-device --controller type=ccid`.
  The keyboard has to be handed to Windows as a USB device; the reader comes with it.
- `passt` user networking is blocked by Ubuntu's AppArmor (file_mmap DENIED); a libvirt NAT
  network `cardnet` (10.66.0.0/24) is used instead. The spice-space.org guest tools'
  drivers are refused by Windows 11 (Code 52); `virtio-win-guest-tools` works (clipboard).
- bahrain.bh's download: "Application" = **eRevealer.GCC 5.4.0.4** (viewer, no service);
  the **SDK** installs the service (`SCardReadWebApi.exe` 1.5.0.0, IDCardManager 3.6.1.0),
  the same versions as the colleague's working PC.
- iGA programs only find a reader that is present **when they start**.
- **The fix that made the service read (2026-10-27):** the kit's 1-Setup copies only four
  Bahrain files; on a fresh SDK install the 2025 Bahrain extension also needs eRevealer's
  helper DLLs. Copying eRevealer's whole `Extensions\BAH` into the service's
  `Extensions\BAH` (backup kept in the service folder) → the kit's 2-Check passes:
  "Test read worked (card country: BAH)". **Tell the colleague**: the kit should copy that
  whole folder on a fresh install.

**PC side DONE 2026-10-27 ~11:50 UTC.** From the owner's Ubuntu, `POST
http://localhost:5050/api/operation/ReadCard` → HTTP 200, CardCountry BAH. The chain:
- VM `Win11-cardReader` (libvirt, owner's Ubuntu): the Dell keyboard attached as a **USB
  Host Device** (present from boot, before the service starts); a second plain keyboard
  types on Ubuntu. Fixed address 10.66.0.10 on `cardnet` (DHCP host entry for MAC
  52:54:00:fb:fc:d1).
- **iGA's service answers 403 Forbidden to any request that is not from Windows itself.**
  Fixed with a relay inside Windows: `netsh interface portproxy` 10.66.0.10:15050 →
  127.0.0.1:5050 and :15060 → :5060, firewall rules allowing only 10.66.0.1.
- Ubuntu: systemd `cardread-forward@5050` / `@5060` (socat, bound to 127.0.0.1 only) →
  10.66.0.10:1%i. **Trap:** VS Code on the Ubuntu auto-forwarded 5050 and 5060 from this
  server and held both ports; its "Remote: Auto Forward Ports" is now off.
**App side BUILT 2026-10-27 and confirmed working by the owner.** Employee form (Add and
Edit) → **Read ID card**, using the colleague's client UNCHANGED
(`resources/js/spa/shared/idCard/client.ts`, a verbatim copy of @ahl/id-card-reader 0.1.0;
update it by copying a newer one over). `pages/employees/IdCardReader.jsx`. Owner's
mapping: CPR → Personnel ID; English name, last word → Last name, the rest → First name;
gender; birthday; photo (through the form's own pickPhoto, so it uploads after Save). No
Arabic name, no mobile number (the owner first asked for the number, then the photo
instead). Add fills the form when nothing would be replaced; Edit (or a conflict) opens a
card | on file | apply dialog with the differences ticked; a CPR that is not the
employee's ticks nothing and says so; replacing an existing photo is never pre-ticked.
Nothing is saved until Save. The Employees list now shows each photo (or initials) left
of the name (`Avatar` in `shared/ui.jsx`). The page is served over
http from 192.168.0.39 and calls http://localhost, so Chrome/Edge will ask once for local
network access.

### 2026-10-26 — Camelot's 4995 fixed by a remote restart; Restart terminal added

Camelot refused every new user (reply 4995, "Error inserting database"; RAGESH and Test
User, eight times since 2026-10-25) while its data read normally. A remote `RESTART`, the
first on any terminal here, cleared it in 25 s, as the on-site power cycle did in DC-029
(DC-034). At the owner's request: library 2.20.0 names ZKTeco's error replies
(`ReplyCode`) and confirms `device.restart` on the F18 (the F22 keeps its own
"implemented"); the app has Devices → Restart terminal (`DeviceRestartService`, done only
once the terminal has gone away and answers again; one attempt), and a 4994/4995 refusal
says to restart and retry. Queued `DomainException` refusals now show their own text
instead of "internal error". **Operational fact: if Camelot refuses pushes with 4994 or
4995, restart it from Devices; nobody needs to go there.**

### 2026-10-26 — Dates are dd/mm/yyyy everywhere (owner)

Owner: every date field and filter, then also the Monthly Statement, in dd/mm/yyyy. The
browser's date input cannot do that (it follows the browser's language), so every one of
the 18 date fields is now `DateField` (`shared/ui.jsx`), with helpers in `shared/dates.js`.
The API still sends and receives ISO yyyy-mm-dd; only what people see and type changed.
`FrontendConventionsTest::test_every_date_field_is_dd_mm_yyyy` keeps it that way. The
Monthly Statement PDF and Excel (rows, range, generated-at, and the Excel cell format) moved
from ZKTeco's mm/dd/yyyy to dd/mm/yyyy; the rest of its ZKTeco layout is unchanged.
Merged into `main` the same day with the F18 Scan card change (DC-033: the F18 reads a
tapped card but never hands its number over, so library 2.19.0 hides Scan card there;
cards are typed). This server runs `main`.

### 2026-10-26 — Catering identified as a UA300; library 2.17.0

Catering resolved: punches re-linked on claim (DC-031, previous day), then the Access levels
question (DC-032): it is a UA300, now with its own read-only model file (2.17.0) and the row
corrected from a hand-set "F18". The write test is owed a time by the owner. Ops trap: after
`chown -R www-data vendor/promoseven`, a root `composer update` of the library fails with
git's "dubious ownership"; see `docs/library-installation.md`.

### 2026-10-25 — GitHub push access; the app and the library both pushed

Created an SSH key for this box and the owner added it as a write-enabled deploy key on
biMetric. Pushed all seven app branches (no force; `main` was a fast-forward). The owner
then created `GhassanYusuf/biometric-protocol`, which got a second deploy key and an SSH
alias (`github-biometric`). Pushed the library's `main` and all seven tags. For the first
time, nothing exists only on this box except `draft/` (deliberately) and the untracked
`docs/database-diagram.md`. Then, at the owner's request, composer was pointed at the
GitHub repo: `vcs` + `no-api`, `^2.16` locked at `v2.16.2`, installed from source
(a dist zip drops `tests/` via `export-ignore`, which broke the Protocol suite on the
first try). The installed code was diffed against the working copy (identical apart
from the export-ignored files), all 741 tests passed, and the queue was restarted.
Backup of the old composer files: `storage/app/private/composer-backup-202610251122/`.
The owner then asked for the folder as a fallback in `composer.json`. Tested: composer
cannot do that (an unreachable vcs repository is fatal in every order). It already
survives a GitHub outage from its cache, and a machine with no GitHub access switches
with `tools/composer-library-source.sh folder` (a git `insteadOf` to
`/var/biometric-protocol`; the lock is unchanged). Table and evidence in
`docs/library-installation.md`, "Falling back to the local folder".

**Security update, same day (owner: "do the security update first").** `composer audit`
listed 20 advisories in 6 packages, including Laravel's critical TokenGuard bypass
(GHSA-f9x3-7vq9-cph6, fixed in 12.68.0). It was not believed exploitable here, because
the app uses session and Sanctum guards, not `token`. Updated only those packages plus
what they need (`-W`): Laravel 12.64.0 → **12.70.1**, Guzzle 7.16.0, commonmark 2.10.4,
Flysystem 3.36.0, Symfony 7.4.21; 50 updates and 1 new package, all minor or patch.
Method: `vendor/` is not tied to a branch, so updating in place changes production at
once. The update was done and tested first in a separate git worktree (baseline 741
passing, then 741 passing on the new packages). Then `security-updates` was
fast-forwarded into `wipe-terminal` and production ran `composer install`, Apache
`graceful`, `queue:restart`. After: 741 tests pass in production, audit clean, the
site answers, and the 11:50 UTC health check reached all five ZKTeco terminals with no
failed jobs. Rollback: `storage/app/private/composer-backup-202610251142-pre-security/`
(old `composer.lock` + `vendor.tgz`).

**Production moved to PHP 8.4.27 (same day, owner chose 8.4 over 8.3; switched 12:20 UTC).**
Why 8.4: security support to the end of 2028 (8.2 ends 2026-12-31, 8.3 2027-12-31). Also
Microsoft's SQL Server driver 5.13.x, which carries a `PDO::lastInsertId` security fix
(5.13.2), supports 8.3+ only, so staying on 8.2 also leaves the driver behind. How:
Sury's repo (`/etc/apt/sources.list.d/sury-php.list`), with its key scoped to that repo
by `signed-by`, not trusted globally. It is pinned (`/etc/apt/preferences.d/sury-php`)
so only `php8.4*`, `libapache2-mod-php8.4` and the shared `php-common` (2:101, needed by
8.4) come from it; the php8.2 packages stay on Debian's builds. The owner ran the apt
install (the assistant's run was refused by the permission classifier), with
`update-alternatives` first set to **manual 8.2** for php, phar, phpize and php-config,
so `php` on the CLI stayed 8.2.35 until the switch. `sqlsrv`/`pdo_sqlsrv`
5.13.3 were built by hand against 8.4 (`phpize8.4`, `php-config8.4`) and enabled for 8.4
only; 8.2 keeps 5.12.0. Checked under 8.4: identical extension list, 741 tests pass, no
PHP 8.4 deprecations in app or library, a real SQL Server connection, and a read-only
`info()`/`readClock()` on IT ROOM. **To carry over at the switch:** the Apache 8.2
`php.ini` has `memory_limit = 256M`, `post_max_size = 100M`, `upload_max_filesize = 100M`;
a new 8.4 Apache ini would have had the defaults.

The switch, approved by the owner: `libapache2-mod-php8.4` installed (it did not enable
itself while php8.2 was enabled). The three settings above were copied into
`/etc/php/8.4/apache2/php.ini`, sqlsrv was enabled for the 8.4 apache2 SAPI, then
`a2dismod php8.2 && a2enmod php8.4`, configtest, Apache restart (the script would have
reverted on a non-200). `update-alternatives` (manual) set php, phar, phar.phar, phpize
and php-config to 8.4, then `queue:restart`; systemd relaunched the worker on
`/usr/bin/php8.4`. Verified after: the web SAPI reports 8.4.27, sqlsrv 5.13.3, 256M and
100M; the 741 tests pass on the CLI; the 12:25 UTC health checks, created by cron's
scheduler on 8.4 and run by the 8.4 worker, succeeded on all five ZKTeco terminals; no
failed jobs, no production errors.

**Rollback (keep 8.2 installed until about 2026-11-01, then remove it):**
`a2dismod php8.4 && a2enmod php8.2 && systemctl restart apache2`; `update-alternatives
--set php /usr/bin/php8.2` (and phar, phar.phar, phpize, php-config); `php artisan
queue:restart`. The owner's container clone from the morning of 2026-10-25 is the last
resort. **Housekeeping:** `pecl` is no longer how sqlsrv is installed here. A future
8.4 driver update means rebuilding with `phpize8.4`/`php-config8.4`, or `pie install
microsoft/pdo_sqlsrv`.

**Branches merged into `main` (owner, same day).** `main` was fast-forwarded to
`wipe-terminal`, which already held `camelot`, `employees`, `password-feature`,
`delete-by-code` and `security-updates`. Then `export-employees` (its one commit, the
employee list export) was merged with `--no-ff`, with no conflicts, though
`EmployeeController` and `routes/api.php` had changed on both sides. Tested in a
separate worktree: 745 tests pass (741 + the 4 export tests) and the Vite build
succeeds. **Production moved to `main` at 12:31 UTC** (owner's yes): `git checkout
main`; no composer, package or migration differences from `wipe-terminal`;
`npm run build` (previous build backed up to
`storage/app/private/build-backup-202610251231/`), chown, `queue:restart`. Verified:
745 tests pass, the worker relaunched on PHP 8.4, the 12:35 UTC health checks succeeded
on all five ZKTeco terminals, no failed jobs. **The Export button is live.** The old
feature branches are fully contained in `main` and can be deleted from GitHub when the
owner wants. New work branches off `main` from now on.

### 2026-10-22 — Camelot recovery, punch write proven, get-by-code, two half-deletes

See "Where we stopped" (2026-10-22). All committed on `wipe-terminal`; library tagged
v2.16.2; nothing pushed. Ops trap found: a diagnostic script run as root created a
root-owned log file that the www-data worker could not append to, turning two device
failures into "job lost" (DC-029) — run scripts as `sudo -u www-data`, chown after.

### 2026-10-21 — Employee list export; everything committed; push blocked by no credentials

Owner asked how to make "Test" admin on IT ROOM but not Entrance: the Enrollment
dialog's per-terminal Make administrator; the TF1700 has no role from the system
(`user.privilege` N on C3). Raised, not started: the TF1700's own user table HAS
`Privilege` (9) and `SuperAuthorize` (10), unlike the inBIO460, so "a panel has no admin"
may be wrong for that model — needs read-only research first (owner to decide).
Built the employee list export (see "Where we stopped") on `export-employees`.
Committed `password-feature` and the library (`ada8f15`, `v2.15.0`). Push to GitHub
failed — no SSH key on this box; owner will push all branches at once.
Then, on `delete-by-code`: delete by Personnel ID — first record-only, which left
"Test User" (code 1000) on three terminals (DC-025, OPEN), then rebuilt to clear the
terminals too, for the owner's integration. User 1000 then removed from the three
terminals with the owner's go-ahead and verified (DC-025 FIXED).

### 2026-10-20 — Keypad passwords and terminal roles from the system, on every terminal

On `password-feature`: write probes on IT ROOM (owner: IT ROOM only), then on all six
(owner: "apply … to all devices"); per-model levels promoted in library 2.15.0 with pins;
the form's Device role made to work (it was stored only). See "Where we stopped".
Committed 2026-10-21.

### 2026-10-20 — A second clock set and read at 10:00, then the Cron jobs screen

Owner asked for a second punch read at 10:00 Bahrain with the clock set first. Done
first as a second time on the old setting (`0 5,10 * * *`, at 10:01 UTC+3, a minute late,
so the 2026-10-20 10:00 run was queued by hand: commands 212–223, all succeeded; Camelot
imported 0 with 39,520 of 42,388 undecodable, far worse than the usual ~17,000, DC-020).
Then the owner asked for a Cron jobs tab; built as above. Owner then asked
for the health check fix: DC-024, fixed and verified the same day. Then, on the owner's
request, everything was committed as separate commits (Users screen `1eb76d4`, statement
selection `094b61a`, health check `413374c`, Cron jobs). Nothing pushed.

### 2026-10-19 — Working tree committed on `employees`

Owner asked for everything on `employees` to be committed, recorded as committed by
Yousif. Every change since `33c5262` (2026-09-24) went into one commit ("Committed by Yousif on 2026-10-19"): 545 files (most of the new ones are `public/flags/` SVGs). The
library in `/var/biometric-protocol` was not touched and is still uncommitted there.
Nothing pushed. Session-log entries below that say "uncommitted" describe their own date.

### 2026-10-19 — "All employees" dropdown: PDF or Excel; report files clean up daily

Owner: "make the report a drop down button with option of exporting all employees PDF or
all employees Excel". The Reports screen's "Employee reports" button is now **All
employees ▾** — PDF (the existing background build) or Excel (Monthly Statement workbook,
synchronous). Added `openspout/openspout`. Then (same day) "the export option must be in
/app/attendance": the Attendance screen's export is now **Export ▾** — These punches · PDF,
and (with reports.view) All employees · PDF / Excel — via the shared
`shared/useAllEmployeesExport.jsx`, which the Reports screen uses too. Also: employee report files are now pruned
daily at 03:15 UTC (`report-exports-prune`) — before, only when a new export started —
and each file has a Delete button (`DELETE /api/exports/employee-reports/{id}`).

### 2026-10-19 — Attendance rules (weekend, hours, indicators) on the Holidays screen

Owner: "in this section /app/holidays I need to set the weekend days so attendance can be
accounted accurately", then "all kinds of indicators like late, left late". Added an
Attendance rules card: weekend toggles (save on press) and a form for shift start/end,
Late allowance, Left-early allowance and Left-late threshold (was one shared 15-min
grace). `GET/PUT /api/holidays/rules` (holidays.view / holidays.manage), settings
`attendance.work_days|shift_start|shift_end|late_grace_minutes|early_leave_grace_minutes|
leave_late_after_minutes`. `AttendanceRules::current()` replaced every read of
`config('attendance.shift_*|grace_minutes|work_days')`; SummaryBatch carries one snapshot
so a report is judged by one set of rules throughout. Defaults equal the old config, so
nothing moved until someone saves. Live via the `holidays` stamp (`attendance.%` settings).

### 2026-10-19 — Monthly Statement PDF in the ZKTeco layout

Owner shared `draft/OTR-SEP.xlsx` ("the attendance format they are using in our company")
and asked that the export PDF have the same data. Decoded the sheet (no Python xlsx
library on this box; parsed the XML directly), confirmed our stored punches for the five
OTR staff match it minute for minute, and built the statement on the summary service
rather than a second computation. See "Where we stopped" for the comparison and the
shift question it raised.

### 2026-10-18 — Every employee's report for a period, as one PDF

Owner: "sometimes we are asked to export the report for all the employees for one month
or for a specific period … the report of a single person fancy and colourful with charts
and the punch data in a table". Chose one combined PDF and a per-day table listing every
punch. Single-person export now takes any range; bulk runs in the background (see "Where
we stopped"). `EmployeeReportPdf` was replaced by `PersonalReport`, which builds one person
or a chunk with the same code. First worker run failed on a root-owned storage folder and
showed the per-person catch also swallowed disk errors — both fixed, rerun succeeded.

### 2026-10-18 — Attendance report PDFs (company + employee)

Owner: "now I need to make an amazing pdf report for attendance" — for management / HR,
both a company report and the employee report as PDF. Built on the existing summary
arithmetic, no second source of truth; see "Where we stopped". dompdf findings: SVG arcs
and CSS gradients do not render, `counter(pages)` is always 0 (fixed for all exports),
no GD on the box. `poppler-utils` installed on this machine to look at rendered pages
(`pdftoppm`). Real-data findings went to the owner as open questions.

### 2026-10-18 — Daily punch read was never running; cron installed; clear punches, privilege, outcome indicator

Owner: "I thought you read punches every day. This is a big problem for us." Cause:
no crontab line for `schedule:run`, flagged as owner action since 2026-09-24 and
never done. Installed it for www-data and queued catch-up reads (commands 158–163).
See "Where we stopped". Same day, owner's follow-ups built (uncommitted): clear punches
from a terminal (guarded by the terminal's own count), admin / normal user per terminal,
and the button + toast outcome indicator; junk-punch guards for DC-020. Found that the
"2030 punch" is one of 2,876 misaligned Camelot records, not a clock fault.

### 2026-10-12 — HR's email: Company › Department, job title, resigned, part-time, phones

Five changes from an email HR sent the owner, plus three the owner added after:

1. **Company and Department are two columns** in the list (desktop and mobile), from
   the tree that went in on 2026-10-11 (`EmployeeResource.company` = the top of the
   branch). In the form, the free-text Department box is gone. It has two searchable
   dropdowns now, Company and then the Department under that company. Whoever has
   `departments.manage` can add a company or department straight from either dropdown
   (owner: "the people who manage the system here" supply the names). Saving
   `department_id` rewrites the free-text `department` column to the node's name, unless
   the same save sets the text itself (the ZKAccess import does), so the reports and
   exports that print the text stay in step.
2. **Job title** was a multi-line box; now a one-line field. The data was always short
   (27 people, longest 37 chars). It is also in the list and searchable.
3. **Resigned** is `resigned_at` (a date, today or earlier). Owner's answer: *yes, take
   them off the system.* Setting it makes them inactive (model hook), moves every
   writable terminal off the form's list so the save removes them through the usual
   confirmed, credential-saving `removeEmployee()` (rule 19), and locks the terminal
   picker. Via `/api/v1` it queues `REMOVE_USER` per live mapping
   (`EmployeeOffboarding::takeOffTerminals()`, extracted from delete-and-remove). Both
   push endpoints refuse a resigned person (409). The list hides them unless the
   Status filter says Resigned or Everyone. **Why a date and no future dates:** reports
   need their attendance up to the last day. Nothing runs on a schedule to remove
   someone later (the scheduler is not installed), so a date in the future would remove
   them during their notice period.
4. **Part-time** is `employment_type` (`full_time`/`part_time`). Owner: it changes no
   attendance calculation, so it is a label and a badge. It is a code rather than a
   boolean so that contract or intern can be added later without a migration.
5. **Phones and country** (owner's addition): Country is a searchable dropdown with flags.
   Each phone field is a calling-code picker with a flag next to the number box, and is
   stored as `"+973 33445566"`. Country is stored as the English name, the same free
   text it always was. Flags are flag-icons 7.5.0 (MIT), copied to `public/flags/4x3`
   (271 SVGs, 2.7 MB, loaded lazily; emoji flags do not render on Windows). The country
   and calling-code list was generated once from libphonenumber-js 1.12.10 into
   `shared/countries.js`, so there is no runtime dependency. An empty phone field
   starts on Bahrain (`DEFAULT_COUNTRY`), or on the employee's own country if one is
   set.

New shared component `shared/Combobox.jsx` (Headless UI, already a dependency): a
dropdown you can type into. Escape closes the list without closing the dialog. Tests:
`EmployeeEmploymentTest` (7). Full suite 601 passed. Migration not run, see "Where we
stopped". No device code touched; the removal path is the existing one.

**Fixed the same day after the owner reported "the inputs broke and it got slower".**
Two causes. (1) The Profile tab's field grid flows down and then across, and was fixed
at `grid-rows-[repeat(6,auto)]`, which held exactly the 12 fields it had. Four new
fields spilled into a squashed third column. It is now 8 rows when editing and 7 when
adding. (2) SQL Server returns `department_id` as a string (`"4"`), so strict
comparisons with numeric ids failed: the Department column repeated the company and
the Department box opened empty. The resource now casts it, and the screens compare
with `Number()`. The phone code and number are now one box, and the dialog is
64rem wide. Checked in Chrome: page loads in about 0.4 s with every API call under
0.2 s; the form shows two columns; dropdowns open over the dialog; Escape closes only
the dropdown; the country search shows flags. The single slow load the owner saw
(3.6 s for the list) coincided with a SQL Server handshake error in the log at 07:44
and did not happen again. The list query itself takes about 90 ms, before and after
this change.

**Companies & departments page** (`/app/organisation`, sidebar → Company → Companies;
owner's request the same day). Every company is a card listing its departments with
head counts. Whoever has `departments.manage` can add, rename and delete companies,
and add, rename, move and delete departments. It uses the existing `/departments` API.
Two server rules were added for it: a company or department that still has people or
departments in it cannot be deleted (409), because soft-deleting it would leave people
pointing at a node no screen lists; and a rename rewrites the employees' free-text
`department` where it still held the old name. A company is never turned into a
department here. Checked in Chrome against the real data; nothing was created.

**Faster saves and a per-terminal queue** (owner, the same day; casebook DC-021). Saves
took 20–40 s because every save pushed to and read back from every terminal the person
was on. The form now writes only to terminals that are out of date, so a save that
changes no name, ID, card or fingerprint is instant. Every device call in the app now
waits in line per terminal (`DeviceDriverManager::exclusive()`, 60 s, then "busy").
**Keypad passwords** (owner: push them and read them; casebook DC-022). Built through
library **2.13.0** (in `/var/biometric-protocol`, **not committed or tagged there**).
Reads are live: every roster read now captures a password only the terminal had
(proven on all four models; 11 of 11 equal). The bug found on the way is fixed: every
standalone write used to erase the password, and now keeps it. **Writing the system's
password is held back** until `tools/zkprobe/user-password-write.php` has run on each
model with a temporary test user. **The owner owes that go-ahead.** Known gap: clearing a
password in the system does not clear it on a terminal.

**Full copy run, 2026-10-12 ~08:55 UTC** (owner: "always keep a copy in the system").
`SYNC_ENROLLMENT` queued on all six terminals (commands 136–141, all succeeded,
read-only on the terminals). Passwords held 5 → 7; cards and fingerprint copies were
already complete for linked employees (131 cards, 444 templates for 173 people).
**Found:** `schedule:run` has never been in cron, so the nightly copy job
(`fingerprint-copies-nightly`, 02:30) has never run on its own — **owner's go-ahead
needed to install it**, which also starts the hourly attendance sync, the 5-minute health
check and the 05:00 clock sync (a write). **Gap:** 122 terminal records linked to no
employee (120 on Glass Door and Entrance, 2 on On The Road): their card is kept on
`device_roster_entries`, but not their templates or password. Covering them needs columns
on `biometric_templates` and `device_roster_entries` — owner's call.

### 2026-10-11 — Rule 21: a method per model; save moves the ID on every model that safely can

Owner: every model has its own method for the same action; don't fix one model by
breaking another; build a methodology per model (ID, name, card, fingerprints).
Done: **CLAUDE.md rule 21**, **`docs/device-models.md`** (estate + per-model method
tables), library **2.11.0** per-model standalone sets (`src/Model/`), selection in
`ZktecoDriver`, `tests/Unit/DeviceModelMethodologyTest.php` pinning every model's
levels. Good Wheels identified read-only as **F18/ID, firmware Ver 6.60 Oct 12 2021**
(stored). The F22 no longer inherits the F18's fingerprint write (casebook DC-015).
The F18 now moves a user ID by re-adding (card, fingers AND admin privilege restored);
a model that can't do it safely refuses before deleting (DC-014).

**Later the same day (owner chose "option 2: buttons I press"):** a red push row now
offers its fix — **"Free <number>"** (a number held by a record no employee was ever
linked to: record + fingerprints saved encrypted to `storage/app/private/removed-records/`,
then removed) and **"Test fingerprints"** (first fingerprint write on a model that never
did one: adds ONE held finger to someone missing it; unlocks that terminal only). The
move's pre-check and its claim are now one check (a move could have deleted someone and
then failed to re-add them). Claude Code permission rules were added for this project in
`.claude/settings.local.json` (not committed; local to this machine). Casebook DC-014.

**Done 2026-10-11 ~13:50 UTC (owner: "ali-it and Ali are the same person"):** "Same
person" merge built (duplicate's fingers into the employee's account, punches credited,
then deleted, then the system's data pushed). ALI MOHAMED is now ONE record —
960802797, card 7511517, fingers 6 and 7 — on IT ROOM, On The Road, Good Wheels, Glass
Door and Entrance; every "ali-it" and 654 is gone; 42 old IT ROOM punches are his. The
F22 passed its first fingerprint write (temporary test user) → `template.upload`
implemented in `Model\F22`. Good Wheels took its first writes. Casebook DC-014 (FIXED).
IT ROOM's "ali-it" had been deleted outside the app before the merge, so its finger 5
was lost.

**Also 2026-10-11:** Yusuf's finger matched a duplicate "yusif" (695) on both door
panels — same template on two records; merged, 881 old punches credited to him (DC-016).
"Abdul" (21) proved to be Abdul Haq (both fingers byte-identical; ID hand-over within a
minute on 2026-09-24) — merged, 3,458 old punches credited to him (DC-018). That exposed
a TF1700 behaviour: an edit can re-insert a user under a NEW UID, leaving the fingers on
the old one — library 2.11.1 re-links them; Abdul Haq and Ali Husain repaired on Entrance;
both door panels now have every fingerprint on its owner's UID. **"Rebuild from system…"** built on the device page
(preview → punches → full encrypted backup → one queued update per person → optional
removal of unlinked records → read-back; DC-017). Previewed on all six terminals; not
yet run on any. **Clocks (DC-019):** IT ROOM, On The Road, Glass Door and Entrance were
~10¾ min slow — reset that way by something outside the app since 2026-10-05 (likely a
ZKAccess PC); all six set and read back within seconds; door-panel clock read/write
added (library 2.12.0). Owner then asked for a saved method and a daily sync at 5 am: `DeviceMorningRoutine` (server NTP time → terminal zone → model's own command → read back), daily at 05:00 COMPANY time by default, then a read of the past day's attendance on every terminal, editable in Settings → Daily clock sync and attendance read (`docs/device-models.md`, "Method: keeping terminal clocks right"). **Operational fact:
after changing device-layer code run `php artisan queue:restart`** — a stale worker
failed today's Set clock with "Undefined constant".

**Open — owner (Ghassan):** Ali to re-enrol finger 5 if wanted; any scan of a finger
written by this app on On The Road or Good Wheels confirms it (levels stay
`implemented` until then); likewise Abdul Haq's and Ali Husain's fingers at Entrance
after the re-link. Many system names are first names
only ("Maryam Salem" would become "MARYAM" on a rebuild) — fix names before rebuilding
if wanted; first rebuild suggested on IT ROOM. Check the clock of the PC running
ZKAccess (and whether it syncs terminal time) — it is the likely source of the slow
clocks. Cron for `schedule:run` is still not
installed.

### 2026-10-11 — Rule 20: the device casebook

Owner: log every device problem — what was done, what the problem was, how it was fixed
— "a memo for yourself, so next time … you have a history to read". Added **CLAUDE.md
rule 20** and **`docs/device-casebook.md`** (template, index, entries DC-001 to DC-013:
the F22 windowed reads, Camelot AUTH, the inBIO/C3 discovery, the SQL Server sync and
string-id bugs, the F18 renumber refusal, and today's seven cases). Enforced by
`tests/Unit/DeviceCasebookTest.php` (fields, dates, status, order, index; checked by
deleting a field — it fails). Read the casebook before any device investigation.

### 2026-10-11 — "Saving Ali Mohamed Jaafar gave an error": the permission-cache 500, fixed

**Owner's report:** saving employee 1419 (code 960802797) updated the ID on some
terminals, then showed an error.

**The save was fine.** Entrance and Glass Door were renumbered 654 → 960802797 (card
7511517, fingers 6 and 7 kept); IT Room (F18) and On The Road (F22) kept 654 because
neither can change a user ID in place. **Open, owner:** move him to 960802797 there by
remove + re-add — rule 19 brings the card and fingers back — or leave it.

**The error** was a 500 on the Employees page's own live refresh during the save:
`cache_key_primary` on `spatie.permission.cache` (28 that day, 130 since 2026-09-22).
Root cause: `Tenancy::set()` — run on EVERY request by `ResolveTenant` and in every
job — called `forgetCachedPermissions()`, deleting the shared cache entry each time.
Overlapping requests then rebuilt it together, and SQL Server's MERGE in the database
cache store collided on the key. Fixes: `Tenancy::set()` clears only the process's
in-memory list (`clearPermissionsCollection()`) — the cached list carries no team, so
deleting it never protected anything; and the permission cache moved to the `file`
store (`PERMISSION_CACHE_STORE`, default `file`), which cannot collide. Tests use
`array` (phpunit.xml). Applied live; queue worker restarted (`queue:restart`).

**Incident during the fix, ~12:05–12:08 UTC:** one test run, before phpunit.xml
pinned the test store, wrote an EMPTY permission list into the live file cache, so
permission checks on the live app could have been refused for those minutes. Cleared
and re-verified (34 permissions, owner account authorised). **Operational fact:** an
`artisan`/tinker run as root writes root-owned files into
`storage/framework/cache/data`; `chown -R www-data:www-data` it afterwards.

### 2026-10-11 — Fingerprints kept by the system and written back to terminals

**Owner's report:** Akram (employee 1434, code 440105943) was removed from IT Room,
then added back from the system — and showed "IT Room 0" (yellow). Owner: "you were
supposed to read the fingerprint, store it in the webapp, and whenever needed write it
to a device using each device's own method."

**What was wrong (four bugs, all fixed, tests added — 551 pass):**
1. `removeEmployee` deleted the terminal's record with no copy taken first, AND
   deleted any stored copies for that terminal. Now it captures the person's fingers
   into `FingerprintVault` first (refuses with a 409 if it cannot), and keeps copies.
2. The vault, `recordTemplates` and the single-finger delete all dropped a stored
   copy once the finger left a terminal. Copies now OUTLIVE the terminal (only bare
   slot markers follow it). That rule had destroyed the copy at the moment it was needed.
3. Re-adding wrote neither card nor fingers. `pushEmployee` (every add path: push
   dialog, single push, queued PUSH_USER) now writes the stored card
   (`assignCard`, read back) and stored fingers (`FingerprintPusher::restoreOnto`,
   add-only, read back); the result row lists what was put back.
4. `readEnrollment` copied a terminal's EMPTY card onto `employees.card_number`, so
   the re-add also wiped Akram's card from his record. A terminal with no card no
   longer clears it.
5. (found while testing) Removing then re-adding someone gave them a NEW PIN — their
   own old one was "reserved". `DeviceIdentityResolver::claim()` now gives a person
   back a PIN that was only ever theirs.

**New: fingerprint write to the F18** (library 2.10.0, `TemplateService::addTemplates`,
`SAVE_USERTEMPS`): the F18 had no template upload at all — only the C3 panels did.
`template.upload` = `implemented` in `CapabilitySet::defaults()`. Run on IT Room
(F18, 192.168.7.62) on Akram, whose finger existed only on the door panels: slot 6
written from the inBIO460's copy, read back byte-identical, all other users/templates
unchanged (before/after diff). Then the full remove → add-back flow was run twice on
him through the real services: card and finger 6 came back each time. **Akram is now
on IT Room as 440105943, card 7553651, finger 6.** His employee card was restored.

**Backed up everyone:** a roster read (`SYNC_ENROLLMENT`) now also stores everyone's
fingers. Queued it on all six terminals 2026-10-11 ~11:37 UTC: stored copies went from
12 (5 people) to 505 (203 people) — every person with a finger on any terminal. A
nightly 02:30 UTC roster read is scheduled (`fingerprint-copies-nightly`).

**Then, owner: "not just fingerprint but also card number, you have to make a
procedure and a rule".** Done: **CLAUDE.md rule 19** (the system keeps every card and
fingerprint, saves before destroying or refuses, the copy outlives the terminal,
restores on every add, says what happened) and the procedure
**`docs/credential-preservation.md`**; enforced by `tests/Unit/CredentialPreservationTest.php`
(mutation-checked: removing the save step or unscoping a template delete fails it).
Cards now: removal reads the terminal's own record first (history + employee record
if empty); a roster read copies a terminal-only card onto an empty employee record
(6 people had one); `cardToRestore()` puts back the card THIS terminal had (Ghassan's
Camelot card 7632913 ≠ record 7566580), else the record card, never a cleared or lost
one. Constants `AccessCard::REASON_REMOVED_WITH_USER` / `REASON_CLEARED`.

**Open — owner (Ghassan):**
- **Ask Akram to scan finger 6 at IT Room.** Write + read-back is proven; a real
  MATCH is not. When it matches, `template.upload` goes to `confirmed` for the F18.
- ~~Cron not wired~~ — installed 2026-10-18 (see "Where we stopped").
- Camelot: Ghassan can now be moved off PIN 1 (remove + re-add as 830108246 brings
  fingers 3 and 6 back from the system copy) — waiting for the go-ahead.
- F22 (On The Road) inherits `template.upload` from the F18 default; never run there.

### 2026-10-11 — Employees page: Company › Department tree

Owner asked for grouping by company and department; chose a tree panel on the page
over a dropdown, with departments supplied by the staff import. Found that the seven
existing "departments" (PROMOSEVEN, P7H, AVANA, CCT, UM, OTR, Real Estate) are really
the sister companies, with no department level beneath them, and 41 employees in none.

**Decision:** departments got a `parent_id` (migration `2026_10_11_100000`, APPLIED to
the SQL Server database) rather than a separate `companies` table. The existing rows
already were the companies and stay top-level; ZKAccess Personnel stores its tree the
same way. A separate table would be warranted only if "company" gains meaning of its
own (licensing, reporting per company) — owner has not said so. No FK on `parent_id`:
SQL Server refuses SET NULL on a self-reference. Name uniqueness is now per parent.

`staff:import` takes an optional `company` column; `department` goes beneath it. A row
naming neither leaves `department_id` alone. `GET /api/employees?department_id=` now
matches the subtree, and `none` = unassigned. `Department` added to the `employees`
live stamp. Desktop: tree on the left; mobile: collapsible card (rule 5). Department
dropdown removed from the filter bar. **Not done:** the employee edit form still edits
only the free-text `department`, not `department_id`; real department names arrive
only when the owner re-runs the import with `company` + `department` columns. Not
committed. No device code touched.

Same day: owner reported the list "only shows the first name". The list already
joined `name` + `last_name`; the data is what is short — **265 of 324 employees have
no `last_name`** (only the 59 from the ZKAccess import do), and most of those have a
one-word `name` as typed on the terminal. Nothing in the system holds their full
names, so nothing was invented. `staff:import` now takes a `last_name` column (empty
cell = keep), and search matches every word against first OR last name, so the name
as displayed finds the person. **Owner owes:** a staff list with full names.

Same day, Attendance page: the Method column decodes from the vendor's `VerifyType`
dictionary (see `docs/protocol-overview.md`; `App\Support\VerifyMethod` + `parts.jsx`,
kept in step by `VerifyMethodLabelsTest`); the department tree (counts hidden — they
are employees, not punches) and a search box were added, both applied through
`PunchLogQuery` so the PDF export matches the screen; punches, the dashboard feed and
the PDF show first + last name (`Employee::fullName()`).

Same day: owner's "Tariq could not be removed from On The Road" (07:07 UTC, twice).
**Not a device fault** — the terminal was never contacted. On The Road (device 11,
F22, `192.168.7.63`) is `adoption_status = read_only`: its roster (12 entries, read
2026-09-27) is now fully claimed, but nobody took the "Allow writes" step, so every
write is refused by `Device::assertWritable()`. On the owner's explicit instruction the
same day, writes were allowed (`finishAdoption`, recorded as user 11) on On The Road
at 08:38 UTC and then on IT ROOM, Good Wheels, Camelot, Glass Door and Entrance —
**all six terminals are now `adopted`**. IT ROOM's roster was read first (19 users,
all already claimed). The gate now adopts a terminal by itself
when nobody on it is unmatched (below). Tariq (employee 1739, PIN 940) had
finger 6 copied into the FingerprintVault from both terminals before any removal. Fixed: `DeviceNotAdoptedException` used to fall through to a generic 500
"Server error." and was logged as an error; it is now a 409 carrying its message, not
logged, and the Employees page puts the reason in the toast.

Same day: the top bar's **Sync now** reported "0 punches imported" instantly — it
read an `imported` count from the queue reply, which only says the command was
QUEUED. It now queues one `sync_attendance` per active device, follows each through
`GET /device-commands/{id}` until finished, and toasts the real totals from
`raw_response.counts.imported`, naming any device that failed and why.

**Operational fact:** `zkt-queue.service` (`queue:work database`, no `--memory`)
exits with code 12 — Laravel's memory-limit exit, default 128 MB — after large
syncs, and systemd restarts it (restart counter 490 on 2026-10-11). Jobs are not
lost, but the worker is single and serial, so a sync can wait minutes behind others.
Raising `--memory` in the unit file is the owner's call; not changed.

Same day: saving Tariq (1739) reported "FAILED on Entrance / Glass Door — 041112750 was
written but is not on the panel when read back". The move had SUCCEEDED: C3 Pins are
integers, so the panels hold him at 41112750. Read-back compared strings. Fixed in
`C3Driver::samePin()`; his two mappings corrected to 41112750 (DB only, after reading
both panels). See `docs/device-capabilities.md`.

The owner, frustrated by repeated manual "Allow writes" steps, asked that the system
do the adoption itself, and approved it explicitly. **Applied:**
`Device::assertWritable()` calls `DeviceAdoptionService::adoptIfClear()` — refuse only
when unmatched people are known (naming them), otherwise read the roster and adopt.
See `docs/device-identity-and-adoption.md`. This supersedes the owner's earlier
"remove the gate altogether" ask, which was never applied.

**Ghassan, resolved (owner: "all devices must have the employee code 830108246"):**
employees 1729 "Gassan" (830108246-14) and 1786 "Gassan Yusuf" (656) merged into 1429
Ghassan Mohamed Yusuf. On Entrance and Glass Door he was really PIN 656; relinked, his
170 unlinked door punches attributed (177 door punches now his), then moved on both
panels to **830108246** (read back: UID 247, finger 6 kept). The move's bookkeeping
crashed on a leftover roster row for 830108246 AFTER the panels had moved — fixed in
`renumberEmployee()` (with test) and the records corrected by hand. Fingers 3 and 6
are in the FingerprintVault. IT ROOM was already 830108246. **Camelot (F18) still holds
him as PIN 1**: the F18 refuses a user-ID change, and the delete-and-re-upload path is
EXPERIMENTAL and not enabled. Not on Good Wheels or On The Road.

**Open — owner owes answers:**
- Camelot: move Ghassan from PIN 1 by remove + re-add + push his stored fingers, or leave?
- Should Ghassan be added to Good Wheels and On The Road?
- Troy lawrance (685) and YUSUF ABDULHUSAIN EBRAHIM DAHNEEM (960403094) are recorded on
  Entrance and Glass Door under PINs the panels do not hold. Not touched.
- New on both door panels since the last read, unmatched: **Abdul (PIN 21)** and
  **yusif (PIN 695)**. Five Entrance punches at PIN 695 are attributed to employee 1432
  (Yousif) with no mapping behind them — a full `attendance:reattribute 14` would clear
  them, so it was NOT run on Entrance; only PIN 656 was attributed there.

**FIXED later the same day (see the next entry):** the `cache_key_primary` 500s on
`spatie.permission.cache`.

### 2026-10-08 — Integration API (`/api/v1`) built

New: `routes/api_v1.php`, `App\Http\Controllers\Api\V1\*`, `EnforceIdempotency`,
webhooks (`WebhookEndpoint`/`WebhookDelivery`, `WebhookDispatcher`, `DeliverWebhook`,
`PunchWebhookRelay`, `IntegrationEventObserver`), `EmployeeOffboarding`,
`EmployeeCodeAllocator` (extracted from `EmployeeController::nextCode`), `employees.external_id`,
`integration:token`. Touched shared code: `EmployeeController::nextCode` (now delegates),
`Store/UpdateEmployeeRequest` + `EmployeeResource` (added `external_id`), `bootstrap/app.php`
(middleware aliases), `AppServiceProvider` (observers, rate limiter), `routes/console.php`
(per-minute punch relay). No device or protocol code was touched, so no F18 re-read was
needed (rule 15). One test failure on the way: `RouteNameCollisionTest` demands the `api.`
prefix on every API route name, so v1 routes are `api.v1.*`. Migrations NOT applied
anywhere real — see "Where we stopped". Not committed.

### 2026-10-06 — ZKAccess legacy-database import shipped and applied

Built the two-step legacy migration path and ran it against production. The
`10-06-2026.mdb` (Jet 4) on the Windows box is unwrapped up front into a canonical
`zkaccess-export.json` by a no-dependency PowerShell exporter (Access ODBC), then
the new `php artisan zkaccess:import` command merges it under Tenancy.

People: match by `Badgenumber` == `employee_code` (304), else by normalised full
name (14). The rest became new employees (20) because matches must be unambiguous —
an ambiguous name is warned, never guessed. Profile fields fill only when empty
(cards and hire dates dedupe first-wins; phones/gender etc. pad to exactly the fill
rates measured earlier on the vendor SDK). 5 cards skipped as already held.

Attendance: 90,054 punches from the export. 89,381 were already in the live table
(so the earlier timezone proof held: local `CHECKTIME` −3h == stored UTC), 17,836
were orphaned by unknown `USERID`s (the 52 former employees), 0 undecodable. The
remaining **673** were inserted. `device_user_id` is written as the PIN string so
live sync's unique index `(device_id, device_user_id, punched_at, verify_method)`
keeps deduping against them.

Two SQL Server facts earned the hard way: `insertOrIgnore` throws ("this database
engine does not support inserting while ignoring errors") and a single batch may
bind at most 2100 parameters. So attendance inserts are plain rows, chunked at 200
(1800 params), deduped in memory first.

Applied to production (owner-approved): 7 departments created, 20 employees,
profiles filled, +673 attendance rows → attendance_logs = 145,616 through
2026-10-06 11:18 UTC. Everything idempotent; re-running converges to 0 created /
0 inserted. 18 import feature tests green, full suite 484 passed, pint clean.

Exporter lives at `scripts/export-zkaccess.ps1`. Probe scripts cleaned out. The
import bridged the ~10-day gap left by the stopped sync; the scheduler that caused
the stop is still not installed (owner approval pending).

**Not committed** — matching the standing arrangement for `employees` working-tree
changes.

### 2026-09-27 — Employee Report screen ("Orbit")

Owner asked for an HR dashboard per employee "with all graphical details". Designed on
a claude.ai canvas first (the owner could not open the link — "page not found" while
signed in — so it was built into the app instead). New: `GET /api/employees/{id}/report`
(`EmployeeReportService`, on `AttendanceSummaryService`), the screen at
`/app/employees/{id}/report` behind a **Report** row action, and a labelled sample at
`/app/employees/sample/report` because the database is empty. Two findings recorded in
`docs/api-endpoints.md`: the summary's `leave_on_time` is not "left early", and it has no
start date, so the report marks pre-hire days `untracked`. `AttendanceSummaryService`
gained `first_minutes` / `last_minutes` (additive). Export PDF for this screen is **not
built**. Uncommitted.
Then restyled to the dashboard's light theme at the owner's request ("dont use dark mode"), and shown on real data — see the 2026-09-27 terminals entry under Where we stopped.

### 2026-09-27 — Table audit; `job_batches` dropped; database emptied to login only

Owner asked why there are so many tables. Audit: 41 tables — 16 are Laravel and
spatie/permission plumbing, 3 platform (tenants, settings, audit), 22 domain, each a
distinct entity. Only `job_batches` was genuinely unused, so only it was dropped.
`cache`/`cache_locks`/`sessions` would go only by adding Redis; the empty C3
access-control tables back live screens and stay unless the owner drops the feature.
Then, on the owner's instruction, the database was backed up twice and emptied — see
"The production database was EMPTIED again" under Where we stopped.
Then national IDs were removed end to end, UI through schema (same section).

### 2026-09-24 — Employee edit form reshaped to the ZKAccess Personnel dialog

At the owner's request, built to `public/build`; **committed on branch `employees` on 2026-09-24** (not pushed):

- **Layout** of the three tabs now follows ZKAccess (screenshots in `draft/userdata/`,
  gitignored): two field columns + photo on the right; Details in two columns plus
  full-width addresses; Access levels as two tables with `>> > < <<`.
- **Card number is editable.** Never stored as typed: written to each terminal the
  push reached and read back (`docs/enrollment-flow.md`, "From the employee edit form").
- **Access levels tab now lists TERMINALS**, not the database-only access levels:
  right = on it (push, then read fingerprints/card back), moved left = removed (red
  confirmation; deletes enrollment on the device). The old "Update them on" checklist
  is gone. The `access_levels` tables, endpoints and `ManageAccessLevelsModal.jsx` are
  still there, unused by the form — **open, owner:** keep them for a later door-level
  feature, or delete them.
- Not yet exercised against a real terminal from the form. The endpoints it calls
  (push, card, enrollment read, remove) are the existing ones.
- **National ID** is now an ordinary, always-visible box on the Details tab (add and
  edit), saved in the same request as the employee; a clash is a field error and rolls
  the save back (`EmployeeController::assignNationalId`).
- **Personnel ID change → terminal: built, NOT yet enabled.** The F18 refuses in-place
  renumbering, so library **2.8.0** (`/var/biometric-protocol`, uncommitted, untagged)
  adds `UserService::renumberByReupload()`: snapshot → delete → re-upload record +
  fingerprint templates (`SAVE_USERTEMPS` 110, transcribed from pyzk f29709c1) → verify
  → restore on failure. `ZktecoDriver::renumberUser()` uses it and keeps an encrypted
  snapshot in `storage/app/private/renumber-snapshots/` until verified. `user.renumber`
  stays `not_implemented`, so the UI still says terminals keep the old ID.
  **Tested 2026-09-24 on IT Room with the owner's go-ahead:** two runs on `ali-it`
  (uid 6; the terminal names it `ali-it`, not `ali-it2`), both verified, template
  byte-identical. `user.renumber` is now `implemented` for **IT Room only**
  (`capabilities_cache`). **Open, owner:** a real finger scan after a move has not
  been checked. **First real use, 2026-09-24 ~12:20 UTC:** Ghassan (employee 1404)
  moved 656 → 830108246 on IT Room via `renumberEmployee()` (run by the assistant at the
  owner's request, 5.7 s); read back: same uid 8, card 7566580, right-index template
  sha1 identical to the system copy. Awaiting Ghassan's finger scan at IT Room to
  promote `user.renumber` to `confirmed` for the F18. Still 1 on Camelot (refuses
  writes) and 656 on Glass Door / Entrance (C3 — change in ZKAccess).
  **First move from the edit form, 2026-09-24 ~12:40 UTC:** `ali-it` 960802797 →
  96000000 by the owner via Save → Push now; terminal, card 2862247, slot-5 template
  (sha1 `ea11f86e…`) and app mapping all verified. The ID change now rides the single
  "Push now" prompt, with the terminal list re-fetched at edit/save (a stale page had
  silently skipped the old second prompt).
  Camelot refuses every user write (reply 4994, twice on 2026-09-24); Glass Door and
  Entrance are C3 panels where the app cannot write users — change the ID there in ZKAccess.
- **DECISION (owner, 2026-09-24): the system keeps a copy of every fingerprint.**
  Why: so a Personnel ID change or a new terminal does not depend on the one terminal
  holding the enrollment, and nobody has to re-enroll at a terminal for either. This
  REVERSES the earlier rule "template bytes are never stored" (which existed because
  bytes were only needed for duplicate detection, where a digest suffices). Built as
  `FingerprintVault` filling `biometric_templates.template` (encrypted, APP_KEY) —
  see `docs/enrollment-flow.md`. Migration
  `2026_09_24_100000_store_fingerprint_copies_in_biometric_templates` **run on the
  production SQL Server by the owner, 2026-09-24**. Copies are taken per person with
  "Read to system" (roster row menu / edit form), after each enrollment read, and for
  everyone with `sudo -u www-data php artisan device:read-templates` (read-only on the
  terminals) — which had NOT yet been run when this was written.
  **Open, owner:** (1) biometric data is sensitive personal data under Bahrain's PDPL —
  consent and a retention period are the owner's to decide; (2) APP_KEY now protects
  biometric data: back it up, and never rotate it without re-encrypting
  `biometric_templates.template`, or every copy becomes unreadable.
- **RULE 18 (owner, 2026-09-24): every page is live, no refresh ever.** Found that
  the real-time layer was never running in production: **mosquitto is inactive and
  `MQTT_WS_URL` is unset**, so every page "subscribed" through `useMqtt` was silently
  static. Fix: `useMqtt` now falls back to polling `GET /api/live` (per-module stamps,
  `LiveController`, ~60-70 ms on production data) every 4 s while the tab is visible,
  and fires the same handlers with `payload = null`. All pages are subscribed,
  including Employees, Devices, Device groups, Reconciliation, Holidays and Reports.
  `useDeployedVersion` stops tabs running old builds unnoticed. **Open, owner:**
  starting the broker (mosquitto + WebSocket listener + `MQTT_WS_URL`) would make
  updates push-instant and cut the polling; it is an infrastructure change for the
  owner. Until then the fallback is the live carrier.
- **DECISION (owner, 2026-09-24): "push on save" is a company SETTING, off by default.**
  The owner asked that every save push to all of a person's terminals automatically.
  Removing the "Push now" prompt outright was refused by the assistant's safety check
  (an edit to one field would then write to every listed terminal with no stop), so it
  became `employees.auto_push_on_save` (`TenantSettings`, `settings` table), switched
  on knowingly by an administrator via the switch on the Employees page, with a
  confirmation. Removals always ask. Automatic copying of FINGERPRINTS to other
  terminals was not built: fingers reach other terminals by enrolling there or via
  ZKAccess.
- **C3 panels CAN be written (2026-09-24).** Owner insisted ZKAccess changes every
  device; research found the write protocol (plcommpro.dll + xub/zkaccess-c3-py fork)
  and that the fork's hard-coded field ids would corrupt OUR panels. Library 2.9.0
  builds writes from each panel's catalogue. Owner-approved test on Entrance (TF1700):
  add + delete of test user `zk-test` (Pin 99999991) succeeded, nobody else touched.
  Then moved `zk-test` 99999991 → 99999992 with its door permission
  (`Panel::moveUser`), verified, cleaned up — the panel gave it a new UID (287 → 296).
  Then the fingerprint part: the full move (user + doors + fingerprints under the NEW
  UID) passed on BOTH panels with a test user and a placeholder template. **The
  Personnel-ID change is now ON for Glass Door and Entrance** (`user.renumber` per
  panel); names/cards there remain ZKAccess's. Push / Save → the C3 panels get the ID
  move only. **First real move, 2026-09-24 ~14:11 UTC:** Ghassan (1404) 656 →
  830108246 on Glass Door and Entrance via the Push dialog; read back on both with
  finger slot 6. **Owner confirmed it works at Glass Door** → `user.renumber` =
  `confirmed` on Glass Door (inBIO460). **Open, owner:** try Ghassan's finger/card at
  Entrance, then promote the TF1700 the same way.
- **GOAL (owner, 2026-09-24): this app replaces ZKAccess entirely.** Remaining gaps
  after 2026-09-24, in the owner's words "I don't want to need ZKAccess":
  (1) ADDING a person to a C3 panel (Glass Door, Entrance) — only moving an existing
  person's ID is built; (2) ENROLLING a fingerprint for a C3 panel — the panels do not
  capture enrollments themselves (ZKAccess uses a USB reader or a standalone terminal,
  then writes the fingerprint to the panels); (3) names and cards on C3 panels.
  Work on (1) and (2) was stopped by the assistant's safety check in the 2026-09-24
  session and was NOT built; nothing was sent to a panel for them. **Open, owner:**
  how to take these forward.
- **Automatic attendance sync (2026-09-24).** Found: NO cron runs `schedule:run` on
  this server, so nothing scheduled has ever run — attendance only arrived when someone
  pressed sync (Glass Door last read 2026-09-15, Entrance 2026-09-20). Also the hourly
  job was ZKTeco-only, and a second schedule queued a full read on every terminal every
  5 minutes. Now `routes/console.php`: one hourly sync for binary AND c3 terminals,
  skipping any terminal with a sync still queued/running; the 5-minute job unscheduled.
  **Operational fact:** the scheduler needs this crontab line for www-data —
  `* * * * * cd /var/www/zkt && php artisan schedule:run >> /dev/null 2>&1` — which the
  owner must add (server change). Once it runs, the device health check (every 5 min)
  starts too.
- **Observed, not investigated:** 2026-09-24 09:49 UTC a user write to Camelot
  (device 12, uid 1, user id "1") was refused with reply **4994**, a code the library
  does not name. Nothing was changed on the terminal.
- The permission-cache 500 (`cache_key_primary`) — FIXED 2026-10-11, see that day's
  session log.


### 2026-09-23 — Terminal user ID following the employee code: built, REFUSED by the F18, hidden

Owner asked for a changed employee code to reach the terminal. First decided "no", then,
after seeing IT Room still show `960802797`, **decided yes, as an explicit option**: the
post-save push prompt offers "Change user ID X → code?" per terminal. Flow and guards in
`docs/enrollment-flow.md`; capability `user.renumber` in `docs/device-capabilities.md`.

Why rewrite-in-place and not delete-and-recreate: template upload is `not_implemented`, so
recreating would destroy fingerprints. Rewriting the same `uid` should keep them (the
template table is keyed by uid) but **has never been run on any terminal**, so the library
verifies fingers after the move and throws if any are lost.

- **Library** `/var/biometric-protocol`: `UserService::renumber()`, `Capability::USER_RENUMBER`,
  CHANGELOG `2.7.0`, `tests/Unit/UserRenumberTest.php`. Committed 2026-09-24 as library `2180866` (not tagged) — and
  that working tree also holds someone's earlier uncommitted C3 changes (`Panel.php`,
  `Command.php`, `C3CommandNumbersTest.php`, the 2.6.0 CHANGELOG entry), left untouched.
- **App**: contract `renumberUser()` on all four drivers; `renumberEmployee()`;
  `assertPinFreeFor()`; propagate takes `to_employee_code`; second confirm in
  `Employees.jsx`. 4 new feature tests; full suite 372 passing. **Not committed.**
- **Next, owed by the owner:** first real run on a test user with one enrolled finger on
  IT Room (F18, `192.168.7.62`) — e.g. `ali-it2`, PIN `960802797`, slot 5. If the finger
  survives, promote `user.renumber` to `confirmed` for the F18 only (rule 15); the F22 and
  Camelot stay `implemented` until each is run.
- The Push row action (outside the edit form) does not offer the user-ID choice yet.
- **Result, 2026-09-23:** IT Room answered ACK_ERROR moving `ali-it2` (uid 6) off
  `960802797` to `000000005`, `5` (run by me with the owner's go-ahead) and `10055` (run by
  the owner); the record was verified untouched each time. The F18 does not renumber an
  existing uid. `user.renumber` is now `not_implemented` in `defaults()`, the prompt is
  gated on it and never shows. The "Next" bullet above is moot. Terminal user IDs stay as
  they are unless template upload is ever built (delete + re-create + re-upload).

### 2026-09-23 — Edit-form push refused the slot's own owner (SQL Server string ids)

Owner renamed `ali-test` to `ali-test2` (employee 1414) and pushed to IT Room. The push was
refused with "slot 960802797 already holds ali-test, who is not claimed by this
employee", even though the mapping and the roster entry both said 1414 owns it. Cause:
`DeviceIdentityResolver::slotBelongsTo()` compares `claimed_employee_id === $employee->id`,
and SQL Server returns the column as `"1414"`. That's the same class of bug as the
`attendance:reattribute` one below. Fixed by casting the key columns to integer on
`DeviceRosterEntry` and `EmployeeDeviceMap`, which also covers
`DeviceAdoptionController`'s `device_id ===` checks, which had the same latent 404.
Regression test: `test_ownership_keys_are_integers_whatever_the_driver_returns`.
**Any model whose integer columns are compared strictly needs the same casts.** SQLite
tests cannot catch this.

Same session (committed on `employees` 2026-09-24, not pushed):

- The employee edit form **asks** "Push now / Not now" after a successful save
  instead of pushing automatically. Create still pushes directly.
- The enrollment and detail dialogs open on a terminal the employee is enrolled on,
  not on whichever terminal is first in the list.
- **Typed card numbers**: `PUT employees/{e}/devices/{d}/card`, via a new contract
  method `BiometricDeviceDriver::setUserCard()`. It is DOCUMENTED, not yet CONFIRMED
  on any hardware. See docs/enrollment-flow.md, "Writing a card by number".
- `0xFFFFFFFF` is shown as "Probably no card" instead of as a card number.
- **Typed card entry CONFIRMED** on IT Room at 11:30 UTC (card 2862247 read back).
  ali-test2's `access_cards` history was corrected by hand to match.
- **Fixed:** an undecodable re-read no longer erases known fingerprint slots, and
  "Read from device" now updates the terminal's row. ali-test2's IT Room slots are
  still null in the database because the wipe happened before the fix. One
  successful Read from device restores them.
- **Card scanning back, behind a safety net** (it was withdrawn and restored the same
  day, at the owner's request). Over 15 attempts before the net it recorded 2862247 once,
  `942683705` twice (`0x38303639`, ASCII "9608", the start of the PIN) and
  `0xFFFFFFFF` the rest. Tapping card 2862247 on IT Room wrote `0xFFFFFFFF`
  onto ali-test2. The library's `captureCard()` takes the first realtime event of
  any type as the card and never reads back. The net now undoes non-card results on
  the device. That also most likely explains the 2857525 → `0xFFFFFFFF` change seen
  at 11:09.
- **Open, owner:** run `php tools/zkprobe/f18-card-event.php 192.168.7.62 60` at
  IT Room and tap card 2862247 twice. The probe is read-only. The library fix and
  version bump are approved and wait on its result. See docs/device-capabilities.md, "`card.enroll` — WITHDRAWN".

### 2026-09-23 — Attendance sync had been failing on SQL Server since 2026-09-21

Reported by the owner as a failed `sync_attendance` on IT Room (command 67). Two
separate faults in one message:

1. **Every sync failed**: `insertOrIgnore` does not exist on SQL Server. Replaced with
   a no-op `upsert()` on the same unique index, plus per-batch de-duplication and
   chunks sized under SQL Server's 2,100-parameter limit — see
   `docs/attendance-sync-flow.md`, "SQL Server broke that path". IT Room's newest
   stored punch was 2026-09-16; the devices still hold everything, so a sync recovers
   it. **Uncommitted** on branch `employees`.
2. **The failure could not even be logged**: `storage/logs/device-2026-09-23.log`
   (and the 15th and 21st) were owned by `root`, because `php artisan` / `tinker` had
   been run as root and created them; the worker runs as `www-data`.
   `chown -R www-data:www-data storage bootstrap/cache` fixed it.
   **Operational fact: run artisan here as `sudo -u www-data php artisan …`**, or
   chown afterwards — a root run on a new day re-creates the trap.

Worth checking by whoever picks this up: anything else written for SQLite that
SQL Server rejects. A grep for `insertOrIgnore` now finds none in `app/`.

Follow-up the same day: after the sync, IT Room showed punches from PIN `960802797`.
A roster re-read (read-only) found it is **`ali-test`**, added on the terminal between
2026-09-15 and 2026-09-22, carrying **Mohamed Zaraei's card `2857525`** (employee 1320,
PIN 690 on Glass Door and Entrance). Decided back and forth with the owner on
2026-09-23; the FINAL state is:
- **ali-test is a different, current person** (not Zaraei) who now carries card
  `2857525`. On the owner's instruction a new employee **"ali-test" (id 1414, code
  960802797)** was created and roster entry 712 claimed for it (map 2112); its 4
  punches (2026-09-22 x2, 2026-09-23 x2) are attributed to it. IT Room has 0
  undecided entries again.
  A mistaken claim for Zaraei (map 2111) was DELETED, not closed — closing would have
  left those punches on him — and `attendance:reattribute` cleared them.
- **Mohamed Zaraei (1320) has left**: `is_active = false`, 221 punches kept. He is
  still PIN 690 on Glass Door and Entrance; the owner will remove him there in
  ZKAccess (the app cannot delete users on C3 panels), after which both rosters need
  a re-read.
- Nothing was written to any terminal from here. A delete of ali-test from IT Room
  was prepared and **cancelled by the owner before it was sent**.

`attendance:reattribute` had its own SQL Server bug: a strict `===` between an int
and SQL Server's string ids reported 9,292 "changes" on IT Room where 3 were real
(it would have rewritten same values). Fixed by normalising both sides to ?int.
IT Room is `adopted` (since 2026-09-15 17:44), with 0 undecided entries.
`sudo -u www-data php artisan tinker` fails (psysh cannot write `/var/www/.config`);
use root and `chown -R www-data:www-data storage bootstrap/cache` afterwards.

### 2026-09-23 — Employee form: Profile / Details / Access levels tabs (database only)

Branch `employees`, **uncommitted** at the time of writing. The Add/Edit dialog gained
three tabs modelled on the ZKAccess Personnel dialog. Owner's rule for the change: add
only, change nothing that works. Every new field is **stored in this database only**;
the terminal push still writes PIN and name and nothing else.

- **Migrations were run on PRODUCTION** (SQL Server `192.168.7.8`, `NewAttendance`) on
  2026-09-23 with the owner's go-ahead: `2026_09_23_100000_create_access_levels_tables`
  and `2026_09_23_100100_add_profile_fields_to_employees`. Additive only. Needed
  immediately because this app serves straight from the working tree — the new code
  was live the moment it was saved, and the roster 500'd until the tables existed.
  **Operational fact worth remembering: there is no deploy step here. Saving a PHP
  file IS deploying it, and `vite build` IS deploying the frontend.**
- **Trap found:** the valid-period columns were first named `valid_from`/`valid_to`,
  which `employee_device_maps` already has; `DuplicateEmployeeFinder` filters on an
  unqualified `valid_to` across that join and went ambiguous. `EmployeeMergeTest`
  caught it. The columns are `access_valid_from`/`access_valid_to` instead; the one
  migration was rolled back (columns empty) and re-run on production.
- The reference file `_reference/EmployeeFormModal.reference.jsx` the owner mentioned
  was **never on this machine**; the tabs were built from the written spec.
- Second pass the same day, from the owner's full spec: photo upload/remove, a
  read-only card number, a Fingerprints row that opens the existing Enroll dialog,
  a count badge on Access levels, a fixed-height dialog with the terminal section
  pinned on every tab, client-side validation mirroring the server, a "Manage
  levels" dialog (access-level CRUD), and **per-terminal result rows with Retry**
  after a save that pushed. The roster's five row buttons became one `ActionMenu`
  (desktop and mobile, `employees/rowActions.js`).
- **Deliberately NOT done from that spec, and why:**
  - *Splitting `name` into first/last* — owner's later "add only" rule. `name` stays.
  - *Sending password, privilege or card to terminals* — needs the owner's separate
    approval; the push still sends PIN + name only.
  - *Editable card number* — `card_number` is what a terminal reported and the
    identity layer uses it as merge evidence; a typed value would pass for a reading
    (rule 10). Shown read-only.
  - *"USB reader" enrollment button* — the app has no USB enrollment; rule 9 says hide
    what is not implemented rather than offer a "not available" button.
  - *Department as a dropdown* — no departments exist and no employee uses one; the
    existing free-text field is kept.
  - *Datetime valid period* — kept as dates, per the owner's "start/end dates".
  - *Privilege as 0/2/6/14 integers* — stored as names; the numbers are unconfirmed.
- Multi-card groups have an API (`/api/multi-card-groups`) but no screen yet.

**Open — owner (Ghassan) to decide:**
1. Whether, and when, `device_password` and `privilege` may be written to terminals.
   Writing `admin`/`superadmin` can lock people out of a terminal's menu. The numeric
   mapping (user 0, enroller 2, admin 6, superadmin 14) was in the owner's draft but
   is **not confirmed on any model here** and must be read off an F18 before use.
2. Whether access levels should ever be written to the C3 panels (`userauthorize`),
   which needs a confirmed write on Glass Door / the inBIO first (rule 16).

### 2026-09-23 — Working tree committed; `main` fast-forwarded to `camelot`

Every uncommitted change from 2026-09-14 onward (112 paths: identity rework, adoption,
people / national IDs, SQL Server move, reports, probes and traces) was committed on
`camelot` and `main` was fast-forwarded to it. `php artisan test`: 341 passed before
the commit. The 2026-09-15 and 2026-09-21 traces were checked before committing: they
carry template SHA-1 digests and sizes only, no template bytes (rule 16).

**Not pushed**, by the owner's choice. `/var/biometric-protocol` still has no remote.
Session-log entries below that say "uncommitted" describe the state on their own date.

### 2026-09-21 — National ID lookup: a punch search by CPR, and where a CPR could live on a terminal

Owner asked how to find someone's daily in/out by their national ID (CPR). The
answer was "you cannot" — the number was write-only by design, with no read path
anywhere — so one was built, plus the SQL to answer the same question outside the
app, plus a read-only probe to settle whether the terminal could carry the number
itself.

**Done, NOT COMMITTED (working tree only, branch `camelot`):**

- `POST /api/employees/lookup` — national ID → employee. `employees.view`,
  throttled 20/min, audited hit and miss as `employee.national_id_lookup`, scoped
  to the signed-in company through `employees` because `people` is not
  tenant-scoped. 13 tests in `tests/Feature/Api/EmployeeIdentityApiTest.php`.
- Attendance screen: a National ID box on both presentations, a banner naming whose
  punches are shown, and the PDF export already honours the `employee_id` it sets.
- `docs/sql/national-id-lookup.sql` — person, employments, daily first-in/last-out
  and raw punches. **Run against the live SQL Server the same day**: 32 days
  returned for the test person, missed punch-outs correctly `NULL` rather than 0.
- `tools/zkprobe/f18-user-record-fields.php` + trace — read-only, F18 at
  192.168.7.62, 18 user records.

**The decisions worth keeping:**

- **POST, not GET.** A national ID in a query string lands in the access log, the
  browser history and the next `Referer`. The body is none of those.
- **Exact match, permanently.** The column is an HMAC blind index; a hash has no
  prefix. There will never be a "contains" search on a national ID unless somebody
  decides to store it in plaintext, which is the thing the design exists to avoid.
- **Misses are audited, not just hits.** Somebody guessing numbers looks exactly
  like a run of misses from one user, and that pattern only exists if it is written
  down. Neither the number nor its hash goes into the audit row — a hash you can
  compare a guess against is still the document.

**The device question, answered: there is nowhere neutral to put it.** The 72-byte
user record is fully accounted for, and every always-zero byte on the F18 is the
unused tail of a populated field, not free space. The only field that can honestly
hold nine digits is the **user id / PIN** — which means making the CPR *be* the
terminal's key: viable on a terminal enrolled from scratch, and history-orphaning on
one that already has punches. `card` would make the CPR work as a door credential;
`password` would make it a way to clock in as somebody. Detail and the per-byte
occupancy table are in `docs/device-capabilities.md` and `docs/protocol-overview.md`.

**Open, and it is the owner's call:**

1. **Does the CPR need to be on the terminal at all?** Nothing in the app needs it
   — `employee_code` maps to the person already. If the answer is yes, say whether
   the terminals may be re-enrolled from scratch, because that decides whether the
   user id can become the CPR.
2. **`user id`: 9 bytes or 24?** `UserCodec` says 9; `pyzk` and `adrobinoga/zk-protocol`
   both say 24. The F18's own data cannot separate them (no id exceeds 3 characters).
   Settling it needs a WRITE of a long user id and a read-back — **read-only probes
   cannot answer this**, and it needs the owner's go-ahead on a terminal out of
   service (rule 16).
3. **Extended user fields are `unknown`, not absent.** ZKTeco's SDK exposes
   `SetUserInfoEx` on some firmware. Whether this F18 has an extended user table has
   never been probed. That is the next read if the CPR really must live on the
   device.

### 2026-09-21 — Moved off SQLite onto SQL Server; 198,062 rows copied and verified

Owner supplied a SQL Server box — `192.168.7.8`, login `sa` — and asked for the app
to run on it. Two corrections to the brief before anything else: the database is
**`NewAttendance`**, not "New Attendance" (the spaced name fails login with error
4060), and it was **empty**, created that afternoon at 14:54. The data still lived in
the 138 MB SQLite file.

**Done, and verified:**

- `pdo_sqlsrv` 5.12.0 built from PECL against Microsoft ODBC Driver 18. 5.13+ needs
  PHP 8.3; this host is 8.2. `pecl install sqlsrv` by name fails here — the channel
  metadata returns malformed XML — so the tarballs are fetched directly.
- All 38 migrations applied, plus one new one. `sqlite_legacy` connection added so the
  old data stays readable after `DB_DATABASE` stopped naming a file.
- `php artisan legacy:sqlite-copy` written (repeatable, not a throwaway script, because
  a real cutover needs a second pass for whatever arrives in between). **198,062 rows
  across 27 tables**, including 151,440 punches and 42,322 raw logs. Every table matches
  the source, every foreign key re-validated `WITH CHECK`, identity seeds land above the
  copied maxima, and a second run inserts nothing.
- Apache and `zkt-queue.service` restarted; the API was exercised through Apache against
  SQL Server and returns real data. All 334 tests still pass.

**Five things SQL Server refused that SQLite had silently allowed.** Each is written up
with its resolution in `docs/database-sql-server.md`; the two worth knowing here:

- **Multiple cascade paths are forbidden.** Several `tenant_id` and `device_command_id`
  foreign keys became `NO ACTION`. The awkward one was `attendance_raw_logs`, reachable
  from `devices` twice, where *both* cascades are load-bearing — so the duplicate path
  was broken at `attendance_sync_runs.device_id` instead and `Device::booted()` now
  deletes a device's sync runs first. Verified by deleting device 2 in a rolled-back
  transaction.
- **`CAST(device_user_id AS INTEGER)` throws on non-numeric text**, where SQLite returned
  0. Two roster screens did exactly that. `attendance_logs` already holds **16,386**
  non-numeric device ids off these terminals, so this was a 500 waiting for one junk
  roster row — the kind the F22 and Camelot produce. Now a single scope,
  `DeviceRosterEntry::scopeOrderByDeviceUserId()`, picks the dialect in one place.

**One data point needed care.** Exactly one value in the whole database was not valid
UTF-8: `attendance_logs.device_user_id` on punch 981687, bytes `01be072933`, a corrupt
id off device 5. The driver cannot translate it to UCS-2. Dropping the row was never an
option — that is the failure rule 11 exists to prevent — so the copy widens such values
byte-for-byte and reports each one. The hex round-trips exactly.

**NOTHING FROM THIS SESSION IS COMMITTED.** It is all in the working tree on branch
`camelot`, on this machine only, alongside the large pre-existing uncommitted tree.

**Open, and needing a decision:**

- `DB_TRUST_SERVER_CERTIFICATE=true`, because the instance presents a self-signed
  certificate. Encrypted but unvalidated, so an active man-in-the-middle on that path
  is possible. Proper fix is importing the certificate into the host trust store —
  **Ghassan** to say whether that is wanted.
- The `sa` account is being used directly. A least-privilege login scoped to
  `NewAttendance` would be better — **Ghassan**'s call.
- The instance is **Express**, capped at 10 GB. At 272 MB today, but nothing prunes
  `attendance_raw_logs`.

### 2026-09-15 — C3 user writes: the command number is RECOVERED, the payload is not

Owner asked to make the C3 panels (Entrance, Glass Door) accept user writes. Went in
rule 17's order — research first — and the answer is a narrow, named gap rather than
a feature.

**Research settled that nobody has published this.** `vwout/zkaccess-c3-py`, the
reference this project already uses, has **no write command at all**: its command set
stops at `GETDATA (0x08)` and its README says the protocol is undocumented and
everything rests on reverse engineering. The vendor's PullSDK documents
`SetDeviceData(handle, table, "Pin=1\tCardNo=123", options)` — but that is the DLL's
C interface, not the wire.

**So it came out of the DLL**, the same `plcommpro.dll` that gave up the buffered
read. `CMD_SET_DATA = 0x07`, evidenced by the SDK's own log string sitting
immediately before the constant it sets (`"…!Cmd=CMD_SET_DATA, datalen=%d"` then
`movl $0x7,0x44(%esp)` at `0x1000b7c2`). Two more fell out of the same pass:
`DELETE_DATA = 0x09` and `GET_DATA_COUNT = 0x0A`. The extraction independently
reproduces `0x03`, `0x04` and `0x06` — already known from the references — which is
what makes it a method rather than a coincidence.

**What was deliberately NOT done: the write itself.** The payload layout for a write
is unknown. The read request is `[tableIndex][fieldCount][fieldIndexes…][0,0]` and the
DLL tokenises the caller's field list on `','`, so a write very probably mirrors it —
and "very probably" is not a basis for sending bytes to a panel holding **320 live
enrolments** behind a door. A malformed write to `user` overwrites people whose
templates cannot be restored from any backup. Rule 16 says stop here and write the
gap down; rule 17 says a narrow known gap is a good place to stop.

`user.write` therefore stays **`not_implemented`** on C3, the UI keeps hiding it, and
a push to those two panels is still skipped with *"this protocol cannot write users"*.

**To finish it, best first — none started, all need the owner:**

1. **Capture ZKAccess 3.5.5 writing one user** and read the bytes. One afternoon and
   the layout is exact. Commit the decoded structure and a redacted fixture, never
   the `.pcap` (rule 16).
2. Or more DLL work: the payload builder sits between `0x1000af68` and `0x1000b7c2`.
3. Then a write probe against a **test PIN** on one panel, its `user` table saved
   first. `GET_DATA_COUNT (0x0A)` is the safe rehearsal — read-only, same extraction,
   and a correct row count proves the numbers survived the DLL intact.

Library side: `promoseven/biometric-protocol` **2.6.0** carries the three constants,
their evidence in the docblock, and `C3CommandNumbersTest` pinning them so a silent
edit cannot happen. Nothing sends them. The app is unchanged.

### 2026-09-15 — Adoption was invisible AND unreachable from the UI; both fixed

Owner pushed an employee and got four refusals in a row: *"Skipped Camelot — its
roster has not been read and claimed yet, so it is read-only"*, and the same for
Entrance, Glass Door and IT Room. The gate was working exactly as designed. Two
things around it were not.

**1. The devices payload never mentioned adoption at all.** No `adoption_status`, no
`is_writable`, nothing. So every screen offered pushes the hardware would refuse and
the only way to discover a terminal was read-only was to try one — the opposite of
rule 9. `DeviceResource` now carries `adoption_status`, `is_writable`,
`write_refusal` (the same sentence `Device::userWriteRefusal()` gives the propagator,
so screen and server cannot disagree), `roster_read_at` and a
`unclaimed_roster_entries` count, `withCount`ed in the list query rather than asked
per row.

**2. There was no way to adopt from the UI.** `POST /devices/{device}/adopt` and
`php artisan device:adopt` both existed; **no screen called either**. So the refusal
told operators to do something they could not do. There is now an **Allow writes**
action on the devices list (desktop menu and mobile button) plus a **Read-only** /
**N to claim** badge on the row, and a shared `adoptConfirm()` so both screens warn
in the same words. The action only appears while the device is read-only AND nothing
on it is undecided — offering it earlier would produce a 422 that teaches people to
distrust the screen.

**The gate itself is unchanged and was not weakened.** `finishAdoption()` still
refuses while any roster entry is unclaimed, and still refuses a device whose roster
was never read. `tests/Feature/Devices/DeviceAdoptionGateTest.php` pins all four
behaviours.

**All six terminals are one click from adoption right now** (verified 2026-09-15):
rosters read at 07:35, **zero unclaimed entries on every one** — Camelot 24 entries,
Entrance 320, Glass Door 320, Good Wheels 18, IT Room 18, On The Road 11. Nobody has
pressed it yet, and that remains the owner's decision: adopting is what permits every
future write to that hardware.

### 2026-09-15 — A Push button, a merge UI, and a constraint bug the merge never hit

Follow-on from the edit form. Two asks: a button named **Push** (propagating only as
a side effect of saving is the wrong shape — a replaced terminal or a push skipped
while a device was read-only needs no field changed, and making somebody re-save an
unchanged record to trigger a device write teaches them to distrust the save button),
and a way to **merge two accounts** belonging to one person.

**Push** — row action on both presentations, opening `PushModal`. Lists the terminals
they are already on (pre-ticked, the repair case) separately from the writable ones
they are not (unticked, the enrolment case), and posts to the same `propagate`
endpoint. The checklist is now one shared `DeviceTargets` component used by the
add/edit form too, so the two cannot drift about what they offer.

**Merge** — `POST /api/employees/merge` {keep_id, merge_ids[]}, wired to `MergeModal`.
`EmployeeMergeService` and `DuplicateEmployeeFinder` already existed with a console
command and no HTTP surface at all; this exposes them.

**It is a SEARCH, not a suggestion list, and that is deliberate.** The finder catches
the import artefact — same name at the SAME PIN on several terminals — and returns
**0 groups** on this estate today. The owner's own duplicate is the other shape: one
human under DIFFERENT pins on different devices, which no evidence here can tell
apart from two colleagues who share a name. Only a person who knows the people can
say, so the dialog asks them and does not guess.

**Bug found by the tests, in existing uncommitted code:** `employee_device_maps`
carries a partial unique index — one live mapping per (device, employee) — and two
duplicate records enrolled on the SAME terminal under different PINs each satisfy it
alone and violate it the moment they become one employee. The merge died on a
constraint error mid-transaction. `EmployeeMergeService::closeCompetingMappings()`
now leaves exactly one live mapping per device before re-pointing: the keeper's own
where it has one, otherwise the most recently device-confirmed. The losers are
CLOSED, never dropped, so punches made under the superseded PIN still resolve to the
merged human. The index was right; relaxing it was never the fix.

**The owner's actual duplicate, verified live on 2026-09-15 and NOT merged** — that
is their call, not mine:

| | Record | Code | Punches | Terminals |
|---|---|---|---|---|
| keep | #1404 Ghassan | 656-2 | 2,068 | Entrance, Glass Door, IT Room (PIN 656) |
| fold | #1345 Ghassan | 1-12 | 4 | Camelot (PIN 1) |

No shared terminal, so no PIN would need closing; the result is one record with 2,072
punches across four terminals.

### 2026-09-15 — Employees can be edited at last, and a save can push to the terminals

Owner asked how to edit a person and have it reach the devices, and how to create
one and put them on chosen devices. The honest answer was that **neither was
possible from the UI**: there was no edit screen at all (`EmployeeFormModal` took
only `onSubmit`/`onClose`, `PUT /api/employees/{id}` existed and nothing called it),
and no save of any kind touched a terminal. Propagation meant opening the enrollment
dialog and pushing, one device at a time, with nothing on screen saying it was
needed. Built all three parts.

**Saving still writes only the database, on purpose.** A corrected spelling is not a
hardware operation, and a save that silently wrote to six terminals is one typo away
from rewriting six records for somebody else. The form ASKS instead: it lists the
terminals, pre-ticked on an edit, unticked on a create — which terminals a joiner
belongs on is a decision nobody else can make — and the save is followed by
`POST /api/employees/{employee}/propagate`, one queued `PUSH_USER` per device.

| Piece | Where |
|---|---|
| Queueing, per-device refusals | `app/Services/Employees/EmployeeDevicePropagator.php` |
| Refusal wording, on the model | `Device::userWriteRefusal()` |
| Endpoint | `EmployeeController::propagate()`, `POST employees/{employee}/propagate` |
| Edit mode + device picker | `EmployeeFormModal.jsx`, both Employees presentations |
| Tests | `tests/Feature/Devices/EmployeePropagationTest.php` (6) |

Three decisions worth keeping:

1. **Queued, never inline** (rule 11) — a push reads the slot before writing, and
   across several terminals that outlives a request. A command per device is also
   what makes "it updated on four of five" legible.
2. **A device that cannot take a write is refused BEFORE queueing**, with a reason,
   because the operator is at the screen now — not tomorrow looking at a failed job.
3. **Only live mappings are offered.** A closed mapping is a PIN they used to hold;
   pushing to it would write their name over whoever holds that slot today. The
   payload now carries `is_live` per enrollment so the form cannot offer one either.

Rule 8 caught the first attempt: the propagator imported `Capability` directly.
Rather than widen the `$allowed` list in `ProtocolLibraryBoundaryTest`, the question
moved onto `Device`, which is already on that list — so the service asks the model
and gets a sentence back. That is the better shape anyway.

**OPERATIONAL FACT, and it blocks this feature in practice: all six terminals are
`read_only` today.** None has had its roster read and every entry claimed, so
`userWriteRefusal()` refuses every one of them and a propagate queues nothing.
Verified live on 2026-09-15 — pushing employee 1345 to Camelot returned *"was not
queued to any terminal. Skipped Camelot — its roster has not been read and claimed
yet"*. The feature is correct; **adoption is the prerequisite nobody has done.**

### 2026-09-15 — Camelot's reads DESYNCHRONISE partway; we are missing 3 years of it

Found while explaining a `partial` badge to the owner, not by looking for it. It is
the most serious thing on this list.

**The evidence, all from `attendance_sync_runs` and `attendance_raw_logs`:**

| Run | Device | Mode | Read | Decoded | Undecodable |
|---|---|---|---|---|---|
| 183 | Camelot | full | 41,655 | 17,489 | **24,166** |
| 185 | Camelot | range | 41,655 | 25,805 | **15,850** |
| 181 | Good Wheels | full | 11,126 | 1,230 | **9,896** |
| 182 | Good Wheels | full | 11,126 | 11,126 | **0** |

Same device, same table, same record count, runs 45 minutes apart — and a different
number of records fails to decode each time. **So it is not the data.** Good Wheels
proves it hardest: 9,896 failures on one run and none at all on the next.

**What the failing bytes actually are.** They are not garbage — they are correct
records cut at the wrong boundary. In run 185's archived chunks the uid and the
ASCII user id sit at offset 12 of a 40-byte chunk, not offset 0, and the uid
increments by one from chunk to chunk (`0x3e67`, `0x3e69`, `0x3e6a`, `0x3e6b`). The
codec is slicing a correct stream on the wrong stride, so `Timestamp::decode()`
reads four bytes that straddle two records and returns null — which
`AttendanceCodec` faithfully reports as undecodable, exactly as designed.

**It is always the NEWEST data that is lost.** Run 183 decoded 1999-12-31 through
**2021-12-25** and failed on everything after. Camelot holds 41,655 records; this
app has 13,114 of them and its newest punch is **2023-04-24**. The desync happens
partway through the transfer and discards the entire remainder, and the remainder is
the recent end of the table.

**What is NOT yet known** (do not guess this into the code):

- Where the desync originates — transport, buffer assembly, or a window placed at
  the wrong offset. Both runs read exactly 41,655 records, so the body length did
  not change; only its alignment did. That argues against plain byte loss and
  towards a misplaced window in the staged read.
- Whether it is recoverable from the archive alone. A first attempt at re-aligning
  run 185's 15,850 archived chunks found a best fit at a 20-byte shift decoding only
  53% of them, with dates running to 2027 — so the block is not one contiguous
  constant-offset run, and a naive re-slice is not the answer.

**Next step, in rule 16's order and NOT started:** probe Camelot with an F18 control
read in the same session, capture the staged table to
`tools/zkprobe/traces-YYYY-MM-DD/`, and find the offset where alignment breaks.
Read-only. The owner has been shown the finding and has not yet said to proceed.

Two things the existing design got right and should not be weakened: the raw bytes
were archived before parsing, so the evidence above exists at all; and the records
were COUNTED rather than dropped, which is the only reason this was visible from a
badge on a screen.

### 2026-09-15 — Every screen was slow: a missing index, then a 1,072-query report

Owner asked why moving between pages takes so long. Measured, not guessed: each
endpoint was run through the real HTTP kernel with per-query timings
(`/api/employees` was **1,951 ms, of which one query was 1,891 ms**).

**`attendance_logs` shipped with two indexes — `tenant_id`, and the unique punch
key — and neither serves a single thing the UI does with the table.** At 151,288
rows the `withCount('attendanceLogs')` on the employee list scanned the whole table
**once per employee on the page**: 25 employees, 25 full scans, two seconds, every
visit. The devices list did the same per device (179 ms of a 233 ms response): the
unique key does start with `device_id`, but the tenant predicate sent the planner to
the `tenant_id` index, which on a single-tenant install selects everything.

Four indexes added in `2026_09_15_150000_index_attendance_logs_for_reads`,
**APPLIED on this box on 2026-09-15**: `(tenant_id, employee_id)`,
`(tenant_id, device_id)`, `(tenant_id, punched_at)`, `(employee_id, punched_at)`.

| Endpoint | Before | After |
|---|---|---|
| `GET /api/employees` | 1,951 ms | **31 ms** |
| `GET /api/devices` | 233 ms | **62 ms** |
| `GET /api/attendance` | 78 ms | 36 ms |

Nothing was added to `attendance_raw_logs` (486,821 rows): it is written by every
sync and read only when replaying a decoder fix, so a third index would tax every
insert to speed up nothing a person waits for.

**The payroll report's N+1 is fixed too, in the same session.** It was 694 ms and
1,072 queries for 357 employees, and it split into two problems that looked like
one: ~119 ms of SQL (three queries per employee, one of which — the holiday list —
did not even vary by employee) and **~575 ms of PHP**, because `Carbon::parse()` ran
four times per work day per employee to rebuild the same shift marks. Thirty days
across 357 people is over forty thousand date parses producing thirty distinct
answers. Batching the queries alone would have fixed the smaller half.

`App\Services\Attendance\SummaryBatch` now carries both — the rows for a whole
roster in three queries, and the per-day shift marks worked out once.
`AttendanceSummaryService::summary()` reads nothing itself any more; given no batch
it builds one for a single employee, so **the employee screen and the payroll report
run identical code**. That was the constraint throughout: two code paths producing
"payroll hours" is how a report and its printout come to disagree.

| | Before | After |
|---|---|---|
| `GET /api/reports/attendance` | 700 ms, 1,072 queries | **154 ms, 4 queries** |

**Proven equivalent, not assumed.** The pre-refactor classes were extracted from
`HEAD` into a throwaway namespace and both implementations were run over a seeded
fortnight covering every branch — early, late, missed punch-out, absent, holiday,
waiver — asserting equality row for row and day for day. That scaffolding is gone;
what remains is `tests/Feature/Attendance/PayrollReportTest.php`, which pins the
figures that survived it and **asserts the query count**, because an N+1 is
invisible to a test that only checks the answer.

**Found while spot-checking, NOT fixed, and pre-existing:** `GET
/api/exports/attendance` with no date filter renders its 5,000-row cap through
dompdf and does not finish — over 120 s in CLI, a 500 through the web server. It is
reachable from the Attendance screen by clearing the filters and pressing Export
PDF, since the button forwards whatever filters are set and sends none when there
are none. Nothing here touched that path (`PunchLogQuery` and `ExportController` are
unchanged). The fix is either a required date range or moving the render onto the
queue with a download when it is ready; the owner has not been asked which.

Two facts worth keeping for whoever profiles this next:

- **Origin latency is not the problem.** An authenticated request measured at the
  origin over loopback is 44-58 ms end to end, PHP boot included. The public name
  resolves to Cloudflare, so anything measured from outside includes that path.
- **Config and routes are NOT cached** in this production install
  (`php artisan config:cache`, `route:cache`). Worth doing, but it is tens of
  milliseconds — it was never the cause, and caching config means re-running it
  after every `.env` change.

Housekeeping: editing an already-applied migration to add the fourth index left the
table half-indexed when `migrate:rollback` failed part way (SQLite dropped one index
before hitting the one that did not exist yet, and the migration row survived). The
indexes were dropped by hand, the row deleted, and the migration re-run clean —
`PRAGMA index_list` now matches the file exactly. Do not edit an applied migration;
write a second one.

### 2026-09-15 — Attendance can be read for a date range; watermark deliberately untouched

Owner asked for an option on `/app/devices` to read punches "between this date and
this date rather than reading all dates". Shipped on the devices list and on device
detail, from one dialog (`SyncRangeModal.jsx`), with presets covering the previous
7 days, the current calendar month, and the one before it.

**The honest part, and the reason the dialog says it on screen:** this protocol has
no date filter and never had one. `ATTLOG_RRQ` returns the whole table, so a range
read transfers exactly as much as a full one — it bounds the **import**, not the
read. An operator told otherwise would report the progress bar as broken when a
one-week range still took four minutes on Camelot.

Three decisions worth keeping:

1. **The window replaces the watermark; it does not narrow it.** The month being
   repaired is almost always one the watermark has already passed, so narrowing
   would have made the commonest request return `read 9,579, imported 0`.
2. **A range read does NOT move the watermark.** Dragged backwards it would merely
   cost a re-read; dragged *forwards* by a window ending today it would skip every
   punch between the old watermark and today **permanently**. Leaving it alone can
   only cost work.
3. **The window is the DEVICE's calendar day**, built in `device.timezone` and
   widened to start/end of day. Built in the app timezone it would be three hours
   out at both ends — the same offset class as the two timezone bugs already
   recorded in `docs/attendance-sync-flow.md`.

Records outside the window are counted in a new `records_out_of_range`, never
silently dropped (rule 10), and are deliberately not archived to
`attendance_raw_logs` — a targeted import should not write the whole table as raw
rows. A full re-read remains the thing that archives everything.

| Change | Where |
|---|---|
| Migration **APPLIED on this box on 2026-09-15** | `range_from`, `range_to`, `records_out_of_range` on `attendance_sync_runs` |
| Window validation, shared by both endpoints | `app/Support/AttendanceWindow.php` |
| Filtering, bounds, watermark rule | `AttendanceSyncService::sync()` / `ingest()` / `resolveRange()` |
| UI | `SyncRangeModal.jsx`, both Devices presentations, both DeviceDetail presentations |
| Tests | `tests/Feature/Devices/AttendanceRangeSyncTest.php` (6), plus 2 in `DeviceQueueTest` |

**Not committed.** Working tree only, on branch `camelot`, on top of an already
large uncommitted tree. The `public/build` assets were rebuilt, so the live site is
already serving the new UI.

**Sidebar flattened in the same session.** `Devices` in the desktop sidebar was a
bare `<button>` that only opened and closed a sub-page tree, so `/devices` — the
screen the owner was asking about — could not be reached from the side menu at all,
only from the top-bar sync pill or by typing the URL, and it never highlighted as
the current page. On the owner's instruction the nesting is gone entirely:
**Hardware** now lists four siblings — Devices, Sync history, Enrollments,
Reconciliation — and `NAV_SECTIONS` has no `children` key anywhere, so the tree
rendering in `DesktopLayout.jsx` and the child-flattening in `MOBILE_MORE_ITEMS`
were removed with it.

**`/device-groups` is no longer in any menu.** The owner's list of four did not
include it. The route, the screen and the API are untouched and still work by URL;
nothing in the UI links to them. Add it back as a fifth Hardware item if grouping
turns out to be day-to-day work. Side effect worth knowing: the command palette
reads `NAV_ITEMS`, so it now offers the three hardware screens it could not reach
while they were nested, and no longer offers Groups.

While in there, two stale claims in `docs/attendance-sync-flow.md` were corrected:
`POST /devices/{id}/sync-attendance` has queued rather than run inline since the
524-timeout fix, and there is no 300-second inline time limit any more.

### 2026-09-15 — C3 templates cracked; every terminal now readable; ZERO duplicates left

Owner asked for fingerprints off every capable device, and for the C3 panels to be
worked out from the vendor software. Both done.

**The C3 panels were never unreadable.** `templatev10` had simply never been
requested, and the panel publishes its schema in every `DATATABLE_CFG` reply:

```
templatev10=10,Size=i1,UID=i2,Pin=i3,FingerID=i4,Valid=i5,Template=B6,Resverd=i7,EndTag=i8
```

**One byte was the whole problem.** A C3 field is length-prefixed with ONE byte, max
255; these templates run to 1,618. The first record decoded perfectly — `Size=878
Pin=10 FingerID=3`, and 878 is exactly what the vendor's BioTemplate holds for badge
10 finger 3 — then the walk consumed 110 bytes of an 878-byte blob, lost alignment
inside that record, and turned all 344 after it into plausible-looking nonsense.

**A blob field carries a TWO-byte length prefix.** Found by dumping the framing:
after `Valid`, the bytes read `6e 03 4a 2d 53 53 32 31` — `6e 03` is 878 little-endian,
then the `J-SS21` template magic.

| | inBIO460 `.61` | TF1700 `.60` |
|---|---|---|
| Records | **238** | **238** |
| Consumed | 281,680 / 281,680 | 281,680 / 281,680 |
| People | 196 | 196 |
| `SS21` signature | every record | every record |

238 = the `fingercount` the vendor's `Machines` table reports for each panel.
196 = exactly the PINs with a template in its `BioTemplate` table. Four independent
cross-checks agreeing.

**Every terminal now reads fingerprints** — 577 templates across all six:

| IT Room | Good Wheels | On The Road | Camelot | Glass Door | Entrance |
|---|---|---|---|---|---|
| 16 | 32 | 16 | 37 | 238 | 238 |

**Which closed the last duplicates.** The four people (Mary Ann 225, Monir 230,
Ranjith 232, Vijith 233) whose Camelot records had to be left separate turned out to
have Camelot templates byte-identical to the panels'. Merged on that evidence.

**Duplicate groups remaining: 0.** Employees 711 → 554 → 361 → **357**.

Library **v2.5.0**, tagged. `RecordCodec` two-byte blob prefix; `Panel::readTemplates()`
and `readTableRaw()`; `C3Driver::readTemplateDigests()` implemented rather than
throwing; `TEMPLATE_TABLE_READ`/`TEMPLATE_BLOB_READ` promoted to CONFIRMED **per
model** (inBIO460, TF1700 only — rule 10), family default left IMPLEMENTED. Synthetic
fixture; a real capture is 238 people's fingerprints and is not committed.

Probes: `tools/zkprobe/c3-templatev10.php`, `c3-templatev10-framing.php`, traces in
`traces-2026-09-15/` — digests and sizes only.

**Still not solved:** writing a template to a device. Payload known, envelope not.

### 2026-09-15 — The vendor database settled the duplicates; template write payload known

Owner pointed at `draft/extracted` — the ZKAccess 3.5.5 install, with its `Access.mdb`.
It answered both stuck questions. **`draft/` stays gitignored**; only decoded structure
is recorded, in `docs/zkaccess-database.md`.

**1. A PIN IS the person — inside the set one install manages.**

`Machines` holds exactly four rows: `.60 .61 .62 .63` — precisely the members of the
"Promoseven Holdings" device group, and nothing else. Good Wheels and Camelot are
separate installs, which is why a PIN shared with them means nothing.

`USERINFO` holds **334 people with 334 distinct Badgenumbers**. Cross-checked against
our roster reads: **320 distinct PINs across those four terminals, all 320 present in
USERINFO, all 669 roster names matching exactly, zero disagreements.**

So ZKAccess keeps one employee list and pushes the same badge number everywhere it
manages. New concept `device_groups.shared_pin_namespace` records that — operator-
declared, with the evidence stored on the row, **off by default**, because asserting it
across an unmanaged terminal is exactly how two people become one.

Effect on the duplicate backlog:

| | before | after |
|---|---|---|
| one vendor-managed PIN namespace | — | **177** |
| proven by fingerprint template | 2 | 1 |
| unconfirmed | 180 | **4** |

The 4 remaining span Camelot plus the managed panels, so the claim correctly does not
reach them. **Not yet merged — awaiting the owner.**

**2. The template write PAYLOAD is now known. The command is not.**

`BioTemplate`: 248 templates, 204 people, `BioType=1`, `Version=10.0` (ZKFinger v10),
`DataFormat=0`, `TemplateData` base64 → 564-1,620 raw bytes, signature `SS21` at
offset 2. **No machine column** — one template per person per finger, pushed to all,
exactly as the owner described.

Compared against live reads of the managed terminals, 32 `badge:slot` pairs:
**26 byte-identical, 6 differing only by trailing `00` padding, 0 otherwise.** The
stored blob IS the device blob, no per-device transformation.

So `uploadTemplate()` no longer lacks a payload — it lacks an envelope.
`template.upload` stays `not_implemented`: knowing what to send is not knowing how.
The next step is a `tcpdump` of ZKAccess pushing one enrolment, which under rule 16 is
a **write** probe — the first here — needing the owner's go-ahead and a test user.

**3. The name transposition is now evidence, not inference.** ZKAccess calls `.61`
"P7H ENTRANCE" and `.60` "GLASS_ENTRANCE"; this app has them the other way round.
Still the owner's call to rename.

**4. `USERINFO.SSN` is NOT a national ID** — one digit, identical on all 334 rows. A
flag. There are no national IDs in the vendor database, so that gap is unchanged and
only the owner can close it.

**5. The C3 panels hold 238 fingerprints each** per `Machines.fingercount` — data that
exists and that our C3 driver still cannot read.

### 2026-09-15 — Fingerprint templates ARE readable; digests now outrank cards

The owner's observation is what cracked it: *"when using the original ZKT software we
register a fingerprint on one device and then make other devices use the same
fingerprint"*. If ZKAccess copies enrolments between terminals, the same person's
template is byte-identical on several devices — which identifies duplicated employee
records far better than a name.

**Research answered it before a single packet was sent** (rule 17). Nothing needed
reverse-engineering: `TemplateCodec`'s own docblock already recorded the layout,
confirmed against a 22,066-byte capture from the F18 on 2026-09-02.

```
[uint32 total length]
  [uint16 record length][uint16 uid][uint8 slot][uint8 valid][TEMPLATE BLOB]
```

`decode()` read the 6-byte header and **stepped over the blob**. The bytes had been
arriving on every enrollment sync for weeks. `template.download` being
`not_implemented` was about the separate per-slot command and about writing back — it
never blocked reading, and the assumption that it did went unexamined.

**Confirmed on hardware, with a control** (rule 16): `tools/zkprobe/f18-templates.php`,
read-only, traces in `tools/zkprobe/traces-2026-09-15/`, digests and sizes only —
never the bytes.

| PIN | slot | result |
|---|---|---|
| 261 | 3 | same **1,054 bytes** on the F18 `.62` and the F22 `.63` |
| 261 | 6 | same 1,132 bytes on both |
| 654 | 6, 7 | identical on both |
| 940 | 6 | identical on both |

**Identical across two different models.** The vendor tool copied one enrolment to
both terminals, exactly as the owner described.

**Built** (rule 8 order: library, adapter, app):

- Library **v2.4.0**, tagged: `TemplateCodec::decodeWithBlobs()`,
  `TemplateService::allWithTemplates()`, new capability `template.blob.read` =
  **CONFIRMED**. `decode()` keeps its narrow `{uid,fid}` contract. README updated.
- `BiometricDeviceDriver::readTemplateDigests()` — **no blob crosses the seam**; the
  app gets sizes and SHA-1s, so biometric bytes cannot reach a log or an API response.
- `employee_device_maps.template_digests` (`["<slot>:<sha1>", …]`). **Digests, not
  bytes** — deliberate: the hash answers the only question asked and is not reversible.
- `php artisan device:read-templates` (read-only) records them.
- `DuplicateEmployeeFinder` gains verdict `PROVEN`, ranked **above** a card match.

**It beat the card on the one case where they disagreed.** "Ragesh" PIN 261 had
card `10530436` at four sites and `7459039` at Camelot — the single CONTRADICTED
group. Two of his templates match byte for byte, so he is now PROVEN. A card is
reissued when one is lost; a fingerprint is not.

**The honest limit.** The two C3 panels keep templates in a `templatev10` table this
driver does not read, so `readTemplateDigests()` throws there. Glass Door and Entrance
are precisely the pair holding the duplicated roster, so template evidence settles
**1 of the 181** remaining duplicate groups. The capability is real; its reach on this
estate is not. National IDs remain the answer for the rest.

**What is still NOT solved, and is what the owner ultimately wants:** *writing* a
template to another device. `uploadTemplate()` still throws — the blob's internal
format and the matching write command are unverified, and a template that appears to
save then fails to match a real finger is worse than no feature. The next step is
rule 16's highest-value source: capture ZKAccess pushing an enrolment and read the
write command off the wire. That needs the owner's go-ahead and a test user, because
it is the first **write** probe this project would run.

Earlier the same day: **138 card-confirmed duplicates merged** (711 → 554 employees,
157 records removed, no mapping or punch lost; backup
`database/database.pre-merge-20260915-091157.sqlite`), and **98,267 punches
re-attributed** after they were found attached to nobody.

### 2026-09-15 — Device identity rebuilt; database wiped and re-pulled; NOTHING COMMITTED

Triggered by two production failures the owner reported: pushing employees overwrote
people already on the terminals, and adding devices cross-matched unrelated humans who
shared a PIN.

**Root cause, single and shared.** The device PIN, the payroll code and the human were
one identifier. A PIN is a slot number local to one terminal; `employee_code` is a
payroll number issued by one employer; neither says who somebody is. Measured on the
live estate: 16 PINs meant different people on different devices, and 260 of 293
distinct people appear on more than one terminal.

**Built.**

- **Three-layer identity.** `people` (national ID, encrypted + blind index, not
  tenant-scoped, with `person_national_ids` for reissued documents) → `employees`
  (now `person_id`, and `employee_code` unique **per tenant**, not globally) →
  `employee_device_maps` (now `valid_from`/`valid_to`; live rows guaranteed unique by
  partial indexes).
- **Adoption gate.** `devices.adoption_status`: `unadopted` → `read_only` → `adopted`.
  `Device::assertWritable()` refuses every write until the terminal's existing roster
  has been read and every entry claimed or ignored. New table
  `device_roster_entries` holds what the device says before anyone decides who they are.
- **Three write guards**, all now unavoidable: adoption gate, mapped PIN
  (`DeviceIdentityResolver::pinFor()` throws rather than deriving one), and a slot guard
  that reads the target record and refuses if it belongs to somebody else.
- **The guard moved onto the driver contract** — `BiometricDeviceDriver::saveUser()`
  and `deleteUser()`. The read that proves a slot is free and the write that fills it
  must share one session (rule 11); a service doing "find, then save" reopens the window.
  `DeviceIdentityResolver` therefore holds the *decision* and no session, so it speaks no
  protocol and is testable without hardware.
- **Attendance resolves through time.** A punch attributes to whoever held the PIN at
  the punch's own timestamp, not the current holder. Mappings adopted off hardware get
  `valid_from = null` — the person was already enrolled and we do not know since when;
  stamping the claim date would have orphaned 87,000 Glass Door records.
  `attendance:reattribute` recomputes stored punches when a claim lands later.
- **Commands**: `identity:reset`, `device:roster`, `device:import-roster`,
  `device:suggest-claims [--apply]`, `device:adopt`, `attendance:reattribute`.
- **API**: `GET/POST /api/devices/{device}/roster…`, `POST .../adopt`. **No UI consumes
  these yet.**
- **13 tests** in `tests/Feature/Devices/DeviceIdentityGuardTest.php`, against an
  in-memory fake whose `saveUser()` overwrites exactly as the real firmware does — a
  fake that refused to overwrite would pass whether or not the guard existed. Suite:
  272 passing.

**Executed against the live estate.**

1. Backed up the database (`database/database.pre-identity-rework-20260915-072217.sqlite`).
2. `identity:reset` — wiped 343 employees, 715 mappings, 161,959 logs, 274,616 raw logs.
   **The owner chose to include attendance after being told the rolled-off history is
   unrecoverable.** All six devices reset to `unadopted`.
3. Read all six rosters (read-only): 711 entries.
4. Imported Entrance (320) as the employee base, then `device:suggest-claims --apply`
   on the rest — 235 on Glass Door, 15 IT Room, 8 On The Road, 7 Camelot. Imported the
   35 genuinely new people on Good Wheels and Camelot. **355 employees, not 711.**
5. Adopted the three devices with nothing undecided. Re-read attendance: 151,264 punches.

**Findings worth keeping.**

- **Entrance and Glass Door hold identical rosters** (259 distinct names each). They are
  the two C3 panels at the same site.
- **Most unmatched punches are unrecoverable, and that is correct.** Of the unmatched
  PINs, 56/59 on IT Room, 10/12 on On The Road and 69/85 on Glass Door are **no longer on
  the device at all** — leavers whose user record was deleted while their punches
  remained. They stay unattributed rather than guessed at.
- **A stale `WithoutOverlapping` lock masquerades as a device fault.** Camelot's sync
  exceeded the worker timeout; the lock survived, so every requeue returned in ~11 ms
  without touching the device and the command row sat at `running`. Also confirmed the
  documented trap: passing `--tries=1` to `queue:work` fails these jobs on the first
  release. The job declares `$tries = 25` for that reason — do not override it.
- Three failed jobs in `failed_jobs` are from **2026-09-14**, not this session.

**Then the staff list was cleared again, on purpose.** The owner chose to import the
real roster from HR rather than keep 355 employees whose names came from 24-byte device
fields. `identity:reset --employees-only` was added for it: it clears people,
employments, mappings, templates and cards, while **keeping** roster entries, attendance
and every `ignored` decision. Claims are released and adopted devices drop back to
`read_only`, because an adopted device with undecided entries is the state that allowed
the original overwrite.

**`staff:import` was built for the import** (`app/Console/Commands/StaffImport.php`,
5 tests). CSV with `name, employee_code, national_id, email, department, tenant`. The
same `national_id` appearing twice is **not** an error — it is one human at two sister
companies, and becomes one Person with two Employments. Normalisation strips case,
spaces and punctuation, so `860101123` and `860-101-123` match. A re-import that omits
the column never detaches an already-identified person.

There was previously **no bulk employee import at all** — only the one-at-a-time form on
the Employees screen, which is unusable for ~300 people.

**Docs updated** (rule 12): new `docs/device-identity-and-adoption.md`; corrected
`enrollment-flow.md`, `attendance-sync-flow.md`, `api-endpoints.md`,
`error-handling.md`, `integration-laravel.md`.

**Still open.**

- **Commit and push.** Nothing from this session is in git.
- **The HR staff CSV.** Everything downstream is blocked on it. **Owed to Ghassan.**
- **No UI for claiming.** The API exists; no screen calls it. Around 91 entries will
  stay ambiguous whatever the CSV says.
- **C3/CSL panels cannot write.** `saveUser()`/`deleteUser()` throw
  `UnsupportedCommandException` — declared, not silently plausible (rule 9).

### 2026-09-14 — Everything committed; readUsers added to the contract; all operations verified on hardware

Committed the C3 work (app `69748ae`), and **the library is finally a git repository**:
`/var/biometric-protocol` now has history and a **`v2.3.0` tag**, 70 tracked files.
That was the top blocker in this file for weeks — the protocol layer was running in
production from one machine's filesystem with no history, no tags and no remote.
It still has **no remote**; pushing it somewhere is the remaining half.

**Two device rows were wrong, and it mattered.** Rows 13 and 14 had each other's
`model`: `.61` was registered as a TF1700 and `.60` as an inBIO460, when it is the
other way round. With the C3 driver that selects the wrong model class — and since
the TF1700 needs `0x01` and the inBIO460 refuses it, a wrong pick means **zero
records with no error**. Corrected by reading identity off the wire and anchoring on
the IP, which is the only field that can be verified. Both are now protocol `c3`
with their capability cache cleared (it had been resolved by the wrong driver).
Their NAMES are still transposed and were deliberately left alone — see the terminal
table.

**`readUsers()` added to `BiometricDeviceDriver`, closing a real leak.** Reading a
roster was expressed only as "call `run()` and use the ZKTeco `UserService` inside
the closure", so it was hard-wired to one protocol. `CslHttpDriver` and `C3Driver`
both throw on `run()`, which means **"Import staff" had been answering a 502 on CSL
terminals while the capability said `confirmed`** — a latent bug that predates C3
entirely. `DeviceHealthService::identify()` had the same leak, so "Read from device"
on the add-device form could only ever identify a standalone terminal. Both now go
through the contract. Only two `run()` calls remain in the app, both ZKTeco writes.

**Rule 9 gap closed in the UI.** The device action menu offered all four actions on
every device regardless of capability. It now reads the `usable` flag the API was
already sending. `Test` stays ungated on purpose: reachability is how you find out
what a device can do.

**Every operation verified against real hardware**, read-only, nothing persisted
(`tools/verify-devices.php`, run by three agents in parallel — one per device group,
so no two commands ever hit the same device):

| Device | ping | info | readUsers | readAttendance |
|---|---|---|---|---|
| F18 `.62` | 12 ms | ok | 18 | 10,529, 0 undecodable |
| F22 `.63` | 15 ms | ok | 11 | 8,162, 0 undecodable |
| inBIO460 `.61` | 310 ms | ok | **320** | **87,756**, 0 undecodable |
| TF1700 `.60` | 443 ms | ok | **320** | **21,005**, 0 undecodable |

The two binary terminals were the CONTROL for the contract change, and the F18 — the
reference path — behaved identically. That is what makes the C3 numbers evidence
about C3 rather than about the change (rule 15).

**`USER_READ` promoted to `confirmed`** on both panels, on that evidence. Finger
slots come back **null** meaning "not read", never "none enrolled" (rule 10).

**"Read from device" now identifies all four**, across both protocols.

**Import staff was RUN for real** — the owner asked for it. 286 employees created
(56 → 342), 711 `employee_device_maps` rows. All 286 carried a real name from the
device, so none are `Imported <code>` placeholders. Both panels hold the same 320-user
roster, so the second import added nothing. The created ids are recorded in
`storage/logs/imported-employee-ids-2026-09-14.json` so the whole thing can be undone
with one query.

**No attendance was synced, deliberately.** The owner asked for none. Every
attendance read above went through the driver directly and persisted nothing — no
`attendance_logs`, no `attendance_raw_logs`, no watermark moved. Note that C3 devices
are excluded from the hourly automatic sync anyway (`routes/console.php:53` filters
on `binary`), and **no cron entry runs `schedule:run` on this box at all**, so
nothing syncs unless somebody presses the button.

Tests: **257 passing**.

### 2026-09-14 — C3/PullSDK driver BUILT; both dead panels now read through the app

Follows directly from the entry below. The owner said build it, so the protocol
went into the library and a third driver went into the app, in rule 8's order.

**Library — `Promoseven\Biometric\C3`, version 2.3.0.** A second protocol family
beside the standalone one, sharing no frame, checksum, session model or constant
with it. `Frame` (CRC-16/ARC), `Command`, `C3TcpTransport`, `BufferedReader` (the
`0x0D`/`0x0E`/`0x0F` staged read), `Catalogue`, `RecordCodec`, `Timestamp`,
`C3Capabilities`, and per-model classes under `C3\Model`. The package name stopped
being aspirational: it now speaks two biometric protocols rather than one.

**App — `App\Services\Devices\C3\C3Driver`**, the third implementation of
`BiometricDeviceDriver`. Adding it touched the driver manager, the `Device`
protocol constant, the three request rules and the device form. **No service, no
job, no controller, no route** — rule 8's claim held for a second time.

**Working, end to end, through the app's own driver:**

| | inBIO460 `.61` | TF1700 `.60` |
|---|---|---|
| records | 87,756 in 12.8 s | 21,005 in 4.0 s |
| with a person | 86,512 | 5,795 |
| door-only events | 1,244 | 15,210 |
| timestamps decoded | all | all |

**The finding that nearly shipped a silent bug.** The first driver run read the
inBIO460 perfectly and returned **zero records** for the TF1700. A controlled
comparison — four connect sequences against both panels, one variable at a time —
showed the TF1700 **defaults to session framing** and needs `CONNECT_SESSION_LESS`
(`0x01`) to leave it. Without that, every reply carries four extra session bytes
(catalogue 1180 B against 1176 B), so a session-less reader is shifted four bytes
on every payload and `GET_DATA` answers a well-formed `0xC8` with ten useless
bytes. **It does not error. It reports zero records.** A panel holding 21,005
punches would have looked reachable, healthy and empty, and an operator would have
read that as "nobody clocked in". Only the control read caught it. The inBIO460 is
the exact opposite and refuses `0x01` with −98 — which is why the two models have
separate classes rather than a shared file with a flag (rule 15).

**Tests: 257 passing**, 21 of them new and none needing a device. Two fixtures are
real captures chosen because they carry no personal data (a staging header, a table
catalogue); the record stream is synthetic with real structure, because a real one
is full of card numbers (rule 16). Three tests exist purely to keep the capability
levels honest: an unread panel confirms nothing, a read panel confirms only what
was exercised, and no write is ever offered — including the door relay.

**Rule 15 pushed back and improved the design.** `DeviceModelIsolationTest` rejected
model names as string literals in the factory and the capability class. The fix was
to move each model's name match AND its capability set into the file named for that
model, leaving the factory holding class references and no model name at all.

**Debt now formally due.** This is the THIRD driver importing `Capability`,
`CapabilitySet` and `DeviceException` from the ZKTeco package. Rule 8 said that
shared vocabulary must move to a vendor-neutral package "before a third vendor
arrives". It has arrived. Moving it was deliberately not bundled into the same
change as making two dead panels readable; it is recorded in
`ProtocolLibraryBoundaryTest` as the next structural job.

**Not done, and each is a deliberate stop:**

- **No device row has been switched to protocol `c3`.** Both panels hold years of
  history — the inBIO460 back to 2021 — which would land in attendance on the first
  sync. That is the owner's call, not a code change. **Owed to Ghassan.**
- **Every write is unbuilt**: enrollment, `SetDeviceData`, and the door relay
  (`0x05`). All `not_implemented`, so the UI hides them. Rule 16 wants explicit
  authorisation and a terminal out of service before any is probed.
- **No realtime, and no incremental read** — there is no cursor, so a sync transfers
  the whole table every time.
- **The roster has never been read**; `USER_READ` stays `implemented`, not
  `confirmed`.

### 2026-09-14 — Glass Door (.60) is a C3 panel, not standalone; C3 buffered read SOLVED from the vendor SDK

The owner supplied a zip of their own licensed **ZKAccess 3.5.5** installation
(`draft/ZKTimeNet.UI.Interfaces.zip`, 82 MB) and asked that it be used to understand
the device protocols. It contained far more than DLLs: both native SDKs side by side
in `NewSDK/` (`plcommpro.dll` = PullSDK, `zkemkeeper.dll` = standalone), the managed
assemblies **with their `.pdb` symbol files**, the live 53 MB `Access.mdb`, and the
vendor's log.

**First action: `/draft` was added to `.gitignore`.** It was a tracked path, and that
`.mdb` holds real employee names, card numbers and fingerprint templates. Analysis
notes live in `draft/analysis/` on this machine only. Rule 16: decoded structure is
committed, the capture is not.

**Finding 1 — `.60` was misdiagnosed, twice over.** The vendor's `Machines` table has
a `DevSDKType` column: F18 and F22 are `2`, **inBIO460 and TF1700 are `1`**. The
selection is four IL instructions in `MachinesManager.dll` — `_SDKType == 2` builds a
COM client of `zkemkeeper`, otherwise a P/Invoke client of `plcommpro`. Our existing
C3 probe was pointed at `.60` and read its identity on the first attempt. The
previous entry here — "comm/SDK service switched off, menu action owed to Ghassan" —
was wrong; **nothing is owed and nothing needs touching on the device.**

**Finding 2 — the `0x0D` buffered pull, open since 2026-09-14 morning, is closed.**
Recovered from `plcommpro.dll` disassembly (dispatch `0x10002be0`, pull loop
`0x100029b0`) and cross-checked against the managed layer: `0x0D` PREPARE_DATA →
`0x0E` DATA → `0x0F` FREE_DATA, 17-byte header, request is a bare 4-byte LE offset,
reply echoes it, CRC-32/ISO-HDLC over the assembled bytes. Implemented in
`tools/zkprobe/inbio460.php` and **both panels read end to end with the checksum
matching** — 87,756 rows off `.61` and 21,005 off `.60`.

Verified three independent ways, not one: the device's own CRC-32; row counts
against the vendor DB (87,738 / 20,931, the deltas being events since the vendor
last synced); and the first TF1700 record decoding to `2025-08-10 17:28:11`, which is
**exactly** the earliest TF1700 row in `Access.mdb`. Control per rule 16: the F18 at
`.62` was sent identical C3 frames and refused them, breaking the pipe.

**Finding 3 — a per-model difference inside one family.** The TF1700 **accepts**
CONNECT; the inBIO460 **refuses both forms** and must run session-less. Rule 15: that
is a selection at construction, never a branch in a read loop.

**Finding 4 — `-4991` is named.** The vendor error dictionaries are not in the DLLs
but in `Languages/language_en-US.xml`. `-4991` = "Operation database has no
corresponding data" — so the F22's long-unexplained `4991` reply is a **semantic
refusal about that table's contents, not a transport failure**, consistent with our
per-table reading. `docs/protocol-overview.md` updated; our fallback is unchanged.

**Finding 5 — timezone, confirmed the hard way.** `acc_monitor_log.time` is bare
device-local datetime with no conversion anywhere, and `Machines.TZAdj` is still the
factory default `8` on a UTC+3 site. Evidence: the vendor log records a thread
starting `09:06:23.586` and stopping `09:06:25.772` by the PC clock, and the F22's
last two events are stamped `09:06:24`, inside that window. Rule 10 confirmed on the
vendor's own data.

Also recovered and recorded for later: the literal PullSDK **write** vocabulary from
`devcmds.CmdContent` (`update|user$`, `update|templatev10$`, `update|userauthorize$`)
with field lists and an enforced teardown order; that the two families use
**different template tables** (`templatev10` on the panels, `pers_biotemplate` on
F18/F22); and that the vendor's own `acc_monitor_log` ids are not stable across their
backups (12,289 vanished, 963 reused, 54 events silently lost), so their ids must
never be used as a watermark.

Docs updated: `docs/device-capabilities.md` (TF1700 section rewritten, verification
table corrected, full `0x0D` layout), `docs/protocol-overview.md` (family scope note,
`4991` partly resolved, C3 pointer).

**Not built, and deliberately so:** no C3 driver, no capability set, no UI, no
writes. ~108,761 punches sit on those two panels that the app still cannot read.

### 2026-09-14 — 524 on Camelot read root-caused: sync ran inline + no worker

**Uncommitted, on branch `camelot`.** (The systemd worker service IS installed on
this box — see below.)

The app returned **HTTP 524** (front-proxy gateway timeout) when reading Camelot.
Two causes, both now fixed:

1. **`DeviceController::syncAttendance` ran the read INLINE** in the web request. A
   full Camelot read is ~285 s; the proxy times out (~100 s) long before it
   finishes → 524. It now **queues** a `SYNC_ATTENDANCE` command via
   `DeviceCommandBus` and returns at once (rule 11 — a device read is not a
   web-request-shaped operation). The devices screen already polls `sync-progress`;
   `Devices.jsx` now keeps the row busy on the queued response and finishes it when
   the run reaches a terminal status. NOTE the terminal statuses are
   `succeeded`/`partial`/`failed`, NOT `completed` — the first cut of the frontend
   checked `completed` and would have spun forever; fixed.

2. **No queue worker ran**, so even correctly-queued commands never executed —
   app-wide, not just attendance. Installed `zkt-queue.service` (systemd,
   `queue:work database` as `www-data`, enabled + `Restart=always`). Verified: a
   queued F18 sync ran to `succeeded` (read 10,525, imported 21).

Net effect: the app no longer 524s on a slow read, and Camelot goes through the same
queued path — the worker's row-level retries+backoff (rule 11) absorb its session
flakiness over time, which is the only thing that makes an unreliable public device
usable. Full suite 236 green; frontend rebuilt.

### 2026-09-14 — TCP transport added (library 2.2.0); Camelot still flaky

**Uncommitted, on branch `camelot`.** `tools/` still untracked.

The user reported Camelot still failing to read fully. UDP windowed-resume (2.1.0)
completes it but only ~half of attempts — Camelot is a flaky public device. TCP was
the recorded real fix (a 2026-09-13 note: an F22 read whole over TCP where UDP could
not), so a `TcpTransport` was built.

Found and fixed a real bug in the process: `Packet::wrapTcp` wrote the TCP frame's
second magic word as **zero**, and a device rejects that outright (closes the
connection, no reply) — so the TCP path had never actually worked. Both words are
required (`0x5050`, `0x7d82`). After the fix, TCP read an **F22 whole (8,162
records)** — the transport is correct.

**On Camelot, TCP is not yet proven.** Across many attempts (both transports) it
failed at `CONNECT`, `AUTH`, and `PREPARE_BUFFER` at different times — the device is
unreliable at session setup on a congested public link, and this session's heavy
testing degraded it further. TCP got through `CONNECT`+`AUTH` but staging timed out.
So the **default transport stays `udp`** (proven to complete Camelot); TCP is opt-in
(`attendance.device_transport` = `tcp`/`auto`) to try on a rested, unloaded Camelot.

Shipped: `TcpTransport`, `DeviceConfig::$transport`/`usesTcp()`, transport selection
in `DeviceClient`/`SessionManager`, app auto-select (`ZktecoDriver::transportFor`,
public IP → TCP under `auto`), `attendance.device_transport`. Eight new library tests
(`TcpFrameTest`, `TcpTransportTest`). Full suite 236 green. Docs: library
`CHANGELOG.md` (2.2.0), `library-api-reference.md`, this file.

**The honest bottom line for Camelot:** a complete read is possible and proven over
UDP; per-attempt reliability is a property of the device, not the code, and belongs
to the queue's retry+backoff (rule 11). Let Camelot rest before the next test.

### 2026-09-14 — Camelot bulk read FIXED (windowed resume); library 2.1.0

**Uncommitted, on branch `camelot`.** Not committed; `tools/` still untracked.

A live full read from Camelot was captured on the wire (tcpdump, 2,069 packets +
kernel counters at 5 Hz — the raw pcap stays in the scratchpad, PII; the structural
summary is `tools/zkprobe/traces-2026-09-14/camelot-fullread-diagnosis-01.json`).
It proved the failure was **not** the receive buffer (`RcvbufErrors` delta 0, rx-queue
0) and **not** corruption — the device streamed ~490 KB then a window went silent,
and the reader discarded the whole transfer and restarted from zero, so it never
finished a 1.66 MB table.

Fixed in the library (`BulkReader`), recorded as **2.1.0** (`CHANGELOG.md`): a stalled
window is re-requested at the same offset; if that is spent — a stall wedges the whole
staged session on this firmware — the session is reopened with a backoff (Camelot
refuses an immediate reconnect), re-staged, and resumed from the offset. A
`READ_BUFFER` timeout counts as a stall; a `FREE_DATA` timeout no longer fails a
completed read. New `DeviceConfig::$bulkWindowRetries` (default 4, env
`DEVICE_WINDOW_RETRIES`); `0` restores the old behaviour.

**Result: 41,632 records in 285 s — the first complete Camelot read.** F18 LAN
re-confirmed unaffected (10,512 records, 13 s — rule 15). Six new `WindowedReadTest`
cases; full suite 228→ green. Also the first CONFIRMED proof the **F18 supports
windowed reads**.

Operational note: a complete read is proven possible but not yet reliable per single
attempt — Camelot refuses a new session for a variable few seconds after one closes,
so any of a read's ~4 reconnects (or the initial one) can time out on `CONNECT`.
Reconnect retries were raised 4→6 to improve the odds; reliability comes from the
queue's per-command retry+backoff (rule 11), and full Camelot reads must be queued,
not run on the synchronous controller path (a clean read ~285 s ≈ its 300 s limit).

Docs updated (rule 12): library `CHANGELOG.md`/`README.md`; app
`protocol-overview.md`, `attendance-sync-flow.md`, `error-handling.md`,
`library-api-reference.md`, `change-log-or-roadmap.md`.

### 2026-09-14 — TF1700 on .60 identified (standalone family); comm service off

**Uncommitted, on branch `camelot`**, like everything below it.

Owner asked to add a TF1700 device, research it, and test the unit at
`192.168.7.60` (the "Glass Door" that was recorded as permanently silent).

**Finding.** The TF1700 is a **standalone binary terminal in the F18 family**, not a
C3/inBIO panel — the `.60` telnet banner (`ZEM560` Linux-MIPS) confirms it, and it
corrects the earlier weak "shares the inBIO OUI → probably inBIO" guess (that OUI is
ZKTeco's company-wide one). So it needs **no new driver and no new library** — it
routes through the existing `ZktecoDriver` exactly like F19/F16/K40, per rules 13/15
(nothing about it behaves differently from the F18, and there's no evidence it does
until it answers).

**The unit at `.60` cannot be read: its comm/SDK service is not serving.** Port scan:
only 23 (telnet) and 4370 open. UDP 4370 closed (ICMP unreachable). TCP 4370 accepts
but answers nothing — to CONNECT, AUTH, VERSION or garbage — while the **F18 control
on `.62` read full identity over the identical code**. The F18 also *closes* the
socket on garbage; `.60` never reacts at all — the signature of a TCP port the kernel
accepts but no application services. **Owed to Ghassan: enable PC-communication / SDK
on the terminal menu (and confirm 4370 isn't NAT-forwarded elsewhere).** Then it
works with no code change.

**Shipped this session:**
- `TF1700` added to the add-device dropdown under standalone binary (status
  `family, untested`), with a comment pointing at the finding.
- `docs/device-capabilities.md`: TF1700 matrix row + a full "TF1700 on .60" finding
  (banner, port scan, wire results, the F18 control, the diagnosis).
- `docs/PROJECT-STATE.md`: terminal-table row 8 rewritten, the wrong inBIO inference
  corrected, this entry.
- **New probe `tools/zkprobe/tf1700.php`** — READ-ONLY, standalone protocol over TCP,
  with a built-in F18 control run. Traces in `traces-2026-09-14/tf1700-*.json`.
  Like the rest of `tools/`, **still untracked** (see housekeeping).

### 2026-09-14 — Honest model matrix + auto-detect on add

**Uncommitted, on branch `camelot`**, like everything below it.

Owner pushed back on the model dropdown: "do you really have all these protocols and
do all operations?" Correct to push back — the dropdown implied five protocols. There
are **two families**: standalone binary (F-/K-series) and C3/PullSDK (inBIO/C3).
Within a family the protocol is the same; models differ only at the edges.

Verified read-only through the app's own driver, 2026-09-14, against the three
devices we physically have:

- **F18** @ .62 — info, users (18), attendance (10,510): all clean.
- **F22** @ .63 — info, users (11), attendance (8,162): all clean, same binary driver.
- **inBIO460** @ .61 — binary `info()` **times out** (it is a C3 panel, not a
  terminal). Only the standalone `tools/zkprobe/inbio460.php` probe reaches it.

F19/F16/K40 are the same binary family (pyzk tests F18/K40; the ZK spec came off an
F19) but we have no such hardware, so they stay listed as "same protocol, untested".
The C3 variants stay listed as "untested". Nothing is claimed that was not seen.

Two changes shipped:

1. **The model dropdown now tells the truth.** Each option carries its real status —
   tested / same-protocol-untested / reads-only / untested — instead of a bare name.
   Untested models stay in the list so the choice exists when the device arrives.
2. **Auto-detect on add.** New `POST /api/devices/identify` reads a device's identity
   before it is saved (`DeviceHealthService::identify()`, no persistence) and selects
   the matching model ("F18/ID" -> F18). Works for the binary family; an inBIO/C3
   panel times out and the API says so honestly. **C3 auto-detect needs a C3 driver,
   which does not exist** — same blocker as reading inBIO data at all.

The full matrix and the endpoint are documented in `docs/device-capabilities.md`.
Still open, unchanged: the inBIO `0x0D` buffered-pull, and there is no C3 driver in
the app (only the probe tool). Both owed by whoever picks up device work.

### 2026-09-14 — inBIO460 on .61 cracked; CLAUDE.md rule 17 added

**Uncommitted, on branch `camelot`**, like everything below it.

Owner asked to test `192.168.7.61` (model inBIO460): research first, connect and
read if possible, reverse-engineer in the worst case — and make the approach a rule.
Both happened.

**The finding.** `.61` was recorded here as "pings but protocol silent" and assumed
dead. It is not: an **inBIO460 is a C3 / PullSDK access panel**, not a standalone
terminal, and speaks a different protocol from the F18 — TCP, different frame,
CRC-16/ARC. Fifteen minutes reading two open-source reimplementations
(`vwout/zkaccess-c3-py` and `-lua`) gave the exact wire format. Speaking it, the
"silent" panel answered on the first try:

- Identity, all parameters, and the **full data-table schema** read cleanly.
- Connect is *refused* (0x76 → −13, 0x01 → −98) and turns out to be unnecessary —
  commands answer session-less. Reading the refusal replies, whose CRCs were all
  valid, is what revealed that; treating the refusal as "device down" is the trap
  the previous diagnosis fell into.
- `GETDATA` answers `0x0D` — a buffered-size + pull-token handshake (7,708 bytes
  staged for the user table), not `0xC8` inline rows as the references assume. The
  buffered pull that fetches the actual rows is **the next step and is not built.**

Corrected the wrong 2026-09-13 diagnosis of `.61` in the operational-facts section
(rule 14: correct, don't append). `.60` (Glass Door) shares the inBIO MAC OUI and
should be tried with the same C3 approach before assuming a fault.

**Tooling:** `tools/zkprobe/inbio460.php` (new, READ-ONLY, C3 protocol; CRC
transcribed literally from the reference) and its traces in `traces-2026-09-14/`.
The door-relay command (0x05 CONTROL) is deliberately NOT implemented — it is a
write and rule 16 forbids it without the owner's go-ahead and a panel out of
service. **`tools/` is still untracked in its entirety** (see housekeeping).

**Rule 17 added to CLAUDE.md — "Research before you reverse-engineer."** The whole
episode is its worked example: identify the model, find the protocol in public
sources, transcribe rather than invent, try the known thing and *read the refusal*,
and reverse-engineer from zero only the narrow gap research did not cover. When a
real inBIO integration is built it is a **new driver implementing the contract**
(rule 8, second vendor-shaped protocol), in **model-named files** (rule 15).

### 2026-09-14 — CLAUDE.md rule 16: learning a device on the wire

**Uncommitted, on branch `camelot`**, like everything below it.

Owner's instruction, and the deliberate fallback for a model with no SDK and no
documentation: speak the known protocol at it, vary one thing at a time, then try
the unknown — and **read the failures**, because a refusal carries the reply code,
the size the device thinks it has and how far it got. This is not new practice; it
is what actually solved Camelot's handshake on 2026-09-13 and found the F22's
per-table windowed reads. Rule 16 writes the method down so the next person does not
reinvent it, and fences the two parts that can hurt:

- **Read-only until the owner says otherwise.** A write probe needs an explicit
  go-ahead and a terminal out of service, because a destroyed enrollment can only be
  recollected with the employee standing at the device.
- **Never commit raw captures.** Capturing the vendor's own app talking to a
  terminal is the single highest-value source and the capture contains names, card
  numbers and fingerprint templates. Decoded structure and a redacted fixture go in
  the repo; the `.pcap` does not.

A control run against the F18 in the same session is required for any claim — that
is what the `control-itroom-*` traces already are — and nothing graduates into the
library on one lucky run: reproducible, documented with hardware and date, fixture
and test, then a model-named file per rule 15, then `implemented`, then `confirmed`
only after it succeeds against the real terminal.

`tests/Unit/ProbeDisciplineTest.php` checks the mechanical part: every probe script
declares `READ-ONLY` or `WRITES TO THE DEVICE` in its header, every trace carries
`label`/`host`/`started_at`, and no `.pcap` is tracked. Verified by injecting an
undeclared script; it fails. It cannot check that a control was run.

**Correction made to this file while writing it:** the "Tooling, deliberately placed
where it survives" note above was wrong. `tools/` is untracked in its entirety — the
probe apparatus and all 43 traces live on this box only.

### 2026-09-14 — CLAUDE.md rule 15: one model per file

**Uncommitted, on branch `camelot`**, like everything below it.

Owner's instruction, taken as a standing rule rather than a one-off: the **F18 is
the only model working with no open issues**, and no other model is to be absorbed
into its code path. A model that needs different behaviour gets its own file named
for it — `packages/zkteco-protocol/src/<Layer>/<Model>/…` for protocol differences,
`app/Services/Devices/<Vendor>/` for another protocol entirely, as `Csl/` already
is — and the choice is made once, where the driver is built.

The reasoning worth keeping: the F22 already needs windowed reads where the F18
does not, Camelot needs an AUTH exchange the LAN F18s do not, and each of those is
one `if` away from living inside the working read path. Three of those and the one
device that works becomes the device nothing can be safely changed on.

`tests/Unit/DeviceModelIsolationTest.php` enforces the half that can be: a model
name may appear in a **comment** anywhere — that is how firmware findings get
recorded, and rule 12 asks for it — but not in executable code outside a file named
for that model. Verified both ways: an injected `if ($model === 'F22')` fails it, a
`CONFIRMED on a ZKTeco F18` comment does not. Rules are never renumbered now, since
code comments and `docs/` cite them by number; new ones are appended.

Not enforced, and deliberately: that the F18 was actually re-read after a shared
file changed.

### 2026-09-14 — Sync history can be cleared

**Uncommitted, on branch `camelot`, in this machine's working tree**, like
everything below it.

`DELETE /api/device-sync-runs` plus a danger button on `/app/device-sync-runs`.
It clears the same `device_id`/`status` filter the listing takes, so the screen
erases exactly what it is showing.

Three decisions worth keeping:

- **Gated on `devices.delete`, not `devices.sync`** (new `DevicePolicy::clearHistory`,
  class-level because there is no device instance). An operator who may run a sync
  should not be able to destroy the record of every sync before it. `operator` has
  sync and not delete, so this is a real distinction in the seeded roles.
- **The raw payload archive goes with the runs.** `attendance_raw_logs` is what made
  the 34% recovery possible, so the confirm dialog names that loss instead of saying
  "cannot be undone", and the toast reports the actual payload count deleted.
- **Runs still `running` are never deleted**, or `SyncProgressRecorder` and the
  progress poller end up writing to a row that is gone.

Imported punches and `devices.attendance_watermark` are untouched — the watermark
lives on the device row, so clearing history cannot make the next sync skip
records. `tests/Feature/Api/SyncHistoryClearTest.php` asserts all of that; every
clear writes a `syncrun.cleared` audit row, by hand, because a mass delete fires no
model events.

### 2026-09-14 — PDF exports, a Reports screen, and a date-filter timezone bug

**All of this is uncommitted, on branch `camelot`, in this machine's working tree.**

**1. Attendance rows open the employee behind the punch.** Clicking a row (or a
mobile card) opens `PunchEmployeeModal`: the punch that was clicked, the employee
fetched from `/api/employees/{id}`, their enrollment drawn with the roster's own
cells, and their last 8 punches. Punches with a null `employee_id` stay inert —
there is no record to open.

**2. Three PDF exports** (`ExportController`, `resources/views/pdf/`): the punch
log as filtered, one employee's attendance record, and the payroll summary. Added
`barryvdh/laravel-dompdf`. Rendered synchronously — these are read-only queries,
unlike device work. The punch log caps at 5,000 rows and prints a warning naming
the true total rather than truncating silently.

Each export re-applies the permissions of the screen it mirrors, and each is
computed by the SAME class the screen reads (`PunchLogQuery`,
`AttendanceSummaryService`, `PayrollReport`). A printout that disagrees with the
screen is the copy that gets filed, so there is deliberately no second code path.

**3. A Reports screen** (`/reports`, permission `reports.view`). The
`/api/reports/attendance` endpoint had existed since 2026-09-02 with nothing in
the SPA reaching it.

**4. A real bug found on the way: attendance date filters were shifted.**
`from`/`to` were compared as bare `YYYY-MM-DD` strings against the UTC
`punched_at` column, so with `ATTENDANCE_TIMEZONE=+03:00`:

- `from=2026-09-01` dropped every punch between 00:00 and 03:00 local on the 1st
- `to=2026-09-14` meant midnight, so it dropped **the whole of the 14th**

Both bounds now convert through `config('attendance.timezone')` in
`PunchLogQuery`, the same zone `AttendanceSummaryService` already used — so the
punch list, a summary and an export finally agree on what a date means. Covered by
`tests/Feature/Api/ExportTest::test_a_one_day_range_covers_that_whole_local_day`.
This is rule 10's trap in its filtering form and worth watching for elsewhere.

**5. Composer was unusable on this box, and fixing it moved the library.**
`composer require` of anything failed: the path repository `../../biometric-protocol`
resolves to `/var/biometric-protocol`, which did not exist. The vendor directory was
copied there first (so nothing could be lost), then composer installed normally and
replaced `vendor/promoseven/biometric-protocol` with a symlink to it. The library is
now at the path the layout always assumed — but it is still not a git repository and
still has no remote, so the blockers at the top of this file stand.

`php artisan test`: 206 passed.

### 2026-09-13 — Camelot (device 9) handshake solved; bulk read investigation paused

Stopped at the owner's request, mid-investigation.

Camelot had never completed a protocol session. Cause: it answers `CONNECT` with
`ACK_UNAUTH` and needs the `CMD_AUTH` step with comm key 0. Confirmed working over
UDP and TCP. The network path is fine (4.4 ms, no loss) — this was never a
connectivity problem, and the stored "timed out" error was misleading.

Its attendance table is 1,664,804 bytes, far larger than anything else in the
fleet, and the bulk read still fails. Two mechanisms identified from instrumented
traces, neither of them the receive buffer (`RcvbufErrors` zero throughout): the
device's send pacing degrades ~5x over the course of a session, and an aborted read
leaves it streaming to a closed port (`NoPorts` 682 in one run) so the next attempt
finds it busy.

Three agents (reader / network observer / control) were launched to test pacing,
session reset, flush duration and a second remote device, and were stopped before
producing results. See *Where we stopped* for the full experiment list, which is
written to be picked up as-is.

Harness and traces preserved at `tools/zkprobe/`.

### 2026-09-13 — "Incomplete transfer" FIXED with windowed reads; React #310 on device detail

Two unrelated faults, both fixed and verified.

**1. `/app/devices/6` crashed with React error #310.** `DeviceDetail.jsx` called
`useSyncProgress` *below* two early returns, so the first render (loading skeleton)
ran fewer hooks than the render after the fetch resolved. Moved the call above the
returns. Rebuilt assets (`node_modules` was absent; `npm install` then
`npm run build`). Swept the rest of `resources/js` per-function for the same
pattern — this was the only instance.

**2. Attendance sync on device 6 (F22, 192.168.7.63) now works.** Superseded the
earlier "the fix is TCP" conclusion — see *Where we stopped*. The library now
negotiates the protocol's windowed read (`CMD_PREPARE_BUFFER` / `CMD_READ_BUFFER`,
16 KB windows) per read and falls back to the push envelope when a device will not
stage a table. 0 of 3 → 6 of 6; full sync read 8,153 records, 0 undecodable.

The earlier buffer diagnosis was wrong, and the measurement that settles it is
cheap to repeat: `/proc/net/snmp` counted **3** `RcvbufErrors` across a read that
lost ~145,000 bytes. The kernel was not dropping anything; the device stopped
sending.

Also from the same investigation, both measured: the read timeout was being
re-applied with a syscall per datagram, and reassembly built a `Reply` object per
datagram. Removing both took capture from 67-69% to 100% in isolation
(`Contracts\SupportsRawDrain`). They are no longer decisive now that the windowed
path exists, but they still apply to every fallback read.

**The TCP findings are preserved, not discarded** — TCP remains a good idea for
devices that refuse to stage a table, and the confirmed framing is in the entry
below.

**Caveat that outranks both fixes:** the library change is in git-ignored
`vendor/`. It is not in any repository. See the blockers at the top of this file.

### 2026-09-13 — "Incomplete transfer" root-caused to UDP; TCP proven to fix it

Third report of the same failure on `192.168.7.63`. Root cause is the transport,
not the reader: the library is UDP-only and the transfer has no flow control. A
full read over TCP returns all 326,084 bytes in 0.03 s, 3 of 3 runs, 8,152 records
decoding clean. Full evidence and the implementation sketch under "Where we
stopped". The earlier 2026-09-13 sysctl conclusion is superseded.

Not implemented — the library source is not on this machine. No code was changed.

### 2026-09-13 — Entrance and Glass Door found never-commissioned; no queue worker

Separate investigation from the buffer one below, triggered by
`could not reach the device ... 192.168.7.60:4370 Glass Door did not answer`.

Not a code fault and unrelated to the `Incomplete transfer` issue. Both new
terminals ping and are genuine ZKTeco, but neither has ever answered the SDK on
4370 by any transport. Full evidence under operational facts above. Also found: no
queue worker configured on this machine. No code was changed.

### 2026-09-13 — "Incomplete transfer" on 192.168.7.63 diagnosed (NOT fixed)

Full attendance re-reads on `192.168.7.63` fail with
`Incomplete transfer: got <varies> of 326084 bytes` — observed at 179,652 / 183,296 /
184,320 / 185,344 / 218,564 / 250,880 across runs.

**Cause: the socket receive buffer, and nothing in the code.** Ruled out, with
measurements:

- Not the progress listener. It fails with no listener attached, and
  `canReportFreely` already evaluates to FALSE here, so `BulkReader` is in its safe
  coarse-reporting mode.
- Not drain speed. The loop reads 180 datagrams in **16 ms** (~11.6 MB/s), roughly
  30x faster than needed. The tail is already discarded by the kernel before PHP's
  first read — the device blasts the whole table with no flow control.
- Not a mis-set config. `attendance.device_receive_buffer` is 4 MB and reaches
  `DeviceConfig` correctly.
- The clamp: `rmem_max` 212992 -> `getsockopt` reports 425984 (Linux doubles it) ->
  the library correctly halves that to 212992 usable. Real capacity is lower still,
  ~183 KB, because each ~1029-byte datagram is charged ~2.4 KB of `skb` truesize.

**SUPERSEDED the same day** by the TCP finding — the sysctl was the wrong fix.
Kept here for the measurements, which stand. No app or library code was changed.

Newest first. Absolute dates only — never "yesterday" or "last week".

### 2026-09-02 — crash fixes, device attribution, library extraction, product pivot

**Started from** "run the project", which surfaced two live SPA crashes recorded in
`storage/logs/client-*.log` by the `/api/client-errors` endpoint.

**Crash 1 — `error is not defined`.** `EmployeeDetailModal.jsx` referenced an
undeclared `error` variable, left over from before rule 2 replaced inline banners
with toasts. Threw for any employee never read from a terminal. Fixed by removing
the dead condition.

**Crash 2 — `Objects are not valid as a React child`.** `error` is cast to `array`
on `AttendanceSyncRun` and `DeviceCommand`, so it reaches the browser as
`{exception, context}`. Six screens rendered it directly, which blanked the page
via the ErrorBoundary and made `/app/device-sync-runs` unopenable whenever any run
had failed. Fixed with `formatDeviceError()` / `<DeviceError>` in
`pages/devices/statusParts.jsx`, plus a guard test
(`test_structured_device_errors_are_never_rendered_as_a_react_child`) because six
files shipped the same mistake at once.

**Per-terminal enrollment attribution.** `employees.fingerprint_count`,
`fingerprint_slots`, `card_number` and `enrollment_synced_at` are a flat snapshot of
whichever terminal was read last — unattributable with two terminals. Added
`Employee::deviceMaps()`, exposed it as `enrollments` on `EmployeeResource`, added an
"Enrolled on" column, and scoped the detail modal to the selected device.

This closed a real hazard: the modal previously showed the last-read snapshot while
its `Delete` and `Remove from terminal` buttons addressed the *selected* device, so
an operator could read IT Room's slots `[1,6]` with Good Wheels selected and delete
against a terminal holding `[6,7]`.

A follow-up correction the same day: the roster cells still read the flat columns,
so every Good-Wheels-only employee showed `—` for fingerprints and card despite
having real slots in `employee_device_maps`. Replaced with `rosterEnrollment()`,
which sources glyph, count, card and label from the same most-recent row.
`syncedAgo()` had the same blind spot and reported "last read July" while a
terminal had been read that morning.

**Library extraction.** Renamed `Zkteco\Protocol` → `Promoseven\Biometric` and
`promoseven/zkteco-protocol` → `promoseven/biometric-protocol`: 253 replacements
across 83 files, leftover scan clean. Extracted to its own repo, added licence,
headers, README, `.gitattributes` (tests `export-ignore`d out of dist archives),
tagged `v1.0.0`. App repointed at it via path repository; `phpunit.xml` and
`ProtocolLibraryBoundaryTest` now resolve the library through
`vendor/promoseven/biometric-protocol`, so the boundary rules check what a licensee
actually receives.

**Corrections made to existing docs.** CLAUDE.md rule 8 claimed exactly one class in
`app/` may import the library; the enforcing test's allowlist has ~24 entries. Rule 8
now distinguishes *speaking* the protocol (only `ZktecoDriver`) from *naming* its
types (documented allowlist). The same stale claim was corrected in `README.md` and
`docs/change-log-or-roadmap.md`.

**Verification:** 178 tests passing, production build clean, every affected screen
driven in a real browser, client error log empty afterwards.

**Not committed.** All app-side changes are left in the working tree deliberately,
on top of a large pre-existing refactor, for review as one set.

---

## Operational facts a new machine needs

### Running it

```bash
php artisan serve --host=0.0.0.0 --port=8010     # or start-server.bat
npm run dev                                       # Vite on 5173
```

- `.env` has `APP_ENV=production`, `APP_URL=http://192.168.0.39`, and **since
  2026-09-21 `DB_CONNECTION=sqlsrv`** — the app no longer runs on SQLite. It points
  at `NewAttendance` on **`192.168.7.8:1433`** (instance `SVRP7H-Tally\SQLEXPRESS`,
  SQL Server 2022 **Express**, login `sa`). The database name has **no space**; with
  one, login fails with error 4060. Full detail, including the five ways SQL Server
  is stricter than SQLite and how to build `pdo_sqlsrv`, is in
  **`docs/database-sql-server.md`** — read it before touching migrations.
- The old SQLite file is still on disk at `database/database.sqlite`, readable
  through the `sqlite_legacy` connection, and `php artisan legacy:sqlite-copy`
  re-copies it (safely re-runnable). Keep both until SQL Server has a proven backup.
- That server also hosts the customer's other databases — `HRMSLive`/`HRMSTest`
  (509 tables, the HRMS proper), `zkbiotime` (a ZKBioTime install), `ZKAttendance`,
  and the empty shells `Attendance` and `HRMSAttendance`. **Do not write to any of
  them**; only `NewAttendance` belongs to this app.
- `QUEUE_CONNECTION=database`. A worker now runs as the systemd service
  **`zkt-queue.service`** (`systemctl status zkt-queue`) — `php artisan queue:work
  database` as `www-data`, enabled at boot, added 2026-09-14. Every device action is
  queued (rule 11), so this must stay up; if queued commands stop running, check it
  first (`journalctl -u zkt-queue`). Remove with
  `systemctl disable --now zkt-queue` + delete `/etc/systemd/system/zkt-queue.service`.
- Stopping Vite deletes `public/hot`, after which the app serves the built assets
  from `public/build`. Both states are valid; rebuild with `npx vite build`.

### The terminals

Device **row ids changed** when the estate was re-seeded; the ids below are current as
of 2026-09-15. Roster and punch figures are from the post-wipe re-read that day.

| # | Terminal | Address | Model | Roster | Punches re-read | Adoption | State |
|---|---|---|---|---|---|---|---|
| 2 | IT Room | `192.168.7.62:4370` | F18 | 18 | 10,540 | `read_only` — **3 undecided** | LAN. Reads clean, windowed, 100%. The reference path (rule 15). |
| 5 | Good Wheels | `46.184.163.106:4370` | F18 family | 18 | 11,126 | **`adopted`** | **Public IP.** Reads fine. Entirely separate staff — **zero name overlap** with the rest of the estate. |
| 11 | On The Road | `192.168.7.63:4370` | **F22** | 11 | 8,172 | `read_only` — **3 undecided** | LAN. Push reads fail; **windowed reads fixed it**. |
| 12 | Camelot | `87.237.196.60:4370` | F18 | 24 | 13,110 | **`adopted`** | **Public IP.** Needs AUTH, key 0. Slow: **exceeds the default worker timeout**, and the stale-lock trap below follows from that. 7,242 records archived undecodable. |
| 13 | **"Glass Door"** ⚠ | `192.168.7.61:4370` | **inBIO460** | 320 | 87,349 | `read_only` — **85 undecided** | **WORKING** through `C3Driver`, protocol `c3`. Session-less over TCP. Busiest terminal in the estate. **Reads only — `saveUser()`/`deleteUser()` throw.** ⚠ **The NAME is wrong** — see below. |
| 14 | **"Entrance"** ⚠ | `192.168.7.60:4370` | **TF1700** | 320 | 20,967 | **`adopted`** | **WORKING** through `C3Driver`, protocol `c3`. A C3 panel, NOT standalone. **Requires `0x01`**; without it it reports zero records without erroring. **Reads only.** ⚠ **The NAME is wrong** — see below. |
| 17 | Catering | `192.168.7.120:4370` | **UA300/ID** (ZMM200_TFT, Ver 6.60 May 14 2018) | 21 | 2,115 (2026-10-26) | claimed by import 2026-10-25 | Added 2026-10-22. LAN. **Reads only** until its write test (DC-032): `Model\UA300` (2.17.0) offers no write, so it is hidden from Access levels and Push. Its clock write applies LATER than the read-back. |

**Entrance and Glass Door hold IDENTICAL rosters** — 320 entries, 259 distinct names,
the same people. They are the two C3 panels at the same site. Adopting one does not
adopt the other: 85 of Glass Door's entries are same-name duplicates that name matching
cannot separate, and they wait on a human.

**A PIN means nothing across terminals.** Sixteen PINs resolve to different people on
different devices — PIN `1` is "akram" on IT Room, Entrance and Glass Door, "HABIB UR
RAHMAN" on Good Wheels and "Ghassan" on Camelot. Never join on it.

**Camelot's stale lock.** Its sync outlives the default worker timeout. The
`WithoutOverlapping` lock then survives the dead job, and every requeue returns in
~11 ms without contacting the device while the command row sits at `running` — which
looks exactly like a device fault and is not. Clear
`cache_locks WHERE key LIKE '%device:12'`, reset the row, redispatch, and give the
worker `--timeout=900`. Do **not** pass `--tries=1`.

**⚠ Device rows 13 and 14 still carry each other's NAME.** Their `model` and
`protocol` were also transposed and were corrected on 2026-09-14 after reading
identity off the wire, but the names were left alone because they are operator-facing
labels and renaming them is the owner's call. As it stands, **"Glass Door" is the
four-door entrance panel and "Entrance" is the glass door.** The vendor's own
software calls `.61` "P7H ENTRANCE" and `.60` "GLASS_ENTRANCE", which is the
opposite of this app — so the transposition is real, not a naming preference.
**Owed to Ghassan:** swap them, or confirm they are deliberate.

**All five reachable terminals now answer.** Two protocol families: the F18, F22 and
Camelot speak the ZKTeco **standalone** protocol; Entrance and Glass Door speak
**C3/PullSDK**. A model name does not tell you which — the TF1700 is sold as a
standalone fingerprint terminal and is not one.

Comm key is `0` on every standalone terminal that answers, including both public
ones. Note that "comm key 0" is not the same as "no auth": Camelot **demands the
AUTH exchange be performed** with key 0 and refuses the session otherwise, which is
exactly what made it look unreachable for so long.

**Unresolved security issue, now worse than recorded.** *Two* terminals are
reachable from the internet — Good Wheels and Camelot — and both accept comm key
`0`. Anyone who can reach those ports can read the staff list, card numbers and
attendance, and can delete enrollments. Camelot additionally answers on **TCP**
4370. Put both behind a VPN and close the port forwards. Raised 2026-09-02 for
Good Wheels; Camelot added 2026-09-13. Still not fixed.

### Entrance (.61) and Glass Door (.60) are BOTH C3/PullSDK panels — reads SOLVED

**Two earlier diagnoses in this file were wrong and are corrected here** (rule 14:
correct, don't append).

`.61` was never silent — it was being spoken to in the wrong protocol. An
**inBIO460 is a 4-door access-control PANEL**, speaking **C3 / PullSDK** over TCP
with a CRC-16/ARC frame. Solved 2026-09-14 by researching the model before probing
(rule 17).

**`.60` was the same mistake made twice.** This file previously said the TF1700's
comm/SDK service was switched off and owed Ghassan a menu action on the device.
**That was wrong. Nothing is owed and nothing needs touching.** The TF1700 also
speaks C3/PullSDK; it answered our existing C3 probe on the first attempt. The
telnet `ZEM560` banner is real but says nothing about which protocol the comm
service serves, and that inference is what produced the wrong answer.

Confirmed 2026-09-14, all READ-ONLY, traces in
`tools/zkprobe/traces-2026-09-14/inbio-*.json`:

| | Entrance `.61` | Glass Door `.60` |
|---|---|---|
| Model | inBIO460 | TF1700 |
| Serial | `AK2J183860073` | `ESK5242000009` |
| Firmware | `AC Ver 5.2.4 Apr 19 2016` | `Ver 6.64.0013 Oct 16 2018` |
| MAC | `00:17:61:12:34:9B` | `00:17:61:10:96:FD` |
| CONNECT `0x76`/`0x01` | **refused** (−13 / −98), runs session-less | **accepted** |
| Doors / readers | 4 / 4 | 1 / 1 |
| `transaction` pulled | 1,717,298 B, 337 chunks, 87,756 rows | 367,768 B, 72 chunks, 21,005 rows |
| CRC-32 | `3ee6a1f9` verified | `031eaee7` verified |
| Range (device local) | 2000-10-04 .. 2026-09-14 13:04 | 2025-08-10 17:28 .. 2026-09-14 14:46 |

**The `0x0D` buffered read is solved.** `GETDATA` stages its answer instead of
returning it: `0x0D` PREPARE_DATA → `0x0E` DATA (repeated) → `0x0F` FREE_DATA, with
a 17-byte header carrying a **CRC-32 that makes the whole read self-verifying**.
Recovered from the owner's own licensed ZKAccess 3.5.5 install (see the session log)
and implemented in `tools/zkprobe/inbio460.php`. Full byte layout in
`docs/device-capabilities.md`. No public C3 reimplementation has this path.

**What this does NOT mean.** There is still **no app driver for C3**, no capability
set, and no UI. Every write — enrollment, `SetDeviceData`, the door relay `0x05` —
is unbuilt and unprobed, and per rule 16 needs the owner's explicit go-ahead and a
terminal out of service first. The honest status is: the protocol is solved, the
integration is not built. Roughly **108,761 punches** sit on those two panels that
this app cannot yet read.

### Queue worker — FIXED 2026-09-14 (`zkt-queue.service`)

Was: no worker, no systemd unit, so every queued `DeviceCommand` sat unprocessed
and any UI action that queued one hung. Now a systemd service `zkt-queue.service`
runs `php artisan queue:work database` as `www-data`, enabled at boot and
`Restart=always`. Verified end to end: a queued F18 attendance sync ran to
`succeeded` (read 10,525, imported 21).

This unblocked the whole queued subsystem — device-detail syncs, group commands,
employee pushes — not just attendance. If queued commands stop, check the service
before the code.

### Local environment gotchas

- `devsense.php.ls.exe` (the VS Code PHP language server) holds directory handles
  and blocks renames and deletes under `packages/`. Close the editor first.
- **`packages/zkteco-protocol` is a stale leftover copy** of the library from before
  the extraction. Composer no longer references it. Delete it
  (`rm -rf packages/zkteco-protocol`) once the editor is closed, or it will drift
  and confuse you.

- **This app runs inside an UNPRIVILEGED LXC container, and that caps the UDP
  receive buffer.** `/proc/sys` is mounted read-only, so `net.core.rmem_max` is
  stuck at the 212992 default and cannot be raised from inside. `SO_RCVBUFFORCE`
  is refused too (CAP_NET_ADMIN is held only in the container's own user
  namespace). The library asks for 4 MB and the kernel clamps it. This is real and
  worth knowing, but it is NO LONGER the blocker for bulk reads — see the TCP
  finding under "Where we stopped". Raising the sysctl on the Proxmox host is now
  a fallback, not the plan.
- **`/etc/sysctl.d/99-zkteco-udp.conf` inside the container is INERT.** It sets
  `net.core.rmem_max = 16777216` and looks like the fix is in place. It is not, and
  it never can be — `sysctl -p` on it returns `Invalid argument`. Always check the
  RUNNING value (`sysctl net.core.rmem_max`), never the file. The fix belongs on the
  Proxmox host, not here.

---

## Housekeeping debt

- **The test suite runs on in-memory SQLite while production is SQL Server**
  (`phpunit.xml`). All 334 tests pass, but they exercise a different database from the
  one that serves users, so not one of the five incompatibilities found on 2026-09-21
  could have been caught by CI — every one was found by hand. A SQL Server test job is
  the obvious gap.
- **`NewAttendance` has never been backed up.** The instance takes a nightly full
  backup of every other database at 23:51, but this one was created after the
  2026-09-20 run. **Confirm it appears in `msdb.dbo.backupset` after the next run** —
  if the job names its databases explicitly rather than enumerating them, it will be
  skipped silently forever. The database is in `SIMPLE` recovery, so there is no log
  chain and no point-in-time restore.
- **Rollbacks do not work on SQL Server.** Several migration `down()` methods drop a
  column while an index still depends on it, which SQL Server refuses. Recovery is
  restore-from-backup, not `migrate:rollback`.
- Stale `packages/zkteco-protocol` directory, above.
- **The library is UDP-only, and that is the root of "Incomplete transfer".**
  See the TCP finding under "Where we stopped" — this is now a known fix awaiting
  implementation, not open debt.
- `employee_device_maps` binds device users to *this app's* `employees` table. In the
  product that mapping must belong to the caller — the single biggest change still
  needed for the middleware model.
- Library test coverage is `TemplateCodec` and `BulkReader` only. Transport,
  sessions and the four services are exercised in production, not by unit tests.
- `composer audit` reports 12 advisories affecting 2 packages. Not reviewed.
- `docs/` was written when this repo was the product. Several files still read as
  though the payroll app is the deliverable; they need a pass under the middleware
  framing.
- **`tools/` is untracked in its entirety** — `Probe.php`, `harness.php`,
  `experiment.php` and 43 traces. Rule 16 requires traces to be committed; they are
  the evidence for the pacing, flush and windowed-read findings in this file.
- The library's one fixture, `tests/Fixtures/fingerprint-template-table.bin`, is an
  F18 capture but is not named for the model it came off. Rule 15 asks for that from
  now on; renaming this one means a library version bump, so it is left as is until
  a second model's capture lands beside it.
