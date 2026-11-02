<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The move from "a company is a department with no parent" to a real `companies` table
 * (2026-11-03, docs/plan-companies-table.md). Rolls back to the old layout, fills it the way
 * production is shaped, runs the migration and its reverse.
 */
class CompaniesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_03_100000_create_companies_table.php';

    private function oldLayout(): array
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]);
        $this->assertTrue(Schema::hasColumn('departments', 'parent_id'));

        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme'])->id;
        $now = now();
        $node = fn (int $id, string $name, ?int $parent) => DB::table('departments')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'parent_id' => $parent, 'name' => $name, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $node(4, 'PROMOSEVEN', null);
        $node(5, 'P7H', null);
        $node(19, 'Camelot', null);
        $node(13, 'Finance', 4);
        $node(21, 'p7h', 4);
        $person = fn (int $id, ?int $dept) => DB::table('employees')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'name' => "E{$id}", 'employee_code' => (string) $id,
            'department_id' => $dept, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $person(1, 4);    // directly on a company
        $person(2, 13);   // in a department
        $person(3, 21);   // the department p7h, not the company P7H
        $person(4, 5);    // the company P7H
        $person(5, 19);
        $person(6, null); // nowhere

        return ['tenant' => $tenant];
    }

    public function test_companies_move_out_with_their_ids_and_everyone_keeps_their_place(): void
    {
        $this->oldLayout();

        Artisan::call('migrate', ['--path' => self::MIGRATION]);

        $this->assertSame([4 => 'PROMOSEVEN', 5 => 'P7H', 19 => 'Camelot'],
            DB::table('companies')->orderBy('id')->pluck('name', 'id')->mapWithKeys(fn ($n, $i) => [(int) $i => $n])->all());
        $this->assertSame([13 => 4, 21 => 4],
            DB::table('departments')->orderBy('id')->pluck('company_id', 'id')->mapWithKeys(fn ($c, $i) => [(int) $i => (int) $c])->all());
        $this->assertFalse(Schema::hasColumn('departments', 'parent_id'));

        $row = fn (int $id) => (array) DB::table('employees')->where('id', $id)->first(['company_id', 'department_id']);
        $this->assertEquals(['company_id' => 4, 'department_id' => null], $row(1));
        $this->assertEquals(['company_id' => 4, 'department_id' => 13], $row(2));
        $this->assertEquals(['company_id' => 4, 'department_id' => 21], $row(3));
        $this->assertEquals(['company_id' => 5, 'department_id' => null], $row(4));
        $this->assertEquals(['company_id' => 19, 'department_id' => null], $row(5));
        $this->assertEquals(['company_id' => null, 'department_id' => null], $row(6));

        $view = DB::table('v_employee_company')->orderBy('employee_id')->get(['employee_id', 'company', 'department'])
            ->map(fn ($r) => [(int) $r->employee_id, $r->company, $r->department])->all();
        $this->assertSame([[1, 'PROMOSEVEN', null], [2, 'PROMOSEVEN', 'Finance'], [3, 'PROMOSEVEN', 'p7h'],
            [4, 'P7H', null], [5, 'Camelot', null], [6, null, null]], $view);

        // A new company does not take an id already in use.
        $next = DB::table('companies')->insertGetId(['tenant_id' => DB::table('tenants')->value('id'), 'name' => 'New', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertGreaterThan(19, $next);
    }

    public function test_the_reverse_puts_the_tree_back_exactly(): void
    {
        $this->oldLayout();
        $tree = fn () => DB::table('employees')->orderBy('id')->pluck('department_id', 'id')->map(fn ($v) => $v === null ? null : (int) $v)->all();
        $before = $tree();

        Artisan::call('migrate', ['--path' => self::MIGRATION]);
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]);

        $this->assertSame($before, $tree());
        $this->assertTrue(Schema::hasColumn('departments', 'parent_id'));
        $this->assertFalse(Schema::hasTable('companies'));
        $this->assertSame(4, (int) DB::table('departments')->where('name', 'Finance')->value('parent_id'));
    }

    public function test_a_department_under_a_department_stops_it_before_anything_changes(): void
    {
        $this->oldLayout();
        DB::table('departments')->insert(['id' => 30, 'tenant_id' => DB::table('tenants')->value('id'), 'parent_id' => 13, 'name' => 'Payroll', 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sit under another department');
        try {
            Artisan::call('migrate', ['--path' => self::MIGRATION]);
        } finally {
            $this->assertFalse(Schema::hasTable('companies'));
            $this->assertTrue(Schema::hasColumn('departments', 'parent_id'));
        }
    }
}
