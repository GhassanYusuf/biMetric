/*
================================================================================
  Everything about one human, from their national ID (CPR)
  SQL Server 2022 / T-SQL — the NewAttendance database on 192.168.7.8
================================================================================

WHY THERE IS A HASH IN HERE AND NOT A NUMBER
--------------------------------------------------------------------------------
`people.national_id` is ENCRYPTED (Laravel Crypt, AES-256-CBC), so it cannot be
compared in SQL and cannot be decrypted in SQL either. Every lookup goes through
`people.national_id_hash` — a deterministic HMAC-SHA256 over the normalised value,
keyed with APP_KEY. See App\Models\Person::hashNationalId().

SQL Server has no HMAC function, so the hash is computed once, outside, and pasted
in. From the app root:

    php artisan tinker --execute="echo App\Models\Person::hashNationalId('830108246');"

Normalisation is done for you by that call: punctuation and case are stripped, so
'830-108-246', '830 108 246' and '830108246' all produce the same hash.

THE HASH IS THE DOCUMENT
--------------------------------------------------------------------------------
Anyone holding a hash can confirm a guessed CPR by recomputing it. Treat the value
below like the number itself: do not paste it into a ticket, a chat or a commit.

WHY IT ALSO SEARCHES person_national_ids
--------------------------------------------------------------------------------
A renewal (iqama, permit) issues a NEW number for the same human, and the old one
is SUPERSEDED rather than overwritten. A payroll query run against a number issued
four years ago must still find the person, so both tables are searched.

ONE PERSON CAN HOLD SEVERAL EMPLOYMENTS
--------------------------------------------------------------------------------
`people` is deliberately NOT tenant-scoped: the same human employed by two sister
companies is one Person with two rows in `employees`. These queries therefore
return a row PER EMPLOYMENT, each carrying its tenant_id. Add a tenant_id filter
if you mean one company.

TIME
--------------------------------------------------------------------------------
`attendance_logs.punched_at` is stored in UTC. The office runs at a fixed +03:00
(config/attendance.php — an offset, not a DST-observing zone), so every query below
shifts by @tz_offset_hours and reports local time. Change the variable, not the
individual queries.
================================================================================
*/

DECLARE @national_id_hash CHAR(64) = 'PASTE_THE_HASH_HERE';
DECLARE @tz_offset_hours  INT      = 3;      -- config('attendance.timezone') = +03:00
DECLARE @from             DATE     = '2026-09-01';
DECLARE @to               DATE     = '2026-09-30';


/* ---------------------------------------------------------------------------
   The person, and every employment they hold.

   Current national id or a superseded one — both find them.
--------------------------------------------------------------------------- */
WITH matched AS (
    SELECT id AS person_id FROM people WHERE national_id_hash = @national_id_hash
    UNION
    SELECT person_id       FROM person_national_ids WHERE national_id_hash = @national_id_hash
)
SELECT
    p.id                AS person_id,
    p.full_name,
    p.status,                          -- 'verified' once a national id is on file
    p.hrms_person_id,
    e.id                AS employee_id,
    e.tenant_id,
    e.employee_code,                   -- what the TERMINAL knows them as
    e.name              AS employment_name,
    e.department,
    e.is_active,
    e.card_number,
    e.fingerprint_count,
    e.enrollment_synced_at             -- NULL = never read from a device.
                                       -- fingerprint_count means nothing without this.
FROM matched m
JOIN people    p ON p.id = m.person_id
LEFT JOIN employees e ON e.person_id = p.id
ORDER BY e.is_active DESC, e.id;


/* ---------------------------------------------------------------------------
   DAILY IN / OUT — first and last punch of each local day.

   This is the same arithmetic AttendanceSummaryService uses for the screen and
   the payroll report: the day's attendance is its FIRST and LAST punch, and
   nothing in between. `punches = 1` is a missed punch-out, NOT a zero-hour day —
   hours_on_site is left NULL there rather than reporting 0.

   Absent days do not appear here at all. A day with no punch has no row to
   return; the app decides whether that is absence, a holiday or a waiver, and
   this query deliberately does not guess.
--------------------------------------------------------------------------- */
WITH matched AS (
    SELECT id AS person_id FROM people WHERE national_id_hash = @national_id_hash
    UNION
    SELECT person_id       FROM person_national_ids WHERE national_id_hash = @national_id_hash
),
local_punches AS (
    SELECT
        a.employee_id,
        DATEADD(HOUR, @tz_offset_hours, a.punched_at) AS punched_local
    FROM attendance_logs a
    JOIN employees e ON e.id = a.employee_id
    JOIN matched   m ON m.person_id = e.person_id
    WHERE a.punched_at >= DATEADD(HOUR, -@tz_offset_hours, CAST(@from AS DATETIME2))
      AND a.punched_at <  DATEADD(HOUR, -@tz_offset_hours, DATEADD(DAY, 1, CAST(@to AS DATETIME2)))
)
SELECT
    e.employee_code,
    e.name,
    CAST(lp.punched_local AS DATE)               AS work_date,
    CONVERT(CHAR(5), MIN(lp.punched_local), 108) AS first_in,
    CASE WHEN COUNT(*) > 1
         THEN CONVERT(CHAR(5), MAX(lp.punched_local), 108)
    END                                          AS last_out,
    COUNT(*)                                     AS punches,
    CASE WHEN COUNT(*) > 1
         THEN CAST(DATEDIFF(MINUTE, MIN(lp.punched_local), MAX(lp.punched_local)) / 60.0 AS DECIMAL(5, 2))
    END                                          AS hours_on_site
FROM local_punches lp
JOIN employees e ON e.id = lp.employee_id
GROUP BY e.employee_code, e.name, CAST(lp.punched_local AS DATE)
ORDER BY work_date DESC;


/* ---------------------------------------------------------------------------
   EVERY punch, unaggregated — which terminal, and how they verified.

   Use this when the daily view looks wrong: a duplicate minute, a punch on the
   wrong terminal or a device whose clock has drifted is only visible here.

   verify_method: 0 password, 1 fingerprint, 2 card, 15 face. This database also
   contains 3 and 4, which are NOT in the documented set — some firmware numbers
   verify modes differently, so they are left as the raw number rather than
   guessed at (the UI does the same).
--------------------------------------------------------------------------- */
WITH matched AS (
    SELECT id AS person_id FROM people WHERE national_id_hash = @national_id_hash
    UNION
    SELECT person_id       FROM person_national_ids WHERE national_id_hash = @national_id_hash
)
SELECT
    e.employee_code,
    e.name,
    d.name                                        AS device_name,
    d.ip_address,
    a.device_user_id,                             -- the id the TERMINAL used;
                                                  -- differs from employee_code when
                                                  -- somebody was re-enrolled badly
    DATEADD(HOUR, @tz_offset_hours, a.punched_at) AS punched_local,
    a.punched_at                                  AS punched_utc,
    a.verify_method,
    a.status
FROM attendance_logs a
JOIN employees e ON e.id = a.employee_id
JOIN matched   m ON m.person_id = e.person_id
LEFT JOIN devices d ON d.id = a.device_id
WHERE a.punched_at >= DATEADD(HOUR, -@tz_offset_hours, CAST(@from AS DATETIME2))
  AND a.punched_at <  DATEADD(HOUR, -@tz_offset_hours, DATEADD(DAY, 1, CAST(@to AS DATETIME2)))
ORDER BY a.punched_at DESC;
