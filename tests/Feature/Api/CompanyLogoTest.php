<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A logo per company (owner, 2026-11-11). */
class CompanyLogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_logo_is_added_replaced_and_removed_and_only_by_a_manager(): void
    {
        Storage::fake('public');
        Tenancy::clear();
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->seed(RolesAndPermissionsSeeder::class);
        RolesAndPermissionsSeeder::seedTenantRoles($tenant);
        Tenancy::set($tenant->id);
        $mk = function (string $email, string $role) use ($tenant) {
            $u = User::withoutGlobalScopes()->create(['name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'tenant_id' => $tenant->id, 'is_active' => true, 'email_verified_at' => now()]);
            $u->syncRoles([$role]);

            return $u;
        };
        $admin = $mk('a@acme.test', 'tenant-admin');
        $operator = $mk('o@acme.test', 'operator');
        $gems = Company::create(['name' => 'GEMS']);
        Tenancy::clear();

        $first = $this->actingAs($admin, 'sanctum')->post("/api/companies/{$gems->id}/logo", ['logo' => self::png('gems.png')], ['Accept' => 'application/json'])->assertOk();
        $path = Company::withoutGlobalScopes()->find($gems->id)->logo_path;
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($first->json('data.logo_url'));

        // Replacing deletes the old file.
        $this->actingAs($admin, 'sanctum')->post("/api/companies/{$gems->id}/logo", ['logo' => self::png('gems2.png')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($path);

        // Not an image, and not a manager.
        $this->actingAs($admin, 'sanctum')->post("/api/companies/{$gems->id}/logo", ['logo' => UploadedFile::fake()->create('evil.svg', 5, 'image/svg+xml')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($operator, 'sanctum')->post("/api/companies/{$gems->id}/logo", ['logo' => self::png('x.png')], ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/companies/{$gems->id}/logo")->assertOk()->assertJsonPath('data.logo_url', null);
        $this->assertNull(Company::withoutGlobalScopes()->find($gems->id)->logo_path);
    }

    /** A real 1x1 PNG (the GD extension, which fake()->image() needs, is not installed here). */
    private static function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    }
}
