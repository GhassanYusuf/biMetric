<?php

namespace Tests\Feature\Api;

use App\Models\AccessLevel;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Personnel-dialog fields on the employee form (2026-09-23).
 *
 * What is under test is that they are STORED and VALIDATED, that the device
 * password never comes back out, and that nothing existing changed meaning: a
 * save that does not mention a new field leaves it alone.
 */
class EmployeePersonnelFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);
        Tenancy::clear();

        Tenancy::set($this->tenant->id);
        $user = User::withoutGlobalScopes()->create([
            'name' => 'Admin',
            'email' => 'admin@acme.test',
            'password' => Hash::make('test-password'),
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->syncRoles(['tenant-admin']);
        Tenancy::clear();

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'admin@acme.test',
            'password' => 'test-password',
        ])->json('data.token');

        $this->app['auth']->forgetGuards();
    }

    public function test_every_new_field_is_saved_and_returned(): void
    {
        $level = $this->accessLevel('Main door', '24-Hour Accessible');

        $row = $this->send('post', '/api/employees', [
            'name' => 'Ali',
            'employee_code' => '501',
            'last_name' => 'Jaafar',
            'gender' => 'male',
            'mobile_phone' => '+973 3300 0000',
            'hired_date' => '2024-01-15',
            'birthday' => '1990-05-02',
            'device_password' => '1234',
            'privilege' => 'enroller',
            'job_title' => 'Technician',
            'office' => 'Manama',
            'city' => 'Manama',
            'home_address' => 'Road 1, Block 2',
            'access_level_ids' => [$level->id],
            'valid_period_enabled' => true,
            'access_valid_from' => '2026-01-01',
            'access_valid_to' => '2026-12-31',
        ])->assertCreated()->json('data');

        $this->assertSame('Ali', $row['name']); // untouched: last_name is separate
        $this->assertSame('Jaafar', $row['last_name']);
        $this->assertSame('2024-01-15', $row['hired_date']);
        $this->assertSame('enroller', $row['privilege']);
        $this->assertSame('Technician', $row['job_title']);
        $this->assertSame([$level->id], $row['access_level_ids']);
        $this->assertTrue($row['valid_period_enabled']);
        $this->assertSame('2026-12-31', $row['access_valid_to']);
        $this->assertTrue($row['has_device_password']);
    }

    public function test_the_device_password_is_never_returned_and_is_encrypted_at_rest(): void
    {
        $id = $this->send('post', '/api/employees', [
            'name' => 'Ali', 'employee_code' => '501', 'device_password' => '98765432',
        ])->assertCreated()->json('data.id');

        $response = $this->send('get', "/api/employees/{$id}")->assertOk();
        $this->assertStringNotContainsString('98765432', $response->getContent());
        $this->assertArrayNotHasKey('device_password', $response->json('data'));

        $raw = DB::table('employees')->where('id', $id)->value('device_password');
        $this->assertNotSame('98765432', $raw);
        $this->assertSame('98765432', Employee::withoutGlobalScopes()->find($id)->device_password);
    }

    public function test_an_edit_that_does_not_mention_the_password_keeps_it(): void
    {
        $id = $this->send('post', '/api/employees', [
            'name' => 'Ali', 'employee_code' => '501', 'device_password' => '1234',
        ])->json('data.id');

        $this->send('put', "/api/employees/{$id}", ['name' => 'Ali J', 'employee_code' => '501'])->assertOk();
        $this->assertSame('1234', Employee::withoutGlobalScopes()->find($id)->device_password);

        // An explicit null is the way to clear it.
        $this->send('put', "/api/employees/{$id}", ['name' => 'Ali J', 'employee_code' => '501', 'device_password' => null])->assertOk();
        $this->assertNull(Employee::withoutGlobalScopes()->find($id)->device_password);
    }

    public function test_the_device_password_must_be_one_to_eight_digits(): void
    {
        foreach (['abcd', '123456789', '12 34'] as $bad) {
            $this->send('post', '/api/employees', ['name' => 'X', 'employee_code' => '9', 'device_password' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('device_password');
        }

        $this->send('post', '/api/employees', ['name' => 'X', 'employee_code' => '9', 'device_password' => '12345678'])
            ->assertCreated();
    }

    public function test_the_valid_period_must_end_after_it_starts(): void
    {
        $this->send('post', '/api/employees', [
            'name' => 'X', 'employee_code' => '9',
            'valid_period_enabled' => true, 'access_valid_from' => '2026-06-01', 'access_valid_to' => '2026-06-01',
        ])->assertStatus(422)->assertJsonValidationErrors('access_valid_to');

        $this->send('post', '/api/employees', [
            'name' => 'X', 'employee_code' => '9',
            'valid_period_enabled' => true, 'access_valid_from' => '2026-06-01',
        ])->assertStatus(422)->assertJsonValidationErrors('access_valid_to');
    }

    public function test_an_existing_employee_keeps_no_privilege_rather_than_gaining_user(): void
    {
        Tenancy::set($this->tenant->id);
        $employee = Employee::create(['name' => 'Old', 'employee_code' => '7', 'is_active' => true]);
        Tenancy::clear();

        $this->send('put', "/api/employees/{$employee->id}", ['name' => 'Old', 'employee_code' => '7'])->assertOk();

        $this->assertNull($employee->fresh()->privilege);
    }

    public function test_access_levels_are_synced_and_left_alone_when_omitted(): void
    {
        $a = $this->accessLevel('A');
        $b = $this->accessLevel('B');

        $id = $this->send('post', '/api/employees', [
            'name' => 'X', 'employee_code' => '9', 'access_level_ids' => [$a->id, $b->id],
        ])->json('data.id');

        $this->send('put', "/api/employees/{$id}", ['name' => 'X', 'employee_code' => '9'])->assertOk();
        $this->assertSame(2, DB::table('employee_access_levels')->where('employee_id', $id)->count());

        $this->send('put', "/api/employees/{$id}", ['name' => 'X', 'employee_code' => '9', 'access_level_ids' => [$b->id]])
            ->assertOk()->assertJsonPath('data.access_level_ids', [$b->id]);
    }

    public function test_another_companys_access_level_cannot_be_assigned(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        Tenancy::set($other->id);
        $foreign = AccessLevel::create(['name' => 'Their door']);
        Tenancy::clear();

        $this->send('post', '/api/employees', [
            'name' => 'X', 'employee_code' => '9', 'access_level_ids' => [$foreign->id],
        ])->assertStatus(422)->assertJsonValidationErrors('access_level_ids.0');

        $this->send('get', '/api/access-levels')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_access_levels_have_basic_crud(): void
    {
        $id = $this->send('post', '/api/access-levels', ['name' => 'Lobby', 'time_zone' => 'Office hours'])
            ->assertCreated()->json('data.id');
        $this->send('post', '/api/access-levels', ['name' => 'Lobby'])->assertStatus(422);
        $this->send('put', "/api/access-levels/{$id}", ['name' => 'Lobby 2'])->assertOk()->assertJsonPath('data.name', 'Lobby 2');
        $this->send('get', '/api/access-levels')->assertOk()->assertJsonPath('data.0.name', 'Lobby 2');
        $this->send('delete', "/api/access-levels/{$id}")->assertOk();

        $group = $this->send('post', '/api/multi-card-groups', ['name' => 'Vault'])->assertCreated()->json('data.id');
        $this->send('post', '/api/employees', ['name' => 'X', 'employee_code' => '9', 'multi_card_group_id' => $group])
            ->assertCreated()->assertJsonPath('data.multi_card_group_id', $group);
    }

    public function test_a_new_code_must_be_digits_up_to_nine(): void
    {
        foreach (['10-12', '1234567890', 'A7'] as $bad) {
            $this->send('post', '/api/employees', ['name' => 'X', 'employee_code' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('employee_code');
        }
        $this->send('post', '/api/employees', ['name' => 'X', 'employee_code' => '123456789'])->assertCreated();
    }

    public function test_an_existing_non_numeric_code_stays_editable_until_it_is_changed(): void
    {
        // 101 of 356 production codes looked like this on 2026-09-23.
        Tenancy::set($this->tenant->id);
        $employee = Employee::create(['name' => 'Imported', 'employee_code' => '10-12', 'is_active' => true]);
        Tenancy::clear();

        $this->send('put', "/api/employees/{$employee->id}", ['name' => 'Imported OK', 'employee_code' => '10-12'])->assertOk();

        $this->send('put', "/api/employees/{$employee->id}", ['name' => 'Imported OK', 'employee_code' => '10-13'])
            ->assertStatus(422)->assertJsonValidationErrors('employee_code');
    }

    public function test_a_photo_can_be_uploaded_and_removed(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $id = $this->send('post', '/api/employees', ['name' => 'X', 'employee_code' => '9'])->json('data.id');

        $this->app['auth']->forgetGuards();
        $url = $this->post("/api/employees/{$id}/photo", [
            // A real 1x1 PNG: this box has no GD, so fake()->image() cannot run.
            'photo' => \Illuminate\Http\UploadedFile::fake()->createWithContent('me.png', base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            )),
        ], ['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])->assertOk()->json('data.photo_url');
        $this->assertNotNull($url);

        $path = Employee::withoutGlobalScopes()->find($id)->photo_path;
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($path);

        $this->send('delete', "/api/employees/{$id}/photo")->assertOk()->assertJsonPath('data.photo_url', null);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($path);
    }

    public function test_a_national_id_sent_with_the_form_is_ignored(): void
    {
        // Removed from the system on 2026-09-27. An old client that still sends one
        // must neither fail validation nor have it stored or echoed back.
        $res = $this->send('post', '/api/employees', [
            'name' => 'Ali', 'employee_code' => '601', 'national_id' => '880101234',
        ])->assertCreated();

        $this->assertArrayNotHasKey('has_national_id', $res->json('data'));
        $this->assertStringNotContainsString('880101234', $res->getContent());
    }

    private function accessLevel(string $name, ?string $zone = null): AccessLevel
    {
        Tenancy::set($this->tenant->id);
        $level = AccessLevel::create(['name' => $name, 'time_zone' => $zone]);
        Tenancy::clear();

        return $level;
    }

    private function send(string $method, string $uri, array $data = []): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json(strtoupper($method), $uri, $data, ['Authorization' => "Bearer {$this->token}"]);
    }
}
