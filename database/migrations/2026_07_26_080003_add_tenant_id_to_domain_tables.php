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
 */
return new class extends Migration
{
    private array $tables = ['employees', 'devices', 'attendance_logs', 'holidays', 'attendance_waivers'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
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
