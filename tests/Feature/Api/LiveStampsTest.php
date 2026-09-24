<?php

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GET /api/live — the rule 18 fallback carrier. A module's stamp must move when its
 * data changes (a delete included), stay put when nothing changed, and never move
 * because ANOTHER company changed something.
 */
class LiveStampsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();
        $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($this->acme);
        Tenancy::clear();
    }

    private function stamps(): array
    {
        Tenancy::set($this->acme->id);
        if (! User::withoutGlobalScopes()->where('email', 'a@acme.test')->exists()) {
            $u = User::withoutGlobalScopes()->create([
                'name' => 'A', 'email' => 'a@acme.test', 'password' => Hash::make('pw-test-123'),
                'tenant_id' => $this->acme->id, 'is_active' => true, 'email_verified_at' => now(),
            ]);
            $u->syncRoles(['operator']);
        }
        Tenancy::clear();
        $token = $this->postJson('/api/auth/login', ['email' => 'a@acme.test', 'password' => 'pw-test-123'])->json('data.token');
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/live')->assertOk()->json('data');
    }

    private function employee(Tenant $t, string $code): Employee
    {
        Tenancy::set($t->id);
        $e = Employee::create(['tenant_id' => $t->id, 'name' => "E{$code}", 'employee_code' => $code, 'is_active' => true]);
        Tenancy::clear();

        return $e;
    }

    public function test_stamps_move_with_this_company_s_changes_only(): void
    {
        $before = $this->stamps();
        $this->assertSame($before, $this->stamps(), 'nothing changed, nothing moves');

        $this->employee($this->other, '900');
        $this->assertSame($before['employees'], $this->stamps()['employees'], 'another company must not wake this one');

        $e = $this->employee($this->acme, '100');
        $afterCreate = $this->stamps();
        $this->assertNotSame($before['employees'], $afterCreate['employees']);
        $this->assertSame($before['attendance'], $afterCreate['attendance'], 'only the module that changed moves');

        Tenancy::set($this->acme->id);
        Employee::whereKey($e->id)->delete();
        Tenancy::clear();
        $this->assertNotSame($afterCreate['employees'], $this->stamps()['employees'], 'a delete moves the stamp too');
    }
}
