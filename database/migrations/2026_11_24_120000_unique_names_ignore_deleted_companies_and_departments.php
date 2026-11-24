<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A deleted company's or department's name can be used again (2026-11-24).
 *
 * The forms always allowed it — StoreCompanyRequest / UpdateCompanyRequest and the department requests check
 * uniqueness `whereNull('deleted_at')` — but the unique indexes counted soft-deleted rows too. So renaming COH to
 * "City One Hotel" a minute after the old "City One Hotel" (#27) was deleted passed validation and then failed in
 * the database with a 500. The indexes now match the rule: unique among rows that are not deleted.
 *
 * Filtered unique indexes: SQL Server and SQLite both take `... WHERE deleted_at IS NULL`. Same index names.
 */
return new class extends Migration
{
    private const INDEXES = [
        'companies' => ['companies_tenant_id_name_unique', 'tenant_id, name'],
        'departments' => ['departments_tenant_id_company_id_name_unique', 'tenant_id, company_id, name'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => [$name, $columns]) {
            Schema::table($table, fn ($t) => $t->dropUnique($name));
            DB::statement("CREATE UNIQUE INDEX {$name} ON {$table} ({$columns}) WHERE deleted_at IS NULL");
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => [$name, $columns]) {
            Schema::table($table, fn ($t) => $t->dropUnique($name));
            DB::statement("CREATE UNIQUE INDEX {$name} ON {$table} ({$columns})");
        }
    }
};
