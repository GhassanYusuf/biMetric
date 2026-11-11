<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/** Website sign-ins: 15 minutes idle, 8 hours at most (owner, 2026-11-11). */
class WebSessionLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::clear();
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($tenant);
        Tenancy::set($tenant->id);
        $this->user = User::withoutGlobalScopes()->create(['name' => 'A', 'email' => 'a@acme.test', 'password' => 'secret-pass-1', 'tenant_id' => $tenant->id, 'is_active' => true, 'email_verified_at' => now()]);
        $this->user->syncRoles(['tenant-admin']);
        Tenancy::clear();
    }

    private function signIn(): string
    {
        return $this->postJson('/api/auth/login', ['email' => 'a@acme.test', 'password' => 'secret-pass-1'])->assertOk()->json('data.token');
    }

    private function me(string $token, array $headers = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->withHeaders($headers)->getJson('/api/auth/me');
    }

    public function test_a_sign_in_expires_eight_hours_after_it_was_made(): void
    {
        $token = $this->signIn();
        $row = PersonalAccessToken::query()->latest('id')->first();
        $this->assertEqualsWithDelta(now()->addHours(8)->timestamp, $row->expires_at->timestamp, 5);

        $this->travel(7)->hours();
        $row->forceFill(['last_active_at' => now()])->save(); // active all along
        $this->me($token)->assertOk();

        $this->travel(61)->minutes();
        $row->forceFill(['last_active_at' => now()])->save();
        $this->me($token)->assertUnauthorized();
    }

    public function test_fifteen_minutes_without_activity_ends_it_and_background_polls_do_not_count(): void
    {
        $token = $this->signIn();

        $this->travel(10)->minutes();
        $this->me($token, ['X-Background' => '1'])->assertOk(); // a live-update poll
        $this->travel(6)->minutes();                              // 16 min since the person did anything
        $this->me($token)->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::query()->count(), 'the idle sign-in is deleted');
    }

    public function test_activity_keeps_it_alive(): void
    {
        $token = $this->signIn();
        foreach ([10, 10, 10] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->me($token)->assertOk();
        }
    }

    public function test_an_old_sign_in_without_an_expiry_is_ended_too(): void
    {
        $legacy = $this->user->createToken('web')->plainTextToken; // as before 2026-11-11: no expiry
        PersonalAccessToken::query()->latest('id')->first()->forceFill(['created_at' => now()->subDays(5), 'last_used_at' => now()])->save();
        $this->me($legacy)->assertUnauthorized();
    }

    public function test_integration_and_postman_tokens_keep_their_own_rules(): void
    {
        $api = $this->user->createToken('postman')->plainTextToken;
        PersonalAccessToken::query()->latest('id')->first()->forceFill(['created_at' => now()->subDays(30), 'last_used_at' => now()->subDays(2)])->save();
        $this->me($api)->assertOk();
    }
}
