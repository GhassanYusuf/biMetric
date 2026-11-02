# Plan: a real `companies` table (option 2)

**Status: BUILT AND REHEARSED, NOT DEPLOYED (2026-11-03).** Branch `companies` (`0cc2b7a`,
pushed), worktree `/var/www/zkt-companies`; 795 tests pass, the website builds. Live production
is still `main`, untouched. Next: the cut-over with the owner, at a time the owner picks.

**Rehearsal, 2026-11-03, on SQL Server:** the owner restored this morning's backup as
`NewAttendance_rehearsal` on 192.168.7.8 (DBeaver, `RESTORE … WITH MOVE`); the migration ran
against it from the worktree (env override, database name checked before each run):
- up in 0.23 s, its own check passed; compared independently with the live `NewAttendance`:
  **217 employees, 0 differences** in company/department; 11 companies with their ids, 8
  departments (all company 4), no `parent_id`, foreign keys as designed, Camelot 14,
  free-text `department` consistent for everyone;
- rollback in 0.22 s: `employees.department_id` and the `departments` table identical to
  live again; migrated a second time, same result;
- the new code against it: companies with counts (PROMOSEVEN 117 = 67 on the company + 50 in
  its departments), 12 unassigned, labels "PROMOSEVEN › Information Technology", OrgScope,
  attendance report / payroll / monthly statement by company and department all answer.
`NewAttendance_rehearsal` is left migrated on the server; the owner drops it after the
cut-over (`DROP DATABASE [NewAttendance_rehearsal];`).

## Why

Today a company is a `departments` row with no `parent_id`, and an employee has one column,
`department_id`, which holds their department or, when they have none, their company. The
website hides this; the database and every SQL query do not. The owner wants the database to
say what it means: a company is a company, a department belongs to one, and an employee has
both.

## What exists now (measured 2026-11-02, production)

| | |
|---|---|
| Companies (departments with no parent) | 11 (none soft-deleted) |
| Departments under a company | 8, all under PROMOSEVEN (none soft-deleted) |
| Deeper levels (a department under a department) | **none** |
| Employees | 217: 205 on a company or department, 12 with neither |
| Tables that reference `departments` | only `employees.department_id` (FK) |
| Free-text `employees.department` | still filled; follows `department_id` on change |
| Tenant "Default Company" | a level above all of this; not touched |

Backend code reading the tree today (each changes): `Department` and `Employee` models;
`DepartmentController`, `EmployeeController`, `V1\EmployeeController`, `AttendanceController`,
`ExportController`, `ReportController`, `ReportExportController`, `LiveController`;
`Store/UpdateDepartmentRequest`, `Store/UpdateEmployeeRequest`, `V1\CompanyDepartmentFields`;
`DepartmentResource`, `EmployeeResource`, `V1\EmployeeResource`; `PunchLogQuery`,
`EmployeeRosterQuery`, `EmployeeMergeService`; reports `AttendanceReport`,
`EmployeeReportsExport`, `EmployeeRoster`, `MonthlyStatement`, `PayrollReport`,
`PersonalReport`; `StaffImport`; ZKAccess `ZkaccessImportService`, `ZkaccessMdbReader`,
`ZkaccessSnapshotExportService`; `DatabaseSeeder`. About 30 files.

Website (each changes): the Employees page (`DepartmentTree`, `EmployeeFormModal`,
`Employees`, desktop/mobile, `employeeLabels.orgOf`, filters, detail), the Organisation page
(`Organisation`, desktop/mobile, `parts`, `DepartmentFormModal`), Attendance and Reports
filters, `shared/DepartmentChecklist`, `shared/ReportRangeDialog`,
`shared/useAllEmployeesExport`. About 20 files that really use it.

Tests that build a tree with `parent_id`: about 10 files.

## The target

```
tenants        Default Company (id 1)                         unchanged
companies      id, tenant_id, name, code, manager_id,         NEW
               timestamps, deleted_at
departments    id, tenant_id, company_id (required), name,    company_id NEW, parent_id REMOVED
               code, manager_id, timestamps, deleted_at
employees      company_id (nullable), department_id           company_id NEW;
               (nullable, must be in company_id)              department_id = a department only
```

Rules: a department belongs to exactly one company; an employee's department, when set, is in
their company; an employee may have a company and no department, or neither.

## Decisions (recommended; the owner confirms before work starts)

1. **Companies keep their current ids** (PROMOSEVEN stays 4, Camelot 19, …). The new table is
   filled with `IDENTITY_INSERT` from the company rows. Everything that already says
   `company_id: 4` (the v1 API, the Postman collection, saved queries) keeps working.
2. **Departments are flat under their company** (no department under a department). There are
   none today; the website's tree supports deeper levels, but nothing uses them. Simpler code
   and simpler SQL. If nesting is ever needed, `departments.parent_id` can come back.
3. **The free-text `employees.department` stays for now**, as today. Removing it is a separate
   clean-up once nothing reads it (the ZKAccess import and some labels still do).
4. **Filters change shape.** Everything that takes a tree node `department_id` today
   (attendance, reports, exports, the monthly statement, the roster) takes `company_id`
   and/or `department_id` instead; `none` stays for "no company".
5. **History:** audit rows written before the switch for a company stay under
   `App\Models\Department` with the same id; new changes are audited under `Company`.

## How the switch runs

Production IS this working tree: a saved file is live, and switching branches here changes
production (recorded in PROJECT-STATE). So:

1. **Build it in a separate git worktree** (`git worktree add ../zkt-companies -b companies`),
   tested on SQLite there, so nothing on the live site changes while it is being built.
2. **The data migration** (`…_create_companies_table`), inside one transaction:
   1. snapshot every employee's (company name, department name) from `v_employee_company`;
   2. create `companies`, copy the company rows in with their ids;
   3. add `departments.company_id` = old `parent_id`; add `employees.company_id`;
   4. for each employee: on a company → `company_id` = it, `department_id` = null; on a
      department → `company_id` = its company, `department_id` unchanged;
   5. delete the company rows from `departments`; drop `departments.parent_id`; add the
      foreign keys;
   6. redefine `v_employee_company` over the new columns (same column names, so queries
      written against it keep working);
   7. **compare every employee's (company, department) with the snapshot. Any difference:
      roll the whole migration back and stop.**
   The `down()` reverses it. A rehearsal runs first on a copy of the production data
   (exported departments + employees) in the worktree.
3. **The cut-over, at a quiet time, with the owner:**
   1. full SQL Server backup of `NewAttendance` on 192.168.7.8, taken by the owner from
      DBeaver (auto-commit ON; ONE line, select all, Ctrl+Enter, since DBeaver splits a
      statement at line breaks), then verified:
      ```sql
      BACKUP DATABASE [NewAttendance] TO DISK = N'C:\Program Files\Microsoft SQL Server\MSSQL16.SQLEXPRESS\MSSQL\Backup\NewAttendance-before-companies.bak' WITH COPY_ONLY, CHECKSUM, INIT, STATS = 10;
      RESTORE VERIFYONLY FROM DISK = N'C:\Program Files\Microsoft SQL Server\MSSQL16.SQLEXPRESS\MSSQL\Backup\NewAttendance-before-companies.bak' WITH CHECKSUM;
      ```
      Rehearsed 2026-11-02: 38,561 pages in 5.5 s, "The backup set on file 1 is valid."
      `COPY_ONLY` leaves the server's own nightly backups (about 23:49 every night) alone.
      Plus a JSON snapshot of `departments` and `employees` (id, department_id) in
      `storage/app/`;
   2. merge the branch into `main` here (fast-forward, checked);
   3. `php artisan migrate --force` (the owner runs it; the assistant's session is refused
      production migrations);
   4. `npm run build`, `queue:restart`;
   5. checks: the migration's own comparison passed; Employees page shows Company and
      Department as before; Organisation page lists 11 companies and 8 departments; one
      report and one export by company; the v1 API `GET /employees?company_id=19` returns
      Camelot's 14.
   If anything is wrong: `migrate:rollback`, previous commit, rebuild; the backup is the last
   resort.

## Effort and risk

About 1.5–2 days of work, then about an hour together for the cut-over. The risk is the size
of the change (many screens and reports read the tree), not the data, which is small (11
companies, 8 departments, 217 employees) and checked row by row. The full test suite runs
before the cut-over; the website itself has to be checked by the owner in a browser after it,
since there is no browser automation here.

## Found while planning (2026-11-02)

- **The app logs in to SQL Server as `sa`** (sysadmin). It should have its own login with
  rights on `NewAttendance` only. Separate from this plan; on the housekeeping list.
- **"P7H" exists twice:** a company (id 5, 2 employees) and a department "p7h" under
  PROMOSEVEN (id 21, 5 employees). **Owner, 2026-11-02: they are different; both stay.**

## Not part of this

Company-level fields (address, logo, CR number), removing `employees.department`, renaming the
tenant. Each is small and can follow.
