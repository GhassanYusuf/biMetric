<?php

namespace Tests\Feature\Devices;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Person;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The staff import. National ids were removed from the system on 2026-09-27, so a
 * `national_id` column is ignored and the import no longer creates People.
 */
class StaffImportTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        Tenant::create(['name' => 'Globex', 'slug' => 'globex']);
        Tenancy::clear();

        $this->path = tempnam(sys_get_temp_dir(), 'staff').'.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_the_same_payroll_code_at_two_companies_is_two_different_people(): void
    {
        // Both companies number from 001. This collided outright while employee_code was
        // globally unique — the second tenant could not be onboarded at all.
        $this->csv(<<<'CSV'
        Name,Employee Code,National ID,Tenant
        Ali Jaafar,001,860101123,acme
        Nadia Hassan,001,900215456,globex
        CSV);

        $this->artisan('staff:import', ['file' => $this->path])->assertSuccessful();

        $this->assertSame(2, Employee::withoutGlobalScopes()->where('employee_code', '001')->count());
    }

    public function test_company_and_department_build_the_department_tree(): void
    {
        // "Finance" at two companies is two departments; "finance" in another row of
        // the same company is the same one.
        $this->csv(<<<'CSV'
        Name,Employee Code,Company,Department,Tenant
        Ali Jaafar,E-001,PROMOSEVEN,Finance,acme
        Nadia Hassan,E-002,PROMOSEVEN,finance,acme
        Tariq Omar,E-003,AVANA,Finance,acme
        Sara Ali,E-004,AVANA,,acme
        CSV);

        $this->artisan('staff:import', ['file' => $this->path])->assertSuccessful();

        $departments = Department::withoutGlobalScopes()->get();
        $promoseven = $departments->firstWhere('name', 'PROMOSEVEN');
        $avana = $departments->firstWhere('name', 'AVANA');

        $this->assertNull($promoseven->parent_id);
        $this->assertNull($avana->parent_id);
        $this->assertCount(4, $departments);

        $employees = Employee::withoutGlobalScopes()->get()->keyBy('employee_code');
        $this->assertSame($employees['E-001']->department_id, $employees['E-002']->department_id);
        $this->assertSame($promoseven->id, Department::withoutGlobalScopes()->find($employees['E-001']->department_id)->parent_id);
        $this->assertSame($avana->id, Department::withoutGlobalScopes()->find($employees['E-003']->department_id)->parent_id);
        // A company with no department places the employee on the company itself.
        $this->assertSame($avana->id, $employees['E-004']->department_id);
        $this->assertSame('Finance', $employees['E-001']->department);
        $this->assertSame('AVANA', $employees['E-004']->department);
    }

    public function test_a_row_without_company_or_department_keeps_its_department(): void
    {
        $this->csv(<<<'CSV'
        Name,Employee Code,Company,Tenant
        Ali Jaafar,E-001,PROMOSEVEN,acme
        CSV);
        $this->artisan('staff:import', ['file' => $this->path])->assertSuccessful();
        $assigned = Employee::withoutGlobalScopes()->firstOrFail()->department_id;

        $this->csv(<<<'CSV'
        Name,Employee Code,Tenant
        Ali Jaafar,E-001,acme
        CSV);
        $this->artisan('staff:import', ['file' => $this->path])->assertSuccessful();

        $this->assertNotNull($assigned);
        $this->assertSame($assigned, Employee::withoutGlobalScopes()->firstOrFail()->department_id);
    }

    public function test_last_name_is_imported_and_an_empty_cell_keeps_it(): void
    {
        $this->csv(<<<'CSV'
        Name,Last Name,Employee Code,Tenant
        Ali,Mohamed Jaafar,E-001,acme
        CSV);
        $this->artisan('staff:import', ['file' => $this->path])->assertSuccessful();
        $this->assertSame('Mohamed Jaafar', Employee::withoutGlobalScopes()->firstOrFail()->last_name);

        $this->csv(<<<'CSV'
        Name,Last Name,Employee Code,Tenant
        Ali,,E-001,acme
        CSV);
        $this->artisan('staff:import', ['file' => $this->path])->assertSuccessful();
        $this->assertSame('Mohamed Jaafar', Employee::withoutGlobalScopes()->firstOrFail()->last_name);
    }

    public function test_a_national_id_column_is_ignored(): void
    {
        $this->csv(<<<'CSV'
        Name,Employee Code,National ID,Tenant
        Tariq Omar,E-003,860101123,acme
        CSV);

        $this->artisan('staff:import', ['file' => $this->path])->assertSuccessful();

        $employee = Employee::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('Tariq Omar', $employee->name);
        $this->assertNull($employee->person_id);
        $this->assertSame(0, Person::count());
    }

    public function test_a_reimport_does_not_detach_an_existing_person(): void
    {
        $this->csv(<<<'CSV'
        Name,Employee Code,Tenant
        Ali Jaafar,E-001,acme
        CSV);
        $this->artisan('staff:import', ['file' => $this->path])->assertSuccessful();

        $person = Person::create(['full_name' => 'Ali Jaafar', 'status' => Person::UNVERIFIED]);
        Employee::withoutGlobalScopes()->firstOrFail()->forceFill(['person_id' => $person->id])->save();

        $this->artisan('staff:import', ['file' => $this->path])->assertSuccessful();

        $this->assertSame(1, Employee::withoutGlobalScopes()->count());
        $this->assertSame($person->id, Employee::withoutGlobalScopes()->firstOrFail()->person_id);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->csv(<<<'CSV'
        Name,Employee Code,National ID,Tenant
        Ali Jaafar,E-001,860101123,acme
        CSV);

        $this->artisan('staff:import', ['file' => $this->path, '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Employee::withoutGlobalScopes()->count());
        $this->assertSame(0, Person::count());
    }

    private function csv(string $contents): void
    {
        // Heredocs here are indented for readability; the parser must see column headers
        // at the start of the line.
        $lines = array_map('trim', explode("\n", trim($contents)));
        file_put_contents($this->path, implode("\n", $lines)."\n");
    }
}
