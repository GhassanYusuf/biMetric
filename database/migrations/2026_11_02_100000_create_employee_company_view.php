<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * v_employee_company: each employee with their company and department as two columns
 * (owner, 2026-11-02, option 1 of 2). Storage is unchanged: employees.department_id holds
 * the department, or the company when there is none, and a company is a departments row
 * with no parent. The view walks the tree to any depth, so SQL can simply say
 * WHERE company = 'Camelot'. Read-only; nothing writes through it.
 *
 * Option 2 (a real `companies` table) replaces this with plain columns; the view can then
 * be redefined over them so queries written against it keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQL Server's CTEs recurse without the RECURSIVE keyword; SQLite and the rest need it.
        $with = DB::getDriverName() === 'sqlsrv' ? 'WITH' : 'WITH RECURSIVE';

        DB::statement("
            CREATE VIEW v_employee_company AS
            {$with} tree (id, company_id, department_id) AS (
                SELECT d.id, d.id, CAST(NULL AS BIGINT)
                FROM departments d
                WHERE d.parent_id IS NULL
                UNION ALL
                SELECT c.id, t.company_id, c.id
                FROM departments c
                JOIN tree t ON c.parent_id = t.id
            )
            SELECT
                e.tenant_id,
                e.id AS employee_id,
                e.employee_code,
                e.name,
                e.last_name,
                e.is_active,
                t.company_id,
                co.name AS company,
                t.department_id,
                de.name AS department
            FROM employees e
            LEFT JOIN tree t ON t.id = e.department_id
            LEFT JOIN departments co ON co.id = t.company_id
            LEFT JOIN departments de ON de.id = t.department_id
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_employee_company');
    }
};
