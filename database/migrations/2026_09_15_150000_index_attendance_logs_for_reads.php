<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The indexes every read path in the app was missing.
 *
 * `attendance_logs` was created with exactly two: `tenant_id`, and the unique punch
 * key `(device_id, device_user_id, punched_at, verify_method)`. Neither helps the
 * two things the UI actually does with this table, and at 151,288 rows that stopped
 * being free.
 *
 * MEASURED on 2026-09-15, this install, before this migration:
 *
 *   GET /api/employees   1,951 ms total — of which ONE query took 1,891 ms
 *
 * That query is the `withCount('attendanceLogs')` on the employee list:
 *
 *   select employees.*,
 *     (select count(*) from attendance_logs
 *       where employees.id = attendance_logs.employee_id
 *         and attendance_logs.tenant_id = ?) as attendance_logs_count
 *   from employees ... limit 25
 *
 * With no index on `employee_id`, the only candidate is `tenant_id` — which on a
 * single-tenant install matches every row — so the planner scans all 151,288 rows
 * ONCE PER EMPLOYEE on the page. Twenty-five employees, twenty-five scans, two
 * seconds, on every single visit to the screen.
 *
 * The three indexes below are the three shapes the app reads in:
 *
 *   (tenant_id, employee_id)  the count above, and every "this employee's punches"
 *   (tenant_id, device_id)    the SAME count on the devices screen — 179 ms of a
 *                             233 ms response. The unique punch key already starts
 *                             with device_id, but the tenant predicate sends the
 *                             planner to the tenant_id index instead, which on one
 *                             tenant selects everything. A composite ends the
 *                             argument.
 *   (tenant_id, punched_at)   the attendance list, the dashboard, the reports —
 *                             all of which filter a date range and order by it
 *   (employee_id, punched_at) one employee's timeline, which is the summary screen
 *                             and the per-employee export
 *
 * Deliberately NOT added: anything on `attendance_raw_logs`. It is written by syncs
 * and read only when replaying a decoder fix, so its two existing indexes are the
 * ones it needs; a third would cost every sync insert to speed up nothing a user
 * waits for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->index(['tenant_id', 'employee_id'], 'attendance_logs_tenant_employee_index');
            $table->index(['tenant_id', 'device_id'], 'attendance_logs_tenant_device_index');
            $table->index(['tenant_id', 'punched_at'], 'attendance_logs_tenant_punched_index');
            $table->index(['employee_id', 'punched_at'], 'attendance_logs_employee_punched_index');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex('attendance_logs_tenant_employee_index');
            $table->dropIndex('attendance_logs_tenant_device_index');
            $table->dropIndex('attendance_logs_tenant_punched_index');
            $table->dropIndex('attendance_logs_employee_punched_index');
        });
    }
};
