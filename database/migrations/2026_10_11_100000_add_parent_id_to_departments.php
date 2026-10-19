<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Departments become a tree (2026-10-11, owner's request): companies are top-level
 * nodes and real departments sit beneath them, so the Employees page can group by
 * "Company › Department" the way ZKAccess Personnel does.
 *
 * One self-referencing table rather than a separate `companies` table: the existing
 * seven top-level rows (PROMOSEVEN, P7H, AVANA…) already ARE the companies, and every
 * employee's department_id keeps pointing where it did.
 *
 * No foreign key on parent_id. SQL Server refuses ON DELETE SET NULL on a
 * self-reference ("may cause cycles or multiple cascade paths"), and departments are
 * soft-deleted anyway, so a constraint would never fire. A node whose parent is gone
 * is treated as a root by the tree (DepartmentTree.jsx) rather than disappearing.
 *
 * Name uniqueness moves from per-tenant to per-parent: "Finance" under two companies
 * is two departments. SQL Server treats NULLs as equal in a unique index, so two
 * top-level departments still cannot share a name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->after('tenant_id');
            $table->index('parent_id');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'parent_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'parent_id', 'name']);
            $table->unique(['tenant_id', 'name']);
            $table->dropIndex(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
