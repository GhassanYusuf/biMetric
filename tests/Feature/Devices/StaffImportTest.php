<?php

namespace Tests\Feature\Devices;

use App\Models\Employee;
use App\Models\Person;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The staff import, and the one thing it has to get right.
 *
 * The same national id appearing twice is NOT a duplicate to reject — it is one human
 * employed by two sister companies. Collapsing those two rows onto one Person is the
 * entire reason the Person layer exists: when they punch at either company's terminal,
 * payroll must see one attendance account.
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

    public function test_one_human_at_two_companies_becomes_one_person_with_two_employments(): void
    {
        $this->csv(<<<'CSV'
        Name,Employee Code,National ID,Tenant
        Ali Jaafar,E-001,860101123,acme
        Ali Jaafar,B-114,860-101-123,globex
        CSV);

        $this->artisan('staff:import', ['file' => $this->path])->assertSuccessful();

        // The two documents differ only in punctuation. Normalising before hashing is
        // what stops one human becoming two people because two clerks typed differently.
        $this->assertSame(1, Person::count());
        $this->assertSame(2, Employee::withoutGlobalScopes()->count());

        $person = Person::firstOrFail();
        $this->assertCount(2, $person->employments()->withoutGlobalScopes()->get());
        $this->assertSame('860101123', $person->nationalId());
        $this->assertTrue($person->isExportable());
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

        $this->assertSame(2, Person::count());
        $this->assertSame(2, Employee::withoutGlobalScopes()->where('employee_code', '001')->count());
    }

    public function test_a_row_without_a_national_id_is_imported_but_not_exportable(): void
    {
        $this->csv(<<<'CSV'
        Name,Employee Code,National ID,Tenant
        Tariq Omar,E-003,,acme
        CSV);

        $this->artisan('staff:import', ['file' => $this->path])->assertSuccessful();

        // Attendance must still collect for them. Only the payroll export waits.
        $employee = Employee::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($employee->person_id);
        $this->assertSame(0, Person::count());
    }

    public function test_a_reimport_that_omits_the_national_id_does_not_detach_the_person(): void
    {
        $this->csv(<<<'CSV'
        Name,Employee Code,National ID,Tenant
        Ali Jaafar,E-001,860101123,acme
        CSV);
        $this->artisan('staff:import', ['file' => $this->path])->assertSuccessful();

        $personId = Employee::withoutGlobalScopes()->firstOrFail()->person_id;
        $this->assertNotNull($personId);

        // A later file from HR that simply does not carry the column must not silently
        // un-identify somebody — that would block their payroll export with no symptom.
        $this->csv(<<<'CSV'
        Name,Employee Code,Tenant
        Ali Jaafar,E-001,acme
        CSV);
        $this->artisan('staff:import', ['file' => $this->path])->assertSuccessful();

        $this->assertSame(1, Employee::withoutGlobalScopes()->count());
        $this->assertSame($personId, Employee::withoutGlobalScopes()->firstOrFail()->person_id);
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
