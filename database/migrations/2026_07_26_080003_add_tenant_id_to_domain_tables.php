<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds tenant ownership to every pre-existing domain table. tenant_id is
 * nullable here so the migration is safe on a populated SQLite database
 * (SQLite only allows ADD COLUMN ... REFERENCES when the default is NULL);
 * existing rows are backfilled to a bootstrap tenant by the seeder / the
 * BackfillDefaultTenant migration that follows.
 *
 * tenant_id is deliberately NO ACTION rather than ON DELETE CASCADE. SQL Server
 * refuses a schema with two cascade paths into the same table, and there are two
 * here: tenants -> employees directly, and tenants -> departments -> employees.
 * Same story for attendance_logs, reachable from tenants both directly and via
 * devices. Nothing is lost by dropping the cascade: Tenant soft-deletes, so the
 * database-level cascade never fired in the first place, and the cascades that
 * do real work (device -> its logs) live on the owning entity and are untouched.
 * Confirmed against SQL Server 2022 Express on 2026-09-21.
 */
return new class extends Migration
{
    private array $tables = ['employees', 'devices', 'attendance_logs', 'holidays', 'attendance_waivers'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->after('id')->constrained();
                $table->index('tenant_id');
            });
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('department')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
        });

        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
