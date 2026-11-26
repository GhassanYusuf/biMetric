<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Open 24 hours" for a company (owner, 2026-11-26; chose "no fixed hours"): its people are never Late, Left
 * early or Left late; a work day with punches is present, hours first to last punch (across midnight); a work
 * day without one is absent. shift_start / shift_end then only give the length of a normal day — the credit
 * for a holiday or excused day and the minutes of an absence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_attendance_rules', function (Blueprint $table) {
            $table->boolean('no_fixed_hours')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('company_attendance_rules', function (Blueprint $table) {
            $table->dropColumn('no_fixed_hours');
        });
    }
};
