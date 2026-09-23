<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A mapping becomes TIME-BOXED, and the per-employee unique goes away.
 *
 * WHY: PIN 5 on a terminal is reassigned when somebody leaves. Every punch before
 * that date belongs to the previous holder. An open-ended mapping silently
 * re-attributes their whole history to the new hire — a payroll error with no symptom.
 *
 * So a mapping is closed (valid_to set) rather than deleted, attendance resolves
 * through the mapping that was valid AT THE PUNCH TIME, and unique(device, employee)
 * is replaced by a partial guarantee: one LIVE mapping per employee per device, and
 * one LIVE mapping per PIN per device. History may hold many of each.
 *
 * Partial unique indexes are raw SQL because the schema builder has no expression for
 * them. SQLite and Postgres both accept this form; MySQL does not, and would need a
 * generated column instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_device_maps', function (Blueprint $table) {
            $table->timestamp('valid_from')->nullable()->after('device_user_id');
            $table->timestamp('valid_to')->nullable()->after('valid_from');
            $table->string('closed_reason')->nullable()->after('valid_to');
        });

        DB::statement('UPDATE employee_device_maps SET valid_from = created_at WHERE valid_from IS NULL');

        Schema::table('employee_device_maps', function (Blueprint $table) {
            $table->dropUnique('employee_device_maps_device_id_employee_id_unique');
            $table->dropUnique('employee_device_maps_device_id_device_user_id_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX employee_device_maps_live_employee_unique
             ON employee_device_maps (device_id, employee_id) WHERE valid_to IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX employee_device_maps_live_pin_unique
             ON employee_device_maps (device_id, device_user_id) WHERE valid_to IS NULL'
        );

        Schema::table('employee_device_maps', function (Blueprint $table) {
            $table->index(['device_id', 'device_user_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS employee_device_maps_live_employee_unique');
        DB::statement('DROP INDEX IF EXISTS employee_device_maps_live_pin_unique');

        Schema::table('employee_device_maps', function (Blueprint $table) {
            $table->dropIndex(['device_id', 'device_user_id', 'valid_from']);
            $table->dropColumn(['valid_from', 'valid_to', 'closed_reason']);
            $table->unique(['device_id', 'employee_id']);
            $table->unique(['device_id', 'device_user_id']);
        });
    }
};
