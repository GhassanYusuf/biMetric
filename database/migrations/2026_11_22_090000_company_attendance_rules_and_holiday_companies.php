<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift and holidays per company (owner, 2026-11-22: "specify the shift for each company and holiday";
 * chose full rules per company, and holidays for all or chosen companies).
 *
 *   company_attendance_rules  A company with a row judges its people by its own shift, weekend and grace
 *                             minutes; without one, by the system default (attendance.* settings). One row
 *                             per company.
 *   holiday_company           The companies a holiday applies to. A holiday with no rows applies to every
 *                             company — which is what every existing holiday is.
 *   holidays.date             No longer unique on its own: two companies may keep different holidays on
 *                             one day (and the old index even spanned tenants). The request checks that a
 *                             day never has two holidays for the same company. Indexed with the tenant.
 *
 * No cascade on companies: SQL Server refuses a second cascade path to the same rows (rules → tenants and
 * companies → tenants), and a company is soft-deleted anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_attendance_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->unique()->constrained('companies')->noActionOnDelete();
            $table->string('shift_start', 5);
            $table->string('shift_end', 5);
            $table->string('work_days', 32); // JSON list of Carbon dayOfWeek values, 0 = Sunday
            $table->unsignedSmallInteger('late_grace_minutes')->default(0);
            $table->unsignedSmallInteger('early_leave_grace_minutes')->default(0);
            $table->unsignedSmallInteger('leave_late_after_minutes')->default(0);
            $table->timestamps();
        });

        Schema::create('holiday_company', function (Blueprint $table) {
            $table->foreignId('holiday_id')->constrained('holidays')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->noActionOnDelete();
            $table->primary(['holiday_id', 'company_id']);
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->dropUnique('holidays_date_unique');
            $table->index(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'date']);
            $table->unique('date');
        });
        Schema::dropIfExists('holiday_company');
        Schema::dropIfExists('company_attendance_rules');
    }
};
