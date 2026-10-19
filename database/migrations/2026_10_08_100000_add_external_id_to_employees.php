<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The caller's own key for an employee (2026-10-08, integration API).
 *
 * An outside system (HRMS, ERP, payroll) knows people by ITS id, not ours. Storing it
 * lets it upsert idempotently — `PUT /api/v1/employees/by-external-id/{id}` — and match
 * attendance back to its own records without keeping a translation table.
 *
 * Nullable, because every employee that exists today has none. Unique per tenant WHEN
 * SET: SQL Server allows only ONE NULL in an ordinary unique index
 * (docs/database-sql-server.md), so there it must be a filtered index. SQLite and
 * MySQL allow many NULLs and take the plain one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('external_id', 128)->nullable();
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX employees_tenant_external_id_unique ON employees (tenant_id, external_id) WHERE external_id IS NOT NULL');
        } else {
            Schema::table('employees', fn (Blueprint $table) => $table->unique(['tenant_id', 'external_id'], 'employees_tenant_external_id_unique'));
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex('employees_tenant_external_id_unique');
            $table->dropColumn('external_id');
        });
    }
};
