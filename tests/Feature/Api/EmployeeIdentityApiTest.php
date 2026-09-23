<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Person;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Filtering the roster by identity, and assigning national IDs from the screen.
 *
 * The operational shape this serves: after importing a staff list, nobody has a
 * national ID and therefore nobody is exportable to payroll. `has_national_id=0` is the
 * working queue, and the Employees screen fills it in one field at a time.
 *
 * The security property under test is that the number never comes back out.
 */
class EmployeeIdentityApiTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-password';

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();

        // The tenant must exist BEFORE the seeder runs: roles are created per tenant
        // (team-scoped assignment), so seeding first leaves this tenant with none.
        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);

        $this->seed(RolesAndPermissionsSeeder::class);
        // Permissions are global; the four ROLES are created per tenant, because role
        // assignment is team-scoped.
        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);
        Tenancy::clear();

        Tenancy::set($this->tenant->id);
        $user = User::withoutGlobalScopes()->create([
            'name' => 'Admin',
            'email' => 'admin@acme.test',
            'password' => Hash::make(self::PASSWORD),
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->syncRoles(['tenant-admin']);
        Tenancy::clear();

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'admin@acme.test',
            'password' => self::PASSWORD,
        ])->json('data.token');

        $this->app['auth']->forgetGuards();
    }

    public function test_the_roster_can_be_filtered_to_people_still_missing_a_national_id(): void
    {
        $with = $this->employee('Has One', 'E-1');
        $this->employee('Needs One', 'E-2');
        $this->employee('Also Needs One', 'E-3');

        $this->put("/api/employees/{$with->id}/national-id", ['national_id' => '860101123'])
            ->assertOk();

        $missing = $this->get('/api/employees?has_national_id=0')->assertOk()->json('data');
        $this->assertCount(2, $missing);
        $this->assertSame(['Also Needs One', 'Needs One'], array_column($missing, 'name'));

        $has = $this->get('/api/employees?has_national_id=1')->assertOk()->json('data');
        $this->assertCount(1, $has);
        $this->assertSame('Has One', $has[0]['name']);
    }

    public function test_the_national_id_is_never_returned_in_full(): void
    {
        $employee = $this->employee('Ali Jaafar', 'E-1');

        $this->put("/api/employees/{$employee->id}/national-id", ['national_id' => '860101123'])->assertOk();

        $row = $this->get('/api/employees')->assertOk()->json('data.0');

        // Enough to confirm the right document went in, and not enough to harvest.
        $this->assertTrue($row['has_national_id']);
        $this->assertSame('••••••123', $row['national_id_masked']);
        $this->assertArrayNotHasKey('national_id', $row);

        $body = $this->get("/api/employees/{$employee->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('860101123', $body);
    }

    public function test_the_same_national_id_twice_in_one_company_is_refused(): void
    {
        $first = $this->employee('Ali Jaafar', 'E-1');
        $second = $this->employee('Nadia Hassan', 'E-2');

        $this->put("/api/employees/{$first->id}/national-id", ['national_id' => '860101123'])->assertOk();

        /*
         * One human cannot be employed twice by one employer, so this is a typo — and
         * letting it through would attach both employees to ONE Person, after which
         * editing either rewrites the document for both. It is refused, and the message
         * names the record it clashes with so the operator can tell which is wrong.
         */
        $this->put("/api/employees/{$second->id}/national-id", ['national_id' => '860101123'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(1, Person::count());
        $this->assertNull($second->fresh()->person_id);
    }

    public function test_the_same_national_id_at_a_sister_company_is_the_same_human(): void
    {
        $globex = Tenant::create(['name' => 'Globex', 'slug' => 'globex']);

        $here = $this->employee('Ali Jaafar', 'E-1');

        Tenancy::set($globex->id);
        $there = Employee::create([
            'tenant_id' => $globex->id,
            'name' => 'Ali Jaafar',
            'employee_code' => 'B-114',
            'is_active' => true,
        ]);
        Tenancy::clear();

        $this->put("/api/employees/{$here->id}/national-id", ['national_id' => '860101123'])->assertOk();

        // Assigned directly, because the API is tenant-scoped to the signed-in company.
        // What is under test is the MODEL rule: the same document is the same human.
        $person = Person::findByNationalId('860-101-123');
        $this->assertNotNull($person, 'Punctuation must not create a second person.');

        $there->forceFill(['person_id' => $person->id])->save();

        $this->assertSame(1, Person::count());
        $this->assertCount(2, $person->employments()->withoutGlobalScopes()->get());
    }

    public function test_renewing_a_document_supersedes_the_old_number_rather_than_losing_it(): void
    {
        $employee = $this->employee('Ali Jaafar', 'E-1');

        $this->put("/api/employees/{$employee->id}/national-id", ['national_id' => '860101123'])->assertOk();
        $this->put("/api/employees/{$employee->id}/national-id", ['national_id' => '990909999'])->assertOk();

        $person = $employee->fresh()->person;
        $this->assertSame('990909999', $person->nationalId());

        // Payroll exports produced under the old number must still reconcile years later.
        $this->assertNotNull(Person::findByNationalId('860101123'));
        $this->assertSame($person->id, Person::findByNationalId('860101123')->id);
    }

    public function test_the_roster_can_be_filtered_to_people_on_no_terminal(): void
    {
        $this->employee('Nobody Here', 'E-1');

        $rows = $this->get('/api/employees?unenrolled=1')->assertOk()->json('data');

        // Nobody is mapped to a device, so everyone qualifies — the point of the filter
        // is that "cannot clock in anywhere" is answerable at all.
        $this->assertCount(1, $rows);
        $this->assertSame('Nobody Here', $rows[0]['name']);
    }

    public function test_a_national_id_resolves_to_the_employment_that_holds_it(): void
    {
        $employee = $this->employee('Ali Jaafar', 'E-1');
        $this->employee('Nadia Hassan', 'E-2');

        $this->put("/api/employees/{$employee->id}/national-id", ['national_id' => '830108246'])->assertOk();

        $found = $this->post('/api/employees/lookup', ['national_id' => '830108246'])
            ->assertOk()
            ->json('data');

        $this->assertSame($employee->id, $found['id']);
        $this->assertSame('Ali Jaafar', $found['name']);
    }

    public function test_punctuation_and_case_do_not_have_to_be_typed_the_way_they_were_stored(): void
    {
        $employee = $this->employee('Ali Jaafar', 'E-1');
        $this->put("/api/employees/{$employee->id}/national-id", ['national_id' => '830108246'])->assertOk();

        // The blind index is over the NORMALISED value, so an operator reading off a
        // card that prints the number in groups still finds the person.
        $this->post('/api/employees/lookup', ['national_id' => ' 830-108-246 '])
            ->assertOk()
            ->assertJsonPath('data.id', $employee->id);
    }

    public function test_a_partial_national_id_finds_nobody(): void
    {
        $employee = $this->employee('Ali Jaafar', 'E-1');
        $this->put("/api/employees/{$employee->id}/national-id", ['national_id' => '830108246'])->assertOk();

        /*
         * Not a limitation to work around later: the column is a hash, which has no
         * prefix to match on. A "contains" search would mean storing the document in
         * plaintext, which is the thing this design exists to avoid.
         */
        $this->post('/api/employees/lookup', ['national_id' => '830108'])
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_the_lookup_never_returns_the_number_it_was_given(): void
    {
        $employee = $this->employee('Ali Jaafar', 'E-1');
        $this->put("/api/employees/{$employee->id}/national-id", ['national_id' => '830108246'])->assertOk();

        $response = $this->post('/api/employees/lookup', ['national_id' => '830108246'])->assertOk();

        // The masked tail is the same thing the roster already shows. The whole
        // number, and the hash, must not be in there.
        $body = $response->getContent();
        $this->assertStringNotContainsString('830108246', $body);
        $this->assertStringNotContainsString(Person::hashNationalId('830108246'), $body);
        $response->assertJsonPath('data.national_id_masked', '••••••246');
    }

    public function test_a_national_id_does_not_resolve_across_companies(): void
    {
        $globex = Tenant::create(['name' => 'Globex', 'slug' => 'globex']);

        // One human, employed by the sister company only. Person is not tenant-scoped
        // on purpose, so the ONLY thing keeping this out of Acme's answer is that the
        // lookup resolves through the employments relation.
        Tenancy::set($globex->id);
        $there = Employee::create([
            'tenant_id' => $globex->id,
            'name' => 'Ali Jaafar',
            'employee_code' => 'B-114',
            'is_active' => true,
        ]);
        $person = Person::create(['full_name' => 'Ali Jaafar', 'status' => Person::UNVERIFIED]);
        $person->setNationalId('830108246');
        $there->forceFill(['person_id' => $person->id])->save();
        Tenancy::clear();

        $this->post('/api/employees/lookup', ['national_id' => '830108246'])
            ->assertStatus(404);
    }

    public function test_every_lookup_is_audited_hit_and_miss_and_without_the_number(): void
    {
        $employee = $this->employee('Ali Jaafar', 'E-1');
        $this->put("/api/employees/{$employee->id}/national-id", ['national_id' => '830108246'])->assertOk();

        $this->post('/api/employees/lookup', ['national_id' => '830108246'])->assertOk();
        $this->post('/api/employees/lookup', ['national_id' => '999999999'])->assertStatus(404);

        $rows = AuditLog::withoutGlobalScopes()
            ->where('action', 'employee.national_id_lookup')
            ->orderBy('id')
            ->get();

        // The miss is the one worth keeping: a run of them from one user is what
        // somebody guessing numbers looks like.
        $this->assertCount(2, $rows);
        $this->assertSame($employee->id, $rows[0]->auditable_id);
        $this->assertTrue($rows[0]->changes['matched']);
        $this->assertNull($rows[1]->auditable_id);
        $this->assertFalse($rows[1]->changes['matched']);

        foreach ($rows as $row) {
            $encoded = json_encode($row->changes);
            $this->assertStringNotContainsString('830108246', $encoded);
            $this->assertStringNotContainsString('999999999', $encoded);
            $this->assertStringNotContainsString(Person::hashNationalId('830108246'), $encoded);
        }
    }

    public function test_an_employee_without_roster_access_cannot_resolve_a_national_id(): void
    {
        $employee = $this->employee('Ali Jaafar', 'E-1');
        $this->put("/api/employees/{$employee->id}/national-id", ['national_id' => '830108246'])->assertOk();

        /*
         * The `employee` role can see its own punches. Being able to turn a colleague's
         * ID card into their name and attendance is a different thing entirely, so the
         * endpoint is gated on employees.view rather than on being signed in.
         */
        $token = $this->tokenFor('staff@acme.test', 'employee');

        $this->postJson('/api/employees/lookup', ['national_id' => '830108246'], [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus(403);
    }

    private function tokenFor(string $email, string $role): string
    {
        Tenancy::set($this->tenant->id);
        $user = User::withoutGlobalScopes()->create([
            'name' => $role,
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->syncRoles([$role]);
        Tenancy::clear();

        $token = $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        $this->app['auth']->forgetGuards();

        return $token;
    }

    private function employee(string $name, string $code): Employee
    {
        Tenancy::set($this->tenant->id);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'employee_code' => $code,
            'is_active' => true,
        ]);
        Tenancy::clear();

        return $employee;
    }

    /** Every request in this class is authenticated as the tenant admin. */
    protected function withAuth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function get($uri, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson($uri, $headers + $this->withAuth());
    }

    public function put($uri, array $data = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->putJson($uri, $data, $headers + $this->withAuth());
    }

    public function post($uri, array $data = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($uri, $data, $headers + $this->withAuth());
    }
}
