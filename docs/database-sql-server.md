# The database: SQL Server

This app ran on SQLite until **2026-09-21**, when it moved to **SQL Server 2022
Express** on `192.168.7.8`. This file is what that cost and what a reuser has to
know. If you are pointing this app at a fresh SQL Server, read it before running
`php artisan migrate`.

The short version: SQL Server is stricter than SQLite in five specific ways, and
every one of them was a silent non-problem on SQLite that became a hard failure
here. None of them are exotic. All of them are load-bearing.

---

## Connection

| | |
|---|---|
| Host | `192.168.7.8:1433` |
| Instance | `SVRP7H-Tally\SQLEXPRESS` — SQL Server 2022 (16.0.1000.6) Express, on Windows Server 2019 |
| Database | `NewAttendance` — **no space**; the name with a space fails login with error 4060 |
| Login | `sa` |
| Driver | `pdo_sqlsrv` 5.12.0 against Microsoft ODBC Driver 18 |

`.env` keys: `DB_CONNECTION=sqlsrv`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD`, `DB_ENCRYPT`, `DB_TRUST_SERVER_CERTIFICATE`.

**On the certificate.** ODBC Driver 18 encrypts by default and validates the
server certificate by default. This instance presents a **self-signed**
certificate, so `DB_TRUST_SERVER_CERTIFICATE=true` is set in `.env`: the
connection is still encrypted, but the certificate is not verified, which leaves
it open to an active man-in-the-middle on that network path. `config/database.php`
keeps Laravel's validating default — the trust decision lives in the deployment's
`.env`, not in committed code. To close it properly, export the certificate from
the Windows host into the client trust store and set the flag to `false`.

**It is Express edition**, which caps a database at 10 GB. The copied data is well
under that, but `attendance_logs` and `attendance_raw_logs` grow with every sync,
so this is a real ceiling rather than a theoretical one. See "Housekeeping" below.

### Installing the driver

There is no Debian package; it is built from PECL and needs Microsoft's ODBC
driver first.

```bash
apt-get install -y php8.2-dev php-pear unixodbc-dev g++ build-essential
curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
  | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg
echo "deb [signed-by=/usr/share/keyrings/microsoft-prod.gpg] \
  https://packages.microsoft.com/debian/12/prod bookworm main" \
  > /etc/apt/sources.list.d/mssql-release.list
apt-get update && ACCEPT_EULA=Y apt-get install -y msodbcsql18

# 5.13+ requires PHP 8.3; this host is on PHP 8.2, so 5.12.0 is the right release.
curl -sSLO https://pecl.php.net/get/sqlsrv-5.12.0.tgz
curl -sSLO https://pecl.php.net/get/pdo_sqlsrv-5.12.0.tgz
pecl install ./sqlsrv-5.12.0.tgz && pecl install ./pdo_sqlsrv-5.12.0.tgz
phpenmod sqlsrv pdo_sqlsrv
```

`pecl install sqlsrv` by name fails on this host — `pecl.php.net`'s channel
metadata comes back as malformed XML — which is why the tarballs are fetched
directly. Restart **both** Apache and `zkt-queue.service` afterwards; the queue
worker is a long-lived process and will otherwise keep using the old connection.

---

## The five differences that actually bit

### 1. Multiple cascade paths are forbidden

SQL Server refuses to create a foreign key when deleting one row could reach the
same table by **two** cascade routes, even when no cycle exists. It counts
`ON DELETE SET NULL` as a cascade path too. SQLite and MySQL accept all of it.

Three places in this schema tripped it, and each was resolved by deciding which
cascade is the one that matters:

| Foreign key | Now | Why |
|---|---|---|
| `tenant_id` on every domain table | `NO ACTION` | `tenants` reached `employees` directly and again via `departments`, and `attendance_logs` directly and again via `devices`. Nothing is lost: `Tenant` soft-deletes, so the database cascade never fired. |
| `attendance_sync_runs.device_command_id`, `enrollment_sessions.device_command_id` | `NO ACTION` | `devices` reached both tables directly and again through `device_commands`. The `device_id` cascade is the one that does the work. |
| `attendance_sync_runs.device_id` | `NO ACTION` | Harder: **both** cascades into `attendance_raw_logs` are load-bearing. Pruning sync runs must clear their raw rows (`DeviceSyncController` deletes runs in bulk and relies on it), and deleting a device must clear its own (`device_id` is `NOT NULL`, so `NO ACTION` there would make devices undeletable). So the duplicate path is broken one level up instead. |

That last one leaves a gap, closed in `Device::booted()`: a `deleting` hook
removes the device's sync runs first, letting *their* cascade clear the raw logs.
It is on the model rather than in `DeviceController::destroy` so every deletion
path is covered. Verified on 2026-09-21 by deleting device 2 (2 runs, 10,589
punches) inside a rolled-back transaction.

### 2. `VARCHAR` lengths are enforced

SQLite stores whatever you give it regardless of the declared length. SQL Server
truncates or, with `ANSI_WARNINGS` on, refuses.

`device_groups.pin_namespace_evidence` was declared `string()` (255) but holds
prose; the real row is 268 characters. It is now `text` — see
`2026_09_21_150000_widen_pin_namespace_evidence.php`. Truncation there would have
been silent damage of the worst kind: the flag stays on and the sentence
justifying it loses its end.

**It was the only one.** Every string column was checked against the real data
before the copy, not discovered one failure at a time.

### 3. Strings must be valid UTF-8

The driver translates every string to UCS-2 and raises `An error occurred
translating string for input param N to UCS-2` on bytes that are not valid UTF-8.
SQLite stores any byte sequence in a text column without complaint.

The legacy database held **exactly one** such value: `attendance_logs.device_user_id`
on punch `981687`, bytes `01be072933`, a corrupt id off device 5. Dropping the row
was never an option — silently discarding undecodable attendance is the specific
failure rule 11 exists to prevent. `legacy:sqlite-copy` widens such values
byte-for-byte (`ISO-8859-1` → `UTF-8`), which is lossless and reversible with
`mb_convert_encoding($v, 'ISO-8859-1', 'UTF-8')`, and reports every one it touched.
The round trip was verified: the hex matches the source exactly.

### 4. `CAST` to an integer throws on non-numeric text

SQLite returns `0`. SQL Server raises `Conversion failed when converting the
nvarchar value ... to data type int` and the request 500s.

Two call sites ordered a roster with `orderByRaw('CAST(device_user_id AS INTEGER)')`.
`device_user_id` is a string because a device id is whatever the terminal says it
is, and `attendance_logs` already holds **16,386** non-numeric ones off these same
terminals. The roster table happens to be all-numeric today, so this was a break
waiting for a single junk row — precisely what the F22 and Camelot produce.

Both now call `DeviceRosterEntry::scopeOrderByDeviceUserId()`, which picks
`TRY_CAST(... AS BIGINT)` on sqlsrv and `CAST(... AS INTEGER)` on SQLite. `TRY_CAST`
yields `NULL` rather than throwing, so unparseable ids sort to the top instead of
taking the screen down. **The dialect is decided in one place**; callers just ask
for the roster in order.

### 5. Integers come back as strings

`pdo_sqlsrv` stringifies every numeric column by default. SQLite returned native
ints, so without intervention `"port": 4370` silently became `"port": "4370"` in
every API response, and anything doing a strict comparison downstream changes
behaviour with nothing logged.

`config/database.php` sets `PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE => true`. The
usual objection to that flag is float rounding; this schema has **no** decimal,
money or float column, so there is nothing to round. **Re-check that before adding
one.**

---

## Two more, not hit but worth knowing

- **A unique index allows only ONE NULL.** SQLite and MySQL allow many. Every
  unique index in this schema was checked against the real data before the copy and
  none had more than one NULL — but a nullable unique column will fail here the
  moment two rows have no value. Use a filtered index (`WHERE col IS NOT NULL`) if
  that day comes.
- **A column cannot be dropped while an index depends on it.** This makes several
  `down()` methods fail on SQL Server even though `up()` is fine. Rollbacks are
  therefore not reliable here; recovery is restore-from-backup, not
  `migrate:rollback`.

---

## Moving the data: `legacy:sqlite-copy`

```bash
php artisan legacy:sqlite-copy --dry-run   # report only
php artisan legacy:sqlite-copy             # copy, then verify
```

The old database stays readable through the `sqlite_legacy` connection
(`SQLITE_LEGACY_DATABASE` in `.env`), so this is repeatable rather than a one-shot
script — which matters, because a real cutover needs two runs: one for the bulk
while the old system still serves, one at switchover for what arrived in between.

- **Ids are preserved, not regenerated.** Every foreign key here points at one.
  `SET IDENTITY_INSERT` is toggled per table.
- **Re-running is safe.** Rows already present are skipped by primary key, or by a
  full-row fingerprint for the permission pivots, which have composite keys and no
  single column to test.
- **It verifies rather than trusts** (rules 3 and 10). Foreign keys are disabled for
  the load and re-enabled `WITH CHECK`, which makes the server re-validate every row
  instead of marking the constraint untrusted. Row counts are compared per table and
  the command exits non-zero on any mismatch.
- `migrations`, `cache`, `cache_locks`, `jobs` and `job_batches` are deliberately not
  copied.

**`SET IDENTITY_INSERT` must go through `PDO::exec`, not `$connection->statement()`.**
Laravel sends a `statement()` as a prepared batch (`sp_prepexec`) and SQL Server
scopes the setting to that batch, so it is reverted before the next insert runs and
you get `Cannot insert explicit value for identity column ... when IDENTITY_INSERT
is set to OFF`. This cost an hour; it is not in any error message.

### Result, 2026-09-21

198,062 rows across 27 populated tables — including 151,440 punches and 42,322 raw
logs. Every table matched the source, every foreign key re-validated, identity seeds
land above the copied maxima, and a second run inserted nothing.

---

## Querying by national ID (removed)

**National IDs were removed on 2026-09-27** at the owner's request: the column on the
Employees screen, the form field, the roster filter, the Attendance lookup, the
`PUT /api/employees/{employee}/national-id` and `POST /api/employees/lookup` endpoints,
and in the database `person_national_ids` plus `people.national_id` /
`people.national_id_hash` (migration `2026_09_27_110000_drop_national_ids`).

`docs/sql/national-id-lookup.sql` was deleted with it.

## Housekeeping

- **The test suite still runs on in-memory SQLite** (`phpunit.xml`). All 334 tests
  pass, but they exercise a different database from production, so none of the five
  differences above can be caught by CI. Every one of them was found by hand. A
  SQL Server test job is the obvious gap.
- **Express caps the database at 10 GB.** Nothing prunes `attendance_raw_logs`, which
  keeps a row per record per sync.
- **The SQLite file is retained** at `database/database.sqlite`, plus a dated
  `database/database.pre-sqlsrv-*.sqlite` snapshot taken before the move. Do not
  delete these until the SQL Server database has its own backup schedule.
- **`NewAttendance` has not been backed up yet, but the instance does back up.** A
  scheduled job takes a full backup of every other database nightly at 23:51 (last
  run 2026-09-20). `NewAttendance` was created at 14:54 on 2026-09-21, after that
  run, so it has never been included. **Confirm it appears in `msdb.dbo.backupset`
  after the next nightly run** — if the job names its databases explicitly rather
  than enumerating them, this one will be quietly skipped forever. That check has
  not been done; it cannot be until 2026-09-22.
- The database is **272 MB** on disk and in `SIMPLE` recovery, so there is no log
  chain and point-in-time restore is not available — the nightly full backup is the
  entire recovery story.
