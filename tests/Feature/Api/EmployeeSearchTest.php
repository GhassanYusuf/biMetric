<?php

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Searching the roster by the name it shows (2026-10-11): first and last name are
 * separate columns but one name on screen, so every word of the search must match
 * one of them (or the code, department or email).
 */
class EmployeeSearchTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-password';

    private string $email = 'search@acme.test';

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::clear();
        $this->seed(DatabaseSeeder::class);
        Tenancy::clear();

        $acme = Tenant::where('slug', 'acme')->firstOrFail();
        Tenancy::set($acme->id);
        User::create([
            'name' => 'Search Admin',
            'email' => $this->email,
            'password' => Hash::make(self::PASSWORD),
            'tenant_id' => $acme->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ])->syncRoles(['tenant-admin']);

        Employee::create(['name' => 'Zaynab', 'last_name' => 'Qudsi Alwardi', 'employee_code' => 'S-1', 'is_active' => true]);
        Employee::create(['name' => 'Zaynab', 'last_name' => 'Haddad', 'employee_code' => 'S-2', 'is_active' => true]);
        Tenancy::clear();
    }

    private function search(string $term): array
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => $this->email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        $this->app['auth']->forgetGuards();

        return collect($this->withToken($token)->getJson('/api/employees?per_page=100&search='.urlencode($term))->assertOk()->json('data'))
            ->pluck('employee_code')->sort()->values()->all();
    }

    public function test_a_last_name_alone_is_found(): void
    {
        $this->assertSame(['S-1'], $this->search('alwardi'));
    }

    public function test_the_full_name_as_shown_is_found(): void
    {
        $this->assertSame(['S-1'], $this->search('zaynab qudsi'));
        $this->assertSame(['S-1'], $this->search('Zaynab Qudsi Alwardi'));
    }

    public function test_a_first_name_finds_everyone_who_has_it(): void
    {
        $this->assertSame(['S-1', 'S-2'], $this->search('zaynab'));
    }
}
