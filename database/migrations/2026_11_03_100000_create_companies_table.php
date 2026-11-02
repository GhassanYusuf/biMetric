<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A real `companies` table (owner, 2026-11-02, option 2 of 2; plan: docs/plan-companies-table.md).
 *
 * Before: a company was a `departments` row with no `parent_id`, and `employees.department_id`
 * held the department or, when there was none, the company.
 * After:  `companies`; `departments.company_id` (required, no `parent_id`: departments are flat
 *         under their company, owner 2026-11-02); `employees.company_id` + `department_id`
 *         (a department only, in that company).
 *
 * Companies KEEP THEIR IDS (PROMOSEVEN 4, Camelot 19, …), so the v1 API's company_id, the
 * Postman collection and saved queries keep meaning the same company.
 *
 * SAFETY: every employee's (company, department) is recorded before anything moves and
 * compared after. One difference throws, and the migration's transaction (SQL Server and
 * SQLite both run schema changes in one) undoes all of it. A department under a department
 * (none exist, checked 2026-11-02) also refuses, since this layout has no place for it.
 *
 * Foreign keys: SQL Server refuses two cascade routes into one table, and tenants →
 * departments → employees is one already. So companies → tenants and departments →
 * companies are NO ACTION (the app refuses to delete a company that still has departments);
 * employees → companies and companies → employees (manager) are SET NULL, as for departments.
 */
return new class extends Migration
{
    public function up(): void
    {
        $before = $this->snapshotFromTree();

        $deep = DB::table('departments as d')->join('departments as p', 'p.id', '=', 'd.parent_id')->whereNotNull('p.parent_id')->count();
        if ($deep > 0) {
            throw new RuntimeException("{$deep} department(s) sit under another department; companies → departments has no place for them. Nothing was changed.");
        }

        // The view reads the columns that change; it is rebuilt at the end.
        DB::statement('DROP VIEW IF EXISTS v_employee_company');

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('name');
            $table->string('code')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name']);
        });

        // The company rows, with their ids.
        $companies = DB::table('departments')->whereNull('parent_id')->orderBy('id')->get();
        $this->withIdentityInsert('companies', function () use ($companies) {
            foreach ($companies as $c) {
                DB::table('companies')->insert([
                    'id' => $c->id, 'tenant_id' => $c->tenant_id, 'name' => $c->name, 'code' => $c->code,
                    'manager_id' => $c->manager_id, 'created_at' => $c->created_at, 'updated_at' => $c->updated_at,
                    'deleted_at' => $c->deleted_at,
                ]);
            }
        });

        Schema::table('departments', fn (Blueprint $t) => $t->unsignedBigInteger('company_id')->nullable()->after('tenant_id'));
        DB::table('departments')->whereNotNull('parent_id')->update(['company_id' => DB::raw('parent_id')]);

        Schema::table('employees', fn (Blueprint $t) => $t->unsignedBigInteger('company_id')->nullable()->after('department'));
        $companyIds = $companies->pluck('id')->map(fn ($id) => (int) $id)->all();
        // On a company: it becomes their company, and they have no department.
        DB::table('employees')->whereIn('department_id', $companyIds)
            ->update(['company_id' => DB::raw('department_id'), 'department_id' => null]);
        // In a department: their company is the department's.
        foreach (DB::table('departments')->whereNotNull('company_id')->get(['id', 'company_id']) as $d) {
            DB::table('employees')->where('department_id', $d->id)->update(['company_id' => $d->company_id]);
        }

        // The company rows leave `departments` (nothing points at them any more).
        DB::table('departments')->whereIn('id', $companyIds)->delete();

        $this->keepingEmployeeDepartments(function () {
            Schema::table('departments', function (Blueprint $table) {
                $table->dropUnique(['tenant_id', 'parent_id', 'name']);
                $table->dropIndex(['parent_id']);
            });
            Schema::table('departments', function (Blueprint $table) {
                $table->dropColumn('parent_id');
                $table->unsignedBigInteger('company_id')->nullable(false)->change();
            });
            Schema::table('departments', function (Blueprint $table) {
                $table->foreign('company_id')->references('id')->on('companies');
                $table->unique(['tenant_id', 'company_id', 'name']);
            });
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->index('company_id');
        });

        $this->createView(tree: false);

        $after = $this->snapshotFromColumns();
        $this->assertSame($before, $after);
    }

    public function down(): void
    {
        $before = $this->snapshotFromColumns();
        DB::statement('DROP VIEW IF EXISTS v_employee_company');

        $this->keepingEmployeeDepartments(function () {
            Schema::table('departments', function (Blueprint $table) {
                $table->dropUnique(['tenant_id', 'company_id', 'name']);
                $table->dropForeign(['company_id']);
            });
            Schema::table('departments', fn (Blueprint $t) => $t->unsignedBigInteger('parent_id')->nullable()->after('tenant_id'));
        });
        DB::table('departments')->update(['parent_id' => DB::raw('company_id')]);

        $companies = DB::table('companies')->orderBy('id')->get();
        $this->withIdentityInsert('departments', function () use ($companies) {
            foreach ($companies as $c) {
                DB::table('departments')->insert([
                    'id' => $c->id, 'tenant_id' => $c->tenant_id, 'company_id' => $c->id, 'parent_id' => null,
                    'name' => $c->name, 'code' => $c->code, 'manager_id' => $c->manager_id,
                    'created_at' => $c->created_at, 'updated_at' => $c->updated_at, 'deleted_at' => $c->deleted_at,
                ]);
            }
        });

        // Someone with only a company goes back onto the company row.
        DB::table('employees')->whereNull('department_id')->whereNotNull('company_id')
            ->update(['department_id' => DB::raw('company_id')]);

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id']);
        });
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('company_id'));
        $this->keepingEmployeeDepartments(function () {
            Schema::table('departments', fn (Blueprint $t) => $t->dropColumn('company_id'));
            Schema::table('departments', function (Blueprint $table) {
                $table->index('parent_id');
                $table->unique(['tenant_id', 'parent_id', 'name']);
            });
        });
        Schema::drop('companies');

        $this->createView(tree: true);

        $this->assertSame($before, $this->snapshotFromTree());
    }

    /** employee id => "company|department", read from the old tree. */
    private function snapshotFromTree(): array
    {
        $nodes = DB::table('departments')->get(['id', 'parent_id', 'name'])->keyBy('id');
        $out = [];
        foreach (DB::table('employees')->orderBy('id')->get(['id', 'department_id']) as $e) {
            $node = $e->department_id === null ? null : ($nodes[$e->department_id] ?? null);
            $company = $node === null ? null : ($node->parent_id === null ? $node : ($nodes[$node->parent_id] ?? null));
            $department = ($node !== null && $node->parent_id !== null) ? $node : null;
            $out[(int) $e->id] = ($company->name ?? '').'|'.($department->name ?? '');
        }

        return $out;
    }

    /** employee id => "company|department", read from the new columns. */
    private function snapshotFromColumns(): array
    {
        $companies = DB::table('companies')->pluck('name', 'id');
        $departments = DB::table('departments')->get(['id', 'company_id', 'name'])->keyBy('id');
        $out = [];
        foreach (DB::table('employees')->orderBy('id')->get(['id', 'company_id', 'department_id']) as $e) {
            $department = $e->department_id === null ? null : ($departments[$e->department_id] ?? null);
            if ($department !== null && (int) $department->company_id !== (int) $e->company_id) {
                throw new RuntimeException("Employee {$e->id}: department {$department->id} is not in company {$e->company_id}.");
            }
            $out[(int) $e->id] = ($e->company_id === null ? '' : ($companies[$e->company_id] ?? '?')).'|'.($department->name ?? '');
        }

        return $out;
    }

    private function assertSame(array $before, array $after): void
    {
        $diff = [];
        foreach ($before as $id => $where) {
            if (($after[$id] ?? null) !== $where) {
                $diff[] = "employee {$id}: was \"{$where}\", now \"".($after[$id] ?? 'missing').'"';
            }
        }
        if ($diff !== [] || count($before) !== count($after)) {
            throw new RuntimeException('Company/department check FAILED, so the migration is undone. '.count($diff).' difference(s): '.implode('; ', array_slice($diff, 0, 10)));
        }
    }

    /**
     * SQLite changes a column by rebuilding the table, and dropping the old copy fires the
     * employees.department_id ON DELETE SET NULL (foreign keys cannot be switched off inside
     * the migration's transaction). SQL Server alters in place and is unaffected. Either way,
     * every employee's department is put back exactly as it was.
     */
    private function keepingEmployeeDepartments(Closure $alter): void
    {
        $kept = DB::table('employees')->whereNotNull('department_id')->pluck('department_id', 'id');
        $alter();
        foreach ($kept->groupBy(fn ($dept) => (int) $dept, preserveKeys: true) as $dept => $rows) {
            DB::table('employees')->whereIn('id', $rows->keys()->all())->update(['department_id' => $dept]);
        }
    }

    /** SQL Server only lets explicit ids into an identity column inside IDENTITY_INSERT. */
    private function withIdentityInsert(string $table, Closure $insert): void
    {
        $sqlsrv = DB::getDriverName() === 'sqlsrv';
        if ($sqlsrv) {
            DB::unprepared("SET IDENTITY_INSERT [{$table}] ON");
        }
        try {
            $insert();
        } finally {
            if ($sqlsrv) {
                DB::unprepared("SET IDENTITY_INSERT [{$table}] OFF");
            }
        }
    }

    /** v_employee_company keeps its columns, so queries written against it keep working. */
    private function createView(bool $tree): void
    {
        DB::statement('DROP VIEW IF EXISTS v_employee_company');

        if (! $tree) {
            DB::statement('
                CREATE VIEW v_employee_company AS
                SELECT e.tenant_id, e.id AS employee_id, e.employee_code, e.name, e.last_name, e.is_active,
                       e.company_id, co.name AS company, e.department_id, de.name AS department
                FROM employees e
                LEFT JOIN companies co ON co.id = e.company_id
                LEFT JOIN departments de ON de.id = e.department_id
            ');

            return;
        }

        $with = DB::getDriverName() === 'sqlsrv' ? 'WITH' : 'WITH RECURSIVE';
        DB::statement("
            CREATE VIEW v_employee_company AS
            {$with} tree (id, company_id, department_id) AS (
                SELECT d.id, d.id, CAST(NULL AS BIGINT) FROM departments d WHERE d.parent_id IS NULL
                UNION ALL
                SELECT c.id, t.company_id, c.id FROM departments c JOIN tree t ON c.parent_id = t.id
            )
            SELECT e.tenant_id, e.id AS employee_id, e.employee_code, e.name, e.last_name, e.is_active,
                   t.company_id, co.name AS company, t.department_id, de.name AS department
            FROM employees e
            LEFT JOIN tree t ON t.id = e.department_id
            LEFT JOIN departments co ON co.id = t.company_id
            LEFT JOIN departments de ON de.id = t.department_id
        ");
    }
};
