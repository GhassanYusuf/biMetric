<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Company settings (2026-09-24), starting with employees.auto_push_on_save.
 *
 * Under test: it is OFF until an administrator turns it on, only settings.manage can
 * change it, the change is audited, and keys outside the allow-list are ignored.
 */
class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);
        Tenancy::clear();
    }

    private function as(string $role): self
    {
        Tenancy::set($this->tenant->id);
        $user = User::withoutGlobalScopes()->create([
            'name' => $role, 'email' => "{$role}@acme.test", 'password' => Hash::make('pw-test-123'),
            'tenant_id' => $this->tenant->id, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $user->syncRoles([$role]);
        Tenancy::clear();

        $token = $this->postJson('/api/auth/login', ['email' => "{$role}@acme.test", 'password' => 'pw-test-123'])->json('data.token');
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_auto_push_is_off_until_an_administrator_turns_it_on_and_the_change_is_audited(): void
    {
        $api = $this->as('tenant-admin');

        // assertJsonFragment, not assertJsonPath: the key contains a LITERAL dot, so
        // a dotted path reads it as nested object access and matches nothing.
        // Fragment also ignores the rest of the payload, so this test stops failing
        // every time DEFAULTS gains a setting.
        $api->getJson('/api/settings')
            ->assertOk()
            ->assertJsonFragment(['employees.auto_push_on_save' => false]);

        $api->putJson('/api/settings', ['employees' => ['auto_push_on_save' => true], 'something' => ['else' => 1]])
            ->assertOk()
            ->assertJsonFragment(['employees.auto_push_on_save' => true]);

        $this->app['auth']->forgetGuards();
        // The one key, not the whole payload — see the assertJsonFragment note above.
        $data = $this->getJson('/api/settings')->json('data');
        $this->assertTrue($data['employees.auto_push_on_save']);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'setting.updated')->sole();
        $this->assertSame(['key' => 'employees.auto_push_on_save', 'old' => false, 'new' => true], $audit->changes);
        $this->assertDatabaseMissing('settings', ['key' => 'something.else']);
    }

    public function test_only_settings_manage_can_change_it(): void
    {
        $this->as('operator')->putJson('/api/settings', ['employees' => ['auto_push_on_save' => true]])->assertForbidden();
        $this->assertDatabaseCount('settings', 0);
    }
}
