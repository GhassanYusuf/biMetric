<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employment type and resignation (2026-10-12, owner's request from HR's email).
 *
 * `employment_type` is a short code rather than an `is_part_time` flag: the owner said
 * part-time changes NO attendance calculation today — it is a label — and a code takes
 * "contract" or "intern" later without another migration. Every existing employee is
 * full-time, which is what the default says.
 *
 * `resigned_at` is a DATE, not a boolean: reports still need the person's attendance up
 * to the day they left. Setting it makes the employee inactive (Employee::booted) and the
 * screens take them off every terminal, through the credential-preserving removal
 * (rule 19). Null = still employed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('employment_type', 16)->default('full_time');
            $table->date('resigned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['employment_type', 'resigned_at']);
        });
    }
};
