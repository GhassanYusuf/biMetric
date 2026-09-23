<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An employee becomes an EMPLOYMENT: this person, at this company.
 *
 * employee_code also stops being globally unique. It is a payroll number issued by
 * one employer, and two sister companies both numbering from 001 is normal, not a
 * conflict. Making it global forced unrelated humans to collide the moment a second
 * tenant was onboarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('person_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        // SQLite cannot drop a unique created inline with the column, so the table is
        // rebuilt. Laravel's schema builder handles that; the index name is the
        // default Laravel generated on create.
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_employee_code_unique');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->unique(['tenant_id', 'employee_code']);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'employee_code']);
            $table->dropConstrainedForeignId('person_id');
            $table->unique('employee_code');
        });
    }
};
