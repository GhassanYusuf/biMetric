<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** "Must change password at first sign-in" (owner, 2026-11-18). */
class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();
        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($this->tenant);
        Tenancy::set($this->tenant->id);
        $this->admin = User::withoutGlobalScopes()->create([
            'name' => 'Admin', 'email' => 'admin@acme.test', 'password' => 'secret-pass-1',
            'tenant_id' => $this->tenant->id, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $this->admin->syncRoles(['tenant-admin']);
        Tenancy::clear();
    }

    private function signIn(string $email, string $password): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])->assertOk()->json('data.token');
    }

    private function with(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function createHr(bool $mustChange): User
    {
        $this->with($this->signIn('admin@acme.test', 'secret-pass-1'))->postJson('/api/users', [
            'name' => 'GEMS HR', 'email' => 'hr@gems.test', 'password' => 'starting-pass-1', 'role' => 'hr-manager',
            'must_change_password' => $mustChange,
        ])->assertCreated();

        return User::withoutGlobalScopes()->where('email', 'hr@gems.test')->firstOrFail();
    }

    public function test_a_new_user_marked_must_change_can_only_change_their_password(): void
    {
        $hr = $this->createHr(true);
        $this->assertTrue($hr->must_change_password);

        $token = $this->signIn('hr@gems.test', 'starting-pass-1');
        $this->with($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->with($token)->getJson('/api/employees')->assertForbidden()->assertJsonPath('errors.reason', 'password_change_required');
    }

    public function test_changing_it_needs_the_current_password_a_different_one_and_then_unlocks_everything(): void
    {
        $this->createHr(true);
        $token = $this->signIn('hr@gems.test', 'starting-pass-1');
        $other = $this->signIn('hr@gems.test', 'starting-pass-1'); // a second session

        $this->with($token)->putJson('/api/auth/password', ['current_password' => 'wrong', 'password' => 'my-own-pass-9', 'password_confirmation' => 'my-own-pass-9'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->with($token)->putJson('/api/auth/password', ['current_password' => 'starting-pass-1', 'password' => 'starting-pass-1', 'password_confirmation' => 'starting-pass-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->with($token)->putJson('/api/auth/password', ['current_password' => 'starting-pass-1', 'password' => 'my-own-pass-9', 'password_confirmation' => 'my-own-pass-9'])
            ->assertOk()->assertJsonPath('data.must_change_password', false);

        $hr = User::withoutGlobalScopes()->where('email', 'hr@gems.test')->first();
        $this->assertFalse($hr->must_change_password);
        $this->assertTrue(Hash::check('my-own-pass-9', $hr->password));
        $this->with($token)->getJson('/api/employees')->assertOk();
        // The other session was signed out; this one stays.
        $this->assertSame(1, $hr->tokens()->count());
        $this->assertNull(\Laravel\Sanctum\PersonalAccessToken::findToken($other));
    }

    public function test_without_the_option_a_new_user_works_at_once(): void
    {
        $this->createHr(false);
        $this->with($this->signIn('hr@gems.test', 'starting-pass-1'))->getJson('/api/employees')->assertOk();
    }

    public function test_an_administrator_resetting_someones_password_can_require_a_change(): void
    {
        $hr = $this->createHr(false);
        $admin = $this->signIn('admin@acme.test', 'secret-pass-1');

        $this->with($admin)->putJson("/api/users/{$hr->id}/password", ['password' => 'temp-pass-22', 'must_change_password' => true])->assertOk();
        $this->assertTrue($hr->fresh()->must_change_password);

        // Resetting your own password never locks you in.
        $this->with($admin)->putJson("/api/users/{$this->admin->id}/password", ['password' => 'secret-pass-2', 'must_change_password' => true])->assertOk();
        $this->assertFalse((bool) $this->admin->fresh()->must_change_password);
    }
}
